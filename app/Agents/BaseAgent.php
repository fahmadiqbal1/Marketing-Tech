<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Models\AgentStep;
use App\Services\AI\AIRouter;
use App\Services\AI\OpenAIService;
use App\Services\AI\SwarmOrchestratorService;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\ApiCredentialService;
use App\Services\CampaignContextService;
use App\Services\IterationEngineService;
use App\Services\McpToolService;
use App\Services\PromptTemplateService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Knowledge\VectorStoreService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class BaseAgent
{
    use \App\Agents\Concerns\HookableTrait;
    use \App\Agents\Concerns\ValidatesOutput;

    protected string $agentType;
    protected string $provider    = 'openai';
    protected string $model;
    protected int    $maxSteps    = 15;
    protected array  $tools       = [];
    protected string $systemPrompt = '';

    /** Per-agent rate limit (requests per minute). 0 = unlimited. */
    private int $rateLimitPerMinute = 0;

    /** Tracks the current running job ID for AIRouter cost attribution. */
    private ?string $currentJobId = null;
    private ?string $currentWorkflowId = null;

    /** Tool result cache TTL in seconds. 0 = no cache. */
    private int $toolCacheTtl = 0;

    /** Minimum RAG similarity score to include a chunk. */
    private float $ragThreshold = 0.75;

    /** Max tool retries on failure. */
    private int $toolMaxRetries = 2;

    /** Compress history after this many steps to prevent unbounded context growth. */
    private int $maxHistorySteps = 8;

    /** Total context budget in characters (configurable per agent). */
    private int $contextBudgetChars = 8000;

    /** Characters reserved for the LLM response. */
    private int $contextReservedChars = 2000;

    // Not readonly — self-initialised below when subclasses omit them from parent::__construct()
    protected ?PromptTemplateService $promptTemplate = null;
    protected ?McpToolService        $mcpTools       = null;

    public function __construct(
        protected readonly OpenAIService                $openai,
        protected readonly AnthropicService             $anthropic,
        protected readonly GeminiService                $gemini,
        protected readonly TelegramBotService           $telegram,
        protected readonly VectorStoreService           $knowledge,
        protected readonly ApiCredentialService         $credentials,
        protected readonly IterationEngineService       $iterationEngine,
        protected readonly CampaignContextService       $campaignContext,
        protected readonly ?AIRouter                    $aiRouter = null,
        protected readonly ?SwarmOrchestratorService    $swarm = null,
        ?PromptTemplateService                          $promptTemplate = null,
        ?McpToolService                                 $mcpTools = null,
    ) {
        $config = config("agents.agents.{$this->agentType}", []);
        $this->provider              = $config['provider']               ?? $this->provider;
        $this->model                 = $config['model']                  ?? 'gpt-4o';
        $this->maxSteps              = $config['max_steps']              ?? $this->maxSteps;
        $this->systemPrompt          = $config['system_prompt']          ?? $this->systemPrompt;
        $this->tools                 = $config['tools']                  ?? $this->tools;
        $this->rateLimitPerMinute    = $config['rate_limit_per_minute']  ?? 0;
        $this->toolCacheTtl          = $config['tool_cache_ttl']         ?? 0;
        $this->maxHistorySteps       = $config['max_history_steps']      ?? 8;
        $this->contextBudgetChars    = $config['context_budget_chars']   ?? 8000;
        $this->contextReservedChars  = $config['context_reserved_chars'] ?? 2000;
        $this->ragThreshold          = (float) config('agents.knowledge.similarity_threshold', 0.75);

        // Ensure these are always available regardless of whether subclass threads them through.
        // Both services have zero constructor dependencies so direct instantiation is safe.
        $this->promptTemplate ??= $promptTemplate ?? new PromptTemplateService();
        $this->mcpTools       ??= $mcpTools ?? new McpToolService();
    }

    /**
     * Categories to scope RAG searches for this agent.
     * Override in subclasses to restrict or broaden the search.
     * Returns [] (empty) to search all categories.
     */
    protected function getRagCategories(): array
    {
        return array_filter([$this->agentType, 'general', 'agent-skills']);
    }

    /**
     * Execute the agent loop for a given job.
     */
    final public function run(AgentJob $job): void
    {
        $traceId = $job->id;

        // ── Provider / model overrides from job ───────────────────────
        if (! empty($job->ai_provider)) {
            $this->provider = $job->ai_provider;
        }
        if (! empty($job->model)) {
            $this->model = $job->model;
        }

        // ── Custom system prompt override ─────────────────────────────
        $promptKey    = 'AGENT_' . strtoupper($this->agentType) . '_PROMPT';
        $customPrompt = $this->credentials->retrieve($promptKey);
        if (! empty($customPrompt)) {
            $this->systemPrompt = $customPrompt;
        }

        // ── Dynamic prompt template rendering ─────────────────────────
        if ($this->promptTemplate !== null) {
            $business = $job->business ?? null;
            $this->systemPrompt = $this->promptTemplate->render($this->systemPrompt, [
                'date'          => now()->toDateString(),
                'business_name' => $business?->name ?? 'your organisation',
                'agent_type'    => $this->agentType,
            ]);
        }

        $this->currentJobId      = $job->id;
        $this->currentWorkflowId = $job->workflow_id ?? null;

        $job->update(['status' => 'running', 'started_at' => now()]);
        $this->notifyUser($job, "⚡ *{$this->agentType}* agent started...");

        $this->fireHook('before_run', $job);

        [$messages, $knowledgeScores] = $this->buildInitialMessages($job);
        $stepCount   = 0;
        $totalTokens = 0;
        $result      = null;

        try {
            while ($stepCount < $this->maxSteps) {
                // ── Pause / cancel check ───────────────────────────────
                $job->refresh();
                if (in_array($job->status, ['paused', 'cancelled'])) {
                    Log::info('Agent loop halted', [
                        'trace_id' => $traceId,
                        'status'   => $job->status,
                        'agent'    => $this->agentType,
                    ]);
                    return;
                }

                // ── Rate limiting ──────────────────────────────────────
                if ($this->rateLimitPerMinute > 0) {
                    $this->enforceRateLimit($traceId);
                }

                // ── History compression — prevent unbounded token growth ──
                if ($stepCount > 0 && $stepCount % $this->maxHistorySteps === 0) {
                    $this->compressHistory($messages, $traceId);
                }

                $stepCount++;

                Log::debug('Agent step', [
                    'trace_id' => $traceId,
                    'step'     => $stepCount,
                    'agent'    => $this->agentType,
                    'provider' => $this->provider,
                    'model'    => $this->model,
                ]);

                // ── Call AI model ─────────────────────────────────────
                $aiStart    = (int) round(microtime(true) * 1000);
                $response   = $this->callAI($messages, $this->getAllToolDefinitions());
                $aiLatency  = (int) round(microtime(true) * 1000) - $aiStart;
                $tokensUsed = $this->extractTokensUsed($response);
                $totalTokens += $tokensUsed;

                $thought   = $this->extractThought($response);
                $toolCalls = $this->extractToolCalls($response);

                if (empty($toolCalls)) {
                    $result = $this->extractText($response);

                    $this->recordStep(
                        job:             $job,
                        stepNumber:      $stepCount,
                        action:          'finish',
                        thought:         $thought,
                        parameters:      [],
                        result:          $result,
                        tokensUsed:      $tokensUsed,
                        latencyMs:       $aiLatency,
                        knowledgeScores: $knowledgeScores,
                        fromCache:       false,
                        toolSuccess:     true,
                        toolError:       null,
                    );
                    break;
                }

                $messages[]  = $this->buildAssistantMessage($response);
                $toolResults = [];

                foreach ($toolCalls as $toolCall) {
                    $toolStart = (int) round(microtime(true) * 1000);

                    // ── Tool result cache ─────────────────────────────
                    $fromCache    = false;
                    $cacheKey     = null;
                    $toolResult   = null;
                    $toolSuccess  = true;
                    $toolError    = null;

                    $isCacheable = $this->toolCacheTtl > 0
                        && ! $this->isTimeSensitiveTool($toolCall['name']);

                    if ($isCacheable) {
                        $cacheKey   = 'agent_tool:' . md5($toolCall['name'] . json_encode($toolCall['arguments']));
                        $toolResult = Cache::get($cacheKey);
                        if ($toolResult !== null) {
                            $fromCache = true;
                            Log::debug('Tool result served from cache', [
                                'trace_id' => $traceId,
                                'tool'     => $toolCall['name'],
                            ]);
                        }
                    }

                    if ($toolResult === null) {
                        // ── Tool reliability: retry + fallback ────────
                        [$toolResult, $toolSuccess, $toolError] = $this->executeToolWithReliability(
                            name: $toolCall['name'],
                            args: $toolCall['arguments'],
                            job:  $job,
                        );

                        if ($isCacheable && $toolSuccess && $cacheKey !== null) {
                            Cache::put($cacheKey, $toolResult, $this->toolCacheTtl);
                        }
                    }

                    $toolLatency = (int) round(microtime(true) * 1000) - $toolStart;
                    $resultStr   = is_string($toolResult) ? $toolResult : json_encode($toolResult);

                    $toolResults[] = [
                        'tool_call_id' => $toolCall['id'],
                        'name'         => $toolCall['name'],
                        'content'      => $resultStr,
                    ];

                    $this->recordStep(
                        job:             $job,
                        stepNumber:      $stepCount,
                        action:          $toolCall['name'],
                        thought:         $thought,
                        parameters:      $toolCall['arguments'],
                        result:          $resultStr,
                        tokensUsed:      $tokensUsed,
                        latencyMs:       $aiLatency + $toolLatency,
                        knowledgeScores: $knowledgeScores,
                        fromCache:       $fromCache,
                        toolSuccess:     $toolSuccess,
                        toolError:       $toolError,
                    );

                    Log::info('Tool executed', [
                        'trace_id'    => $traceId,
                        'tool'        => $toolCall['name'],
                        'success'     => $toolSuccess,
                        'from_cache'  => $fromCache,
                        'latency_ms'  => $toolLatency,
                    ]);

                    // Knowledge scores only relevant on first step
                    $knowledgeScores = [];
                }

                $messages[] = $this->buildToolResultMessage($toolResults);

                $job->update([
                    'steps_taken' => $stepCount,
                    'last_tool'   => $toolCalls[0]['name'] ?? null,
                ]);
            }

            if ($stepCount >= $this->maxSteps && $result === null) {
                $result = "Agent reached maximum steps ({$this->maxSteps}). Partial work may have been completed.";
                Log::warning('Agent hit max steps', ['trace_id' => $traceId, 'agent' => $this->agentType]);
                $this->fireHook('after_run', $job, 'EXHAUSTED');
                \Illuminate\Support\Facades\DB::table('agent_dead_letters')->insert([
                    'agent_job_id' => $job->id,
                    'agent_type'   => $this->agentType,
                    'reason'       => 'max_steps_exhausted',
                    'last_step'    => $stepCount,
                    'created_at'   => now(),
                ]);
            }

            $job->update([
                'status'       => 'completed',
                'result'       => $result,
                'steps_taken'  => $stepCount,
                'total_tokens' => $totalTokens,
                'completed_at' => now(),
            ]);

            // ── Store final output ─────────────────────────────────────
            if ($result !== null) {
                $outputType = $this->inferOutputType();
                try {
                    // Link to any prior output from a previous attempt (version lineage)
                    $parentOutputId = \App\Models\GeneratedOutput::where('agent_job_id', $job->id)
                        ->orderByDesc('created_at')
                        ->value('id');

                    // Link to any existing variation for this job (earliest wins)
                    $contentVariationId = \App\Models\ContentVariation::where('agent_job_id', $job->id)
                        ->orderBy('created_at')
                        ->value('id');

                    if ($contentVariationId) {
                        $this->iterationEngine->storeOutput(
                            agentJobId:           $job->id,
                            content:              $result,
                            type:                 $outputType,
                            metadata:             ['agent_type' => $this->agentType, 'steps' => $stepCount],
                            parentOutputId:       $parentOutputId,
                            contentVariationId:   $contentVariationId,
                        );
                    } else {
                        // No variation to link — ContentAgent already stored outputs atomically.
                        // Other agent types don't produce GeneratedOutput entries.
                        Log::debug('BaseAgent: skipping storeOutput — no variation found for job', [
                            'job_id'     => $job->id,
                            'agent_type' => $this->agentType,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to store generated output', ['trace_id' => $traceId, 'error' => $e->getMessage()]);
                }
            }

            // ── Bust campaign context cache ────────────────────────────
            if ($job->campaign_id) {
                $this->campaignContext->bustCache($job->campaign_id);
            }

            Log::info('Agent completed', [
                'trace_id'     => $traceId,
                'agent'        => $this->agentType,
                'steps'        => $stepCount,
                'total_tokens' => $totalTokens,
            ]);

            if ($result !== null) {
                $result = $this->assertOutput($result);
            }

            $this->fireHook('after_run', $job, $result);
            $this->notifyUser($job, "✅ *{$this->agentType}* completed:\n\n{$result}");

            // ── Persist cross-job learnings to knowledge base ──────────
            $this->persistSessionLearnings($job, $result ?? '', $stepCount);

        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();

            Log::error('Agent failed', [
                'trace_id' => $traceId,
                'agent'    => $this->agentType,
                'error'    => $errorMsg,
                'trace'    => $e->getTraceAsString(),
            ]);

            $job->update([
                'status'        => 'failed',
                'error_message' => $errorMsg,
                'total_tokens'  => $totalTokens,
                'completed_at'  => now(),
            ]);

            $this->notifyUser($job, "❌ *{$this->agentType}* failed: " . Str::limit($errorMsg, 200));

            throw $e;
        }
    }

    /**
     * Execute a named tool. Subclasses implement specific tools here.
     * BaseAgent intercepts the 'mcp_tool' universal tool before delegating.
     */
    abstract protected function executeTool(string $name, array $args, AgentJob $job): mixed;

    /**
     * Return tool definitions in OpenAI function-calling format.
     * Subclasses implement agent-specific tools; base appends the universal mcp_tool.
     */
    abstract protected function getToolDefinitions(): array;

    /**
     * Merged tool definitions: agent-specific + universal base tools.
     */
    final protected function getAllToolDefinitions(): array
    {
        return array_merge($this->getToolDefinitions(), $this->baseToolDefinitions());
    }

    /**
     * Universal tools available to every agent (MCP execution, etc.).
     */
    private function baseToolDefinitions(): array
    {
        if ($this->mcpTools === null) {
            return [];
        }

        return [[
            'type'     => 'function',
            'function' => [
                'name'        => 'mcp_tool',
                'description' => 'Execute a tool on a registered MCP server. Use when you need capabilities not available in your standard tool set.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'server_name' => ['type' => 'string', 'description' => 'Name of the registered MCP server'],
                        'tool_name'   => ['type' => 'string', 'description' => 'Name of the tool to execute on the server'],
                        'params'      => ['type' => 'object', 'description' => 'Tool-specific parameters', 'additionalProperties' => true],
                    ],
                    'required'   => ['server_name', 'tool_name'],
                ],
            ],
        ]];
    }

    /**
     * Route the 'mcp_tool' universal call; all other names delegate to executeTool().
     */
    final protected function dispatchTool(string $name, array $args, AgentJob $job): mixed
    {
        if ($name === 'mcp_tool' && $this->mcpTools !== null) {
            return $this->mcpTools->execute(
                $args['server_name'] ?? '',
                $args['tool_name']   ?? '',
                $args['params']      ?? [],
            );
        }

        return $this->executeTool($name, $args, $job);
    }

    /**
     * Override in subclasses to declare expected result shapes per tool name.
     * Keys are tool names; values are arrays of required keys (for array results).
     */
    protected function getToolResultSchemas(): array
    {
        return [];
    }

    /**
     * Validate tool result against declared schemas. Throws \UnexpectedValueException
     * so executeToolWithReliability's retry logic catches it naturally.
     */
    protected function validateToolResult(string $name, mixed $result): void
    {
        $schemas = $this->getToolResultSchemas();
        if (! isset($schemas[$name])) {
            return;
        }

        $requiredKeys = $schemas[$name];
        if (! is_array($result)) {
            throw new \UnexpectedValueException("Tool {$name} must return an array, got " . gettype($result));
        }

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $result)) {
                throw new \UnexpectedValueException("Tool {$name} result missing required key: {$key}");
            }
        }
    }

    // ─── Tool Reliability ─────────────────────────────────────────

    /**
     * Execute a tool with retry logic and fallback.
     * Returns [result, success, error_message].
     */
    private function executeToolWithReliability(string $name, array $args, AgentJob $job): array
    {
        // Circuit breaker check — fail fast if tool is temporarily blocked
        if ($this->iterationEngine->isToolBlocked($name)) {
            Log::warning('BaseAgent: tool blocked by circuit breaker, skipping', [
                'tool'       => $name,
                'job_id'     => $job->id,
                'agent_type' => $this->agentType,
            ]);
            return [$this->toolResult(false, null, "Tool {$name} is temporarily disabled due to repeated failures."), false, 'circuit_breaker'];
        }

        $attempt   = 0;
        $lastError = null;

        // Check historical reliability; double backoff for unreliable tools
        $reliability  = $this->iterationEngine->getToolReliability($name);
        $extraBackoff = ($reliability > 0.0 && $reliability < 0.6);

        if ($extraBackoff) {
            Log::warning('Low-reliability tool selected', [
                'tool'        => $name,
                'reliability' => $reliability,
                'job_id'      => $job->id,
            ]);
        }

        while ($attempt <= $this->toolMaxRetries) {
            $attempt++;
            try {
                $this->fireHook('before_tool', $name, $args);
                $result = $this->dispatchTool($name, $args, $job);

                // Basic validation: result must be non-null and non-empty
                if ($result === null || $result === '' || $result === []) {
                    throw new \RuntimeException("Tool {$name} returned empty result");
                }

                // Structural validation: subclasses may define expected result shapes
                $this->validateToolResult($name, $result);

                $this->fireHook('after_tool', $name, $result);
                $this->iterationEngine->recordToolOutcome($name, true);
                return [$result, true, null];

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->iterationEngine->recordToolOutcome($name, false);
                Log::warning('Tool execution failed, retrying', [
                    'tool'    => $name,
                    'attempt' => $attempt,
                    'error'   => $lastError,
                    'job_id'  => $job->id,
                ]);

                if ($attempt <= $this->toolMaxRetries) {
                    $backoffUs = 500_000 * $attempt; // 0.5s, 1s base backoff
                    if ($extraBackoff) {
                        $backoffUs *= 2; // Double for unreliable tools
                    }
                    usleep($backoffUs);
                }
            }
        }

        // All retries exhausted — return fallback
        $fallback = $this->toolResult(false, null, "Tool {$name} failed after {$this->toolMaxRetries} retries: {$lastError}");
        return [$fallback, false, $lastError];
    }

    /**
     * Time-sensitive tools bypass cache (live data only).
     */
    private function isTimeSensitiveTool(string $toolName): bool
    {
        $timeSensitive = ['get_campaign_stats', 'get_metrics', 'get_experiment_results',
                          'analyze_funnel', 'get_media_info', 'search_candidates'];
        return in_array($toolName, $timeSensitive);
    }

    // ─── AI Provider Abstraction ──────────────────────────────────

    protected function callAI(array $messages, array $tools = []): array
    {
        // Swarm mode: fan-out to all providers + consensus synthesis (non-Gemini only)
        if ($this->provider !== 'gemini' && $this->swarm?->isEnabled()) {
            return $this->swarm->run(
                messages:    $messages,
                system:      $this->systemPrompt,
                tools:       $tools,
                agentRunId:  $this->currentJobId,
            );
        }

        // Gemini stays direct (AIRouter doesn't wrap Gemini yet)
        if ($this->provider === 'gemini') {
            return $this->gemini->chat(
                messages:     $messages,
                model:        $this->model,
                systemPrompt: $this->systemPrompt,
                tools:        $tools,
                maxTokens:    config('agents.gemini.max_tokens', 4096),
            );
        }

        // Route through AIRouter when available — enables custom platforms, cost tracking, and fallbacks
        if ($this->aiRouter !== null) {
            return $this->aiRouter->chat(
                messages:    $messages,
                model:       $this->model,
                maxTokens:   config("agents.{$this->provider}.max_tokens", 4096),
                temperature: 0.7,
                system:      $this->systemPrompt,
                tools:       $tools,
                provider:    $this->provider,
                agentRunId:  $this->currentJobId,
                workflowId:  $this->currentWorkflowId,
            );
        }

        // Legacy direct calls (fallback for agents not yet injecting AIRouter)
        return match ($this->provider) {
            'anthropic' => $this->anthropic->chat(
                messages:     $messages,
                model:        $this->model,
                systemPrompt: $this->systemPrompt,
                tools:        $this->convertToolsForAnthropic($tools),
                maxTokens:    config('agents.anthropic.max_tokens', 8192),
            ),
            default => $this->openai->chat(
                messages:     $messages,
                model:        $this->model,
                systemPrompt: $this->systemPrompt,
                tools:        $tools,
                maxTokens:    config('agents.openai.max_tokens', 4096),
            ),
        };
    }

    // ─── Message Building ─────────────────────────────────────────

    /**
     * Per-section character limits as fractions of the total budget.
     * Applied before the global budget guard.
     */
    private function sectionLimits(): array
    {
        $budget = $this->contextBudgetChars - $this->contextReservedChars;
        return [
            'rag'      => (int) ($budget * 0.35),
            'patterns' => (int) ($budget * 0.20),
            'global'   => (int) ($budget * 0.12),
            'campaign' => (int) ($budget * 0.20),
            'failures' => (int) ($budget * 0.13),
        ];
    }

    /** Running total of injected context chars for this request. */
    private int $promptUsed = 0;

    /**
     * Trim a section to its per-section limit, then check against the global
     * budget. Returns trimmed text, or empty string if budget exhausted.
     */
    private function addSection(string $section, string $text): string
    {
        $limits       = $this->sectionLimits();
        $sectionMax   = $limits[$section] ?? (int) (($this->contextBudgetChars - $this->contextReservedChars) * 0.20);
        $trimmed      = mb_substr($text, 0, $sectionMax, 'UTF-8');
        $usableBudget = $this->contextBudgetChars - $this->contextReservedChars;

        if (($this->promptUsed + mb_strlen($trimmed, 'UTF-8')) > $usableBudget) {
            return '';
        }

        $this->promptUsed += mb_strlen($trimmed, 'UTF-8');
        return $trimmed;
    }

    /**
     * Returns [messages array, knowledge_scores array [{id, score}]].
     *
     * Sections are added in priority order with per-section caps plus a global
     * usable budget of 6000 chars (8000 total minus 2000 reserved for response).
     */
    private function buildInitialMessages(AgentJob $job): array
    {
        $this->promptUsed = 0; // reset budget for this run
        $messages         = [];
        $knowledgeScores  = [];

        // ── 1. RAG context (filtered by similarity threshold, deduplicated) ──
        $rawContext = $this->knowledge->search($job->instruction, topK: 5, categories: $this->getRagCategories());
        $seen       = [];
        $usedChunks = [];

        foreach ($rawContext as $item) {
            $similarity = $item['similarity'];

            // PageIndex returns null similarity (reasoning-based, not scored).
            // Only apply the threshold filter for legacy vector results (non-null).
            if ($similarity !== null && (float) $similarity < $this->ragThreshold) continue;

            $hash = md5(mb_substr($item['content'] ?? '', 0, 200, 'UTF-8'));
            if (isset($seen[$hash])) continue;
            $seen[$hash] = true;

            $usedChunks[] = $item;
            if (! empty($item['id'])) {
                $knowledgeScores[] = ['id' => $item['id'], 'score' => $similarity !== null ? round((float) $similarity, 4) : 1.0];
            }
        }

        if (! empty($usedChunks)) {
            $contextText = "[KNOWLEDGE CONTEXT — ranked by relevance]\n\n";
            foreach ($usedChunks as $i => $item) {
                $num        = $i + 1;
                $title      = $item['title']    ?? 'Unknown';
                $category   = $item['category'] ?? 'general';
                $score      = $item['similarity'] !== null
                    ? 'Score: ' . round((float) $item['similarity'], 2)
                    : 'Relevance: HIGH';
                $sanitized  = $this->iterationEngine->sanitizeForPrompt($item['content'] ?? '');
                $contextText .= "{$num}. [Source: {$title} | Category: {$category} | {$score}]\n   {$sanitized}\n\n";
            }
            $trimmed = $this->addSection('rag', $contextText);
            if (! empty($trimmed)) {
                $messages[] = ['role' => 'user',      'content' => $trimmed];
                $messages[] = ['role' => 'assistant',  'content' => 'I have reviewed the relevant knowledge context and will use it to inform my response.'];
            }
        }

        // ── 2. Iteration engine: inject high-performing patterns (per-agent) ──
        $patterns = $this->iterationEngine->getPromptContext($this->agentType);
        if (! empty($patterns)) {
            $trimmed = $this->addSection('patterns', $patterns);
            if (! empty($trimmed)) {
                $messages[] = ['role' => 'user',      'content' => $trimmed];
                $messages[] = ['role' => 'assistant',  'content' => 'I will apply these proven high-performing patterns to maximise effectiveness.'];
            }
        }

        // ── 3. Global cross-agent patterns ────────────────────────────────
        $globalPatterns = $this->iterationEngine->getGlobalPatterns();
        if (! empty($globalPatterns)) {
            $trimmed = $this->addSection('global', $globalPatterns);
            if (! empty($trimmed)) {
                $messages[] = ['role' => 'user',      'content' => $trimmed];
                $messages[] = ['role' => 'assistant',  'content' => 'I have noted these global high-performing patterns and will incorporate them.'];
            }
        }

        // ── 4. Campaign context: inject prior campaign work ───────────────
        if (! empty($job->campaign_id)) {
            $rawCampaignCtx = $this->campaignContext->getCampaignContext($job->campaign_id);
            if (! empty($rawCampaignCtx)) {
                $campaignCtx = $this->iterationEngine->sanitizeForPrompt($rawCampaignCtx);
                $trimmed     = $this->addSection('campaign', $campaignCtx);
                if (! empty($trimmed)) {
                    $messages[] = ['role' => 'user',      'content' => $trimmed];
                    $messages[] = ['role' => 'assistant',  'content' => 'I have reviewed the campaign history and will ensure continuity with prior work.'];
                }
            }
        }

        // ── 5. Failure context (retry awareness — max 3 most recent failures) ──
        $failedSteps = \App\Models\AgentStep::where('agent_job_id', $job->id)
            ->where(function ($q) {
                $q->where('tool_success', false)->orWhere('status', 'failed');
            })
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        if ($failedSteps->isNotEmpty()) {
            $failureLines = [];
            foreach ($failedSteps as $step) {
                $rawError = $step->tool_error ?? $step->thought ?? 'Unknown error';

                // Strip file paths and Laravel exception class names to reduce noise
                $cleaned = preg_replace('/\/[a-zA-Z0-9_.\/]+\.[a-zA-Z]+/', '[path]', $rawError);
                $cleaned = preg_replace('/[A-Z][a-zA-Z]+Exception/', '[Exception]', $cleaned ?? $rawError);
                $cleaned = preg_replace('/App\\\\[A-Za-z\\\\]+/', '[Class]', $cleaned ?? $rawError);
                $cleaned = trim($cleaned ?? $rawError);

                // Summarize with actionable hint instead of raw dump
                $reason = mb_substr($cleaned, 0, 80, 'UTF-8');
                $hint   = match (true) {
                    str_contains(strtolower($reason), 'timeout')    => 'Retry with smaller input or fallback.',
                    str_contains(strtolower($reason), 'rate limit') => 'Wait before retrying.',
                    str_contains(strtolower($reason), '404')        => 'Resource not found, verify input.',
                    default                                          => 'Review input and retry.',
                };
                $failureLines[] = "- {$step->action} failed ({$reason}). {$hint}";
            }
            $failureContext = "[PREVIOUS ATTEMPT FAILURES — avoid repeating these mistakes]\n"
                . implode("\n", $failureLines);
            $trimmed = $this->addSection('failures', $failureContext);
            if (! empty($trimmed)) {
                $messages[] = ['role' => 'user',      'content' => $trimmed];
                $messages[] = ['role' => 'assistant',  'content' => 'I understand these previous failures and will avoid repeating the same mistakes.'];
            }
        }

        // ── 6. The actual instruction ─────────────────────────────────────
        $messages[] = ['role' => 'user', 'content' => $job->instruction];

        return [$messages, $knowledgeScores];
    }

    private function buildAssistantMessage(array $response): array
    {
        if ($this->provider === 'anthropic') {
            return ['role' => 'assistant', 'content' => $response['content']];
        }

        return [
            'role'       => 'assistant',
            'content'    => $response['choices'][0]['message']['content'] ?? null,
            'tool_calls' => $response['choices'][0]['message']['tool_calls'] ?? [],
        ];
    }

    private function buildToolResultMessage(array $toolResults): array
    {
        if ($this->provider === 'anthropic') {
            $content = array_map(fn($r) => [
                'type'        => 'tool_result',
                'tool_use_id' => $r['tool_call_id'],
                'content'     => $r['content'],
            ], $toolResults);

            return ['role' => 'user', 'content' => $content];
        }

        return [
            'role'    => 'tool',
            'content' => json_encode($toolResults),
        ];
    }

    // ─── Response Parsing ─────────────────────────────────────────

    private function extractToolCalls(array $response): array
    {
        if ($this->provider === 'anthropic') {
            $toolUses = [];
            foreach ($response['content'] ?? [] as $block) {
                if ($block['type'] === 'tool_use') {
                    $toolUses[] = [
                        'id'        => $block['id'],
                        'name'      => $block['name'],
                        'arguments' => $block['input'],
                    ];
                }
            }
            return $toolUses;
        }

        $toolCalls = $response['choices'][0]['message']['tool_calls'] ?? [];
        return array_map(fn($tc) => [
            'id'        => $tc['id'],
            'name'      => $tc['function']['name'],
            'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
        ], $toolCalls);
    }

    private function extractText(array $response): string
    {
        if ($this->provider === 'anthropic') {
            foreach ($response['content'] ?? [] as $block) {
                if ($block['type'] === 'text') {
                    return $block['text'];
                }
            }
            return '';
        }

        return $response['choices'][0]['message']['content'] ?? '';
    }

    private function extractThought(array $response): ?string
    {
        if ($this->provider === 'anthropic') {
            foreach ($response['content'] ?? [] as $block) {
                if ($block['type'] === 'text' && ! empty($block['text'])) {
                    return Str::limit($block['text'], 500);
                }
            }
        }
        return null;
    }

    private function convertToolsForAnthropic(array $openAiTools): array
    {
        return array_map(fn($tool) => [
            'name'         => $tool['function']['name'],
            'description'  => $tool['function']['description'],
            'input_schema' => $tool['function']['parameters'],
        ], $openAiTools);
    }

    // ─── History Management ───────────────────────────────────────

    /**
     * Compress the oldest history pairs to prevent unbounded context growth.
     *
     * Replaces the oldest 4 assistant+tool message pairs with a single
     * [COMPRESSED HISTORY] summary, keeping the initial context messages intact.
     */
    private function compressHistory(array &$messages, string $traceId): void
    {
        // Keep first N messages (initial context injections) + last pair in place.
        // Only compress middle history pairs.
        $initialCount = 0;
        foreach ($messages as $msg) {
            if (isset($msg['role']) && in_array($msg['role'], ['user', 'assistant'])) {
                // Count the initial context setup messages (before any tool calls)
                $initialCount++;
            }
            if ($initialCount >= 6) break; // stop after assumed initial block
        }

        $compressible = array_slice($messages, $initialCount, -2); // preserve last pair
        if (count($compressible) < 4) {
            return; // not enough history to compress
        }

        $pairsToCompress = array_slice($compressible, 0, 4);
        $summaryText = '';
        foreach ($pairsToCompress as $m) {
            $content = is_array($m['content'])
                ? json_encode($m['content'])
                : ($m['content'] ?? '');
            $summaryText .= "[{$m['role']}]: " . mb_substr((string) $content, 0, 300, 'UTF-8') . "\n";
        }

        try {
            $prompt = "Summarise these agent interaction steps into one concise paragraph (max 150 words) preserving key decisions and results:\n\n{$summaryText}";
            $summary = $this->aiRouter
                ? $this->aiRouter->complete($prompt, 'gpt-4o-mini', 200, 0.0)
                : mb_substr($summaryText, 0, 400, 'UTF-8');

            $summaryMessage = ['role' => 'user', 'content' => "[COMPRESSED HISTORY]\n{$summary}"];
            $ackMessage     = ['role' => 'assistant', 'content' => 'Understood — I have the prior context summary.'];

            // Replace the first 4 compressible messages with the summary pair
            array_splice($messages, $initialCount, 4, [$summaryMessage, $ackMessage]);

            Log::debug('BaseAgent: history compressed', [
                'trace_id'       => $traceId,
                'agent'          => $this->agentType,
                'messages_after' => count($messages),
            ]);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: history compression failed — continuing without compression', [
                'trace_id' => $traceId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // ─── Cross-job Memory ─────────────────────────────────────────

    /**
     * Extract and persist key learnings from this job into the shared knowledge base.
     * These feed back into RAG context for future agent runs via the 'agent-skills' category.
     */
    private function persistSessionLearnings(AgentJob $job, string $result, int $steps): void
    {
        if (empty($result) || $steps < 2) {
            return; // Not enough signal to learn from
        }

        try {
            $prompt = <<<PROMPT
You are summarising the key learnings from a completed {$this->agentType} agent job.
Job instruction: {$job->instruction}
Job result (excerpt): {excerpt}
Steps taken: {$steps}

Extract exactly 3 concise, reusable learnings that would help future {$this->agentType} agents do better work.
Format as a JSON array: ["learning 1", "learning 2", "learning 3"]
Each learning must be a single sentence under 100 characters.
Respond with ONLY the JSON array.
PROMPT;
            $excerpt = mb_substr($result, 0, 500, 'UTF-8');
            $prompt  = str_replace('{excerpt}', $excerpt, $prompt);

            $raw = $this->aiRouter
                ? $this->aiRouter->complete($prompt, 'gpt-4o-mini', 250, 0.0)
                : null;

            if (empty($raw)) return;

            $clean    = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', trim($raw))));
            $learnings = json_decode($clean, true);

            if (! is_array($learnings) || empty($learnings)) return;

            $content = implode("\n", array_map(fn($l, $i) => ($i + 1) . ". {$l}", $learnings, array_keys($learnings)));
            $this->knowledge->store(
                title:    "Agent Learning: {$this->agentType} — " . now()->toDateString(),
                content:  $content,
                tags:     [$this->agentType, 'auto-learned', "job:{$job->id}"],
                category: 'agent-skills',
                source:   "agent_job:{$job->id}",
            );

            Log::debug('BaseAgent: session learnings persisted', [
                'job_id'     => $job->id,
                'agent_type' => $this->agentType,
                'learnings'  => count($learnings),
            ]);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to persist session learnings', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function recordStep(
        AgentJob $job,
        int      $stepNumber,
        string   $action,
        ?string  $thought,
        array    $parameters,
        mixed    $result,
        int      $tokensUsed,
        int      $latencyMs,
        array    $knowledgeScores = [],
        bool     $fromCache = false,
        ?bool    $toolSuccess = null,
        ?string  $toolError = null,
    ): void {
        try {
            $chunkIds = array_column($knowledgeScores, 'id');

            AgentStep::create([
                'task_id'              => null,
                'agent_job_id'         => $job->id,
                'step_number'          => $stepNumber,
                'agent_name'           => $this->agentType,
                'action'               => $action,
                'thought'              => $thought,
                'parameters'           => $parameters,
                'result'               => is_string($result) ? ['output' => $result] : (array) $result,
                'knowledge_chunks_used'=> empty($chunkIds)       ? null : $chunkIds,
                'knowledge_scores'     => empty($knowledgeScores) ? null : $knowledgeScores,
                'from_cache'           => $fromCache,
                'tool_success'         => $toolSuccess,
                'tool_error'           => $toolError,
                'status'               => ($toolSuccess === false) ? 'failed' : 'completed',
                'tokens_used'          => $tokensUsed,
                'latency_ms'           => $latencyMs,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record agent step', [
                'trace_id' => $job->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function extractTokensUsed(array $response): int
    {
        if (isset($response['usage']['input_tokens'])) {
            return ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);
        }
        return $response['usage']['total_tokens'] ?? 0;
    }

    private function enforceRateLimit(string $traceId): void
    {
        $key     = "agent_rate:{$this->agentType}:" . date('YmdHi');
        $current = (int) Cache::get($key, 0);

        if ($current >= $this->rateLimitPerMinute) {
            $retryAfter = max(1, 60 - (int) date('s'));
            Log::warning('Agent rate limit reached, releasing job', [
                'trace_id'    => $traceId,
                'agent'       => $this->agentType,
                'retry_after' => $retryAfter,
            ]);
            throw new \App\Exceptions\RateLimitException($retryAfter, $this->agentType);
        }

        Cache::put($key, $current + 1, 120);
    }

    /**
     * Infer the output type from the agent type for GeneratedOutput storage.
     */
    private function inferOutputType(): string
    {
        return match ($this->agentType) {
            'content'   => 'content',
            'marketing' => 'campaign',
            'growth'    => 'report',
            'hiring'    => 'analysis',
            'media'     => 'creative',
            'knowledge' => 'strategy',
            default     => 'content',
        };
    }

    protected function notifyUser(AgentJob $job, string $message): void
    {
        if ($job->chat_id) {
            try {
                $this->telegram->sendMessage($job->chat_id, $message);
            } catch (\Throwable $e) {
                Log::warning('Failed to notify user', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Format tool result as JSON string for AI context.
     */
    protected function toolResult(bool $success, mixed $data, ?string $error = null): string
    {
        return json_encode([
            'success' => $success,
            'data'    => $data,
            'error'   => $error,
        ]);
    }
}
