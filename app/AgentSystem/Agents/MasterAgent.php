<?php

namespace App\AgentSystem\Agents;

use App\AgentSystem\Gateway\AIGateway;
use App\AgentSystem\Tools\ToolRegistry;
use App\Models\AgentStep;
use App\Models\AgentTask;
use App\Services\MemoryService;
use App\Services\Marketing\ProductMarketingContextService;
use App\Services\Marketing\PerformanceService;
use Illuminate\Support\Facades\Log;

/**
 * MasterAgent – orchestrates the full THINK → DECIDE → ACT → OBSERVE loop.
 *
 * - Max 10 steps per task
 * - Retries failed AI calls up to 2 times per step
 * - Delegates to sub-agents when specialised work is needed
 * - Records every step in agent_steps table
 * - Persists key observations in agent_memories for future context
 *
 * Marketing OS additions (all backward-compatible, optional):
 * - Organic Growth Engine injected into system prompt (Stage 4)
 * - generatePlan() method — returns a max-3-step plan (Stage 8)
 * - Structured output: strategy/content/media/recommendations/ads (Stage 11)
 * - Iteration Rule: feeds top performers back into prompt context (Ext-3)
 * - Product marketing context block in system prompt (Stage 1)
 */
class MasterAgent
{
    private const MAX_STEPS     = 10;
    private const MAX_RETRIES   = 2;
    private const RETRY_DELAY_S = 2;
    private const PLAN_MAX_STEPS = 3;

    /** @var array<int,array{role:string,content:string}> */
    private array $messages = [];

    /** Aggregated data from all previous steps – passed to SummarizeTool at the end. */
    private array $collectedData = [];

    private int $stepOffset = 0;

    public function __construct(
        private readonly AgentTask                          $task,
        private readonly AIGateway                         $gateway,
        private readonly ToolRegistry                      $toolRegistry,
        private readonly ContentAgent                      $contentAgent,
        private readonly KeywordAgent                      $keywordAgent,
        private readonly ?MemoryService                    $memory              = null,
        private readonly ?ProductMarketingContextService   $marketingContext     = null,
        private readonly ?PerformanceService               $performance          = null,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Generate a lightweight marketing plan (max 3 steps) without executing it.
     *
     * Returns the plan and a flag indicating whether paid ads are included
     * (which requires explicit caller approval before proceeding).
     *
     * @param array $options  Optional hints: channels, goals, product_context
     * @return array{steps: array, includes_paid_ads: bool, requires_approval: bool, raw: array}
     */
    public function generatePlan(array $options = []): array
    {
        $userInput     = $this->task->user_input;
        $contextBlock  = $this->marketingContext
            ? $this->marketingContext->toPromptContext($this->task->id)
            : '';

        $systemPrompt = <<<SYS
You are a marketing strategist. Given a task, return a focused plan with EXACTLY {steps} steps.
Prefer organic actions. Only include paid-ads if the task explicitly requires it.
Return ONLY valid JSON.
SYS;

        $systemPrompt = str_replace('{steps}', self::PLAN_MAX_STEPS, $systemPrompt);

        $userPrompt = <<<PROMPT
{$contextBlock}Task: {$userInput}

Available actions (use these names exactly):
- content-strategy
- copywriting
- creative-content
- seo-audit
- page-cro
- analytics-tracking
- paid-ads  (only if task explicitly asks for paid advertising)

Return a plan with EXACTLY 3 steps:
{
  "steps": [
    {"step": 1, "action": "content-strategy", "reason": "..."},
    {"step": 2, "action": "copywriting",       "reason": "..."},
    {"step": 3, "action": "seo-audit",         "reason": "..."}
  ],
  "includes_paid_ads": false,
  "summary": "One-sentence plan description"
}
PROMPT;

        try {
            $response = $this->gateway->complete([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ], ['temperature' => 0.4, 'max_tokens' => 800]);

            $raw  = $this->gateway->parseJson($response['content']);

            // Enforce max 3 steps regardless of model output
            $steps = array_slice($raw['steps'] ?? [], 0, self::PLAN_MAX_STEPS);

            $includesPaidAds = $raw['includes_paid_ads']
                ?? (bool) count(array_filter($steps, fn($s) => ($s['action'] ?? '') === 'paid-ads'));

            return [
                'steps'            => $steps,
                'includes_paid_ads'=> $includesPaidAds,
                'requires_approval'=> $includesPaidAds,
                'summary'          => $raw['summary'] ?? '',
                'raw'              => $raw,
            ];
        } catch (\Throwable $e) {
            Log::error("[MasterAgent] generatePlan failed: " . $e->getMessage());

            // Safe fallback — a minimal 3-step organic plan
            return [
                'steps' => [
                    ['step' => 1, 'action' => 'content-strategy', 'reason' => 'Define content pillars and cadence'],
                    ['step' => 2, 'action' => 'copywriting',       'reason' => 'Generate core copy variations'],
                    ['step' => 3, 'action' => 'seo-audit',         'reason' => 'Validate keyword alignment'],
                ],
                'includes_paid_ads' => false,
                'requires_approval' => false,
                'summary'           => 'Fallback organic plan — generatePlan() AI call failed.',
                'raw'               => [],
            ];
        }
    }

    public function execute(): void
    {
        $this->task->update(['status' => 'running']);

        $this->messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'user',   'content' => $this->task->user_input],
        ];

        $this->stepOffset = $this->task->current_step;

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            // ── Pause check ────────────────────────────────────────────
            $this->task->refresh();
            if ($this->task->isPaused()) {
                Log::info("[MasterAgent] Task {$this->task->id} paused at step " . ($this->stepOffset + $i + 1));
                return;
            }

            $stepNumber = $this->stepOffset + $i + 1;
            $step = AgentStep::create([
                'task_id'     => $this->task->id,
                'step_number' => $stepNumber,
                'agent_name'  => 'MasterAgent',
                'status'      => 'running',
            ]);

            $this->task->update(['current_step' => $stepNumber]);

            // ── THINK + DECIDE (with retries) ──────────────────────────
            $decision   = null;
            $retryCount = 0;
            $tokensUsed = 0;
            $latencyMs  = 0;

            for ($r = 0; $r <= self::MAX_RETRIES; $r++) {
                if ($r > 0) {
                    sleep(self::RETRY_DELAY_S * $r);
                    Log::warning("[MasterAgent] Retrying step {$stepNumber}, attempt {$r}");
                }
                try {
                    $startMs    = (int) round(microtime(true) * 1000);
                    $response   = $this->gateway->complete($this->messages);
                    $latencyMs  = (int) round(microtime(true) * 1000) - $startMs;
                    $tokensUsed = $response['tokens']['total'] ?? 0;
                    $decision   = $this->gateway->parseJson($response['content']);
                    $retryCount = $r;
                    break;
                } catch (\Throwable $e) {
                    Log::error("[MasterAgent] Step {$stepNumber} AI call failed: " . $e->getMessage());
                    if ($r === self::MAX_RETRIES) {
                        $step->update([
                            'status'      => 'failed',
                            'thought'     => 'AI call failed after retries.',
                            'result'      => ['error' => $e->getMessage()],
                            'retry_count' => $r,
                            'latency_ms'  => $latencyMs,
                        ]);
                        $this->task->update([
                            'status'        => 'failed',
                            'error_message' => "Step {$stepNumber} AI call failed: " . $e->getMessage(),
                        ]);
                        return;
                    }
                }
            }

            $thought    = $decision['thought']    ?? '';
            $action     = $decision['action']     ?? 'finish';
            $parameters = $decision['parameters'] ?? [];
            $subAgent   = $decision['sub_agent']  ?? null;
            $status     = $decision['status']     ?? 'continue';

            $step->update([
                'thought'     => $thought,
                'action'      => $action,
                'parameters'  => $parameters,
                'agent_name'  => $subAgent ?? 'MasterAgent',
                'tokens_used' => $tokensUsed,
                'latency_ms'  => $latencyMs,
                'retry_count' => $retryCount,
            ]);

            $this->task->update([
                'total_tokens'     => $this->task->total_tokens + $tokensUsed,
                'total_latency_ms' => $this->task->total_latency_ms + $latencyMs,
            ]);

            // ── FINISH ─────────────────────────────────────────────────
            if ($status === 'finish' || $action === 'finish') {
                $finalOutput = $this->buildFinalOutput($decision);
                $step->update(['status' => 'completed', 'result' => $finalOutput]);
                $this->task->update([
                    'status'       => 'completed',
                    'final_output' => $finalOutput,
                ]);
                return;
            }

            // ── ACT ────────────────────────────────────────────────────
            $result = $this->executeAction($action, $parameters, $subAgent, $this->task);

            $step->update([
                'status' => $result['success'] ? 'completed' : 'failed',
                'result' => $result,
            ]);

            if (! $result['success']) {
                Log::warning("[MasterAgent] Step {$stepNumber} action failed: " . ($result['error'] ?? ''));
            }

            // ── Collect + store memory ─────────────────────────────────
            if ($result['success'] && ! empty($result['data'])) {
                $this->collectedData[$action] = $result['data'];
                $this->storeMemory($action, $result['data']);
            }

            // ── OBSERVE – append to conversation ───────────────────────
            $this->messages[] = ['role' => 'assistant', 'content' => json_encode($decision)];
            $this->messages[] = [
                'role'    => 'user',
                'content' => 'OBSERVATION: Tool/agent result: ' . json_encode($result),
            ];
        }

        // Exceeded max steps
        $this->task->update([
            'status'        => 'failed',
            'error_message' => 'Maximum steps (' . self::MAX_STEPS . ') reached without finishing.',
            'final_output'  => ['partial_data' => $this->collectedData],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    private function executeAction(string $action, array $parameters, ?string $subAgent, AgentTask $task): array
    {
        if ($subAgent && $subAgent !== 'MasterAgent') {
            return match ($subAgent) {
                'ContentAgent' => $this->contentAgent->execute(
                    $parameters['objective'] ?? $action, $task, $parameters
                ),
                'KeywordAgent' => $this->keywordAgent->execute(
                    $parameters['objective'] ?? $action, $task, $parameters
                ),
                default => ['success' => false, 'data' => null, 'error' => "Unknown sub-agent: {$subAgent}"],
            };
        }

        return $this->toolRegistry->execute($action, $parameters);
    }

    private function buildFinalOutput(array $decision): array
    {
        $summary = $decision['summary'] ?? null;

        if (! $summary && ! empty($this->collectedData)) {
            $summaryResult = $this->toolRegistry->execute('SummarizeTool', [
                'task_description' => $this->task->user_input,
                'gathered_data'    => $this->collectedData,
                'format'           => 'full_plan',
            ]);
            if ($summaryResult['success']) {
                $raw = $summaryResult['data'];
                return $this->toStructuredOutput($raw);
            }
        }

        return $this->toStructuredOutput([
            'summary'        => $summary ?? 'Task completed.',
            'collected_data' => $this->collectedData,
        ]);
    }

    /**
     * Reshape any output into the canonical Marketing OS structure.
     * All keys are optional — missing keys default to empty/null.
     * Backward-compatible: passes through unknown keys under 'meta'.
     */
    private function toStructuredOutput(array $raw): array
    {
        // Extract content items from various possible locations in raw
        $contentItems = $raw['content']      ?? $raw['content_plan']  ?? [];
        $mediaItems   = $raw['media']        ?? $raw['media_assets']  ?? [];
        $recommendations = $raw['recommendations']
            ?? $raw['action_items']
            ?? (! empty($raw['collected_data']) ? ['Review collected_data for details'] : []);

        $strategy = $raw['strategy']
            ?? $raw['summary']
            ?? $raw['executive_summary']
            ?? '';

        $ads = $raw['ads'] ?? null;

        // If content was collected by skills, fold it into content[]
        if (empty($contentItems) && ! empty($this->collectedData)) {
            foreach ($this->collectedData as $toolName => $data) {
                if (is_array($data) && isset($data['variations'])) {
                    $contentItems[] = ['source' => $toolName, 'variations' => $data['variations']];
                }
                if (is_array($data) && isset($data['quick_start'])) {
                    $contentItems[] = ['source' => $toolName, 'quick_start' => $data['quick_start']];
                }
            }
        }

        $structured = [
            'strategy'        => $strategy,
            'content'         => $contentItems,
            'media'           => $mediaItems,
            'recommendations' => $recommendations,
        ];

        if ($ads !== null) {
            $structured['ads'] = $ads;
        }

        // Carry through any extra keys as meta — backward compat
        $knownKeys = ['strategy', 'summary', 'executive_summary', 'content', 'content_plan',
                      'media', 'media_assets', 'recommendations', 'action_items', 'ads',
                      'collected_data', 'tokens'];
        $extra = array_diff_key($raw, array_flip($knownKeys));
        if (! empty($extra)) {
            $structured['meta'] = $extra;
        }

        return $structured;
    }

    /**
     * Persist a tool result as a memory entry (capped at 500 chars by MemoryService).
     */
    private function storeMemory(string $toolName, mixed $data): void
    {
        if (! $this->memory) {
            return;
        }

        $value = is_array($data) ? json_encode($data) : (string) $data;
        $this->memory->store($this->task->id, $toolName . '_result', $value, "Result from {$toolName}");
    }

    private function buildSystemPrompt(): string
    {
        $catalogue    = $this->toolRegistry->getCatalogueDescription();
        $memoryPrefix = $this->buildMemoryPrefix();
        $contextBlock = $this->buildProductContextBlock();
        $iterationBlock = $this->buildIterationBlock();

        return <<<PROMPT
You are a Master Marketing Agent. You orchestrate a multi-step workflow to fulfil marketing tasks for businesses.
{$contextBlock}
## Your Loop
THINK (reason about what to do) → DECIDE (pick action) → ACT (the system executes) → OBSERVE (read result) → REPEAT.

## Sub-Agents Available
- **ContentAgent**: Specialised for complex content creation tasks (multiple content pieces, brand voice).
- **KeywordAgent**: Specialised for deep keyword research, SEO strategy, and ad targeting.

## Tools Available
{$catalogue}
{$memoryPrefix}
## Organic Growth Engine (always follow this sequence first)
Run organic steps BEFORE any paid activity:
1. **content-strategy** — define pillars, channels, and 30-day posting cadence
2. **copywriting** — produce hero copy and A/B/C variations
3. **creative-content** — generate platform-adapted creative assets
4. **seo-audit** — validate keyword alignment and on-page signals
Only after these 4 steps should paid-ads ever be considered — and only if explicitly approved.
{$iterationBlock}
## Decision Output Format
Every response MUST be strict JSON (no markdown, no explanation outside JSON):
```json
{
  "thought": "Your step-by-step reasoning about what to do next",
  "action": "ToolName | SubAgentName | finish",
  "sub_agent": "ContentAgent | KeywordAgent | null",
  "parameters": { "param1": "value1" },
  "status": "continue | finish",
  "summary": "Only required when status=finish: executive summary of all work done"
}
```

## Rules
1. ORGANIC FIRST — follow the Organic Growth Engine sequence.
2. Generate content AFTER you have keywords and audience insights.
3. Use SummarizeTool as your LAST step to consolidate everything into a marketing plan.
4. When action = "finish", set status = "finish" and write a clear summary.
5. Maximum {steps_limit} steps total — plan efficiently.
6. If a tool fails, adapt your plan (try a different tool or approach). Never crash.
7. Output ONLY valid JSON. No markdown headers. No extra text.
8. paid-ads skill MUST NOT be called unless task explicitly requests ads AND approved=true is set.

## When to Use Sub-Agents
- Use **ContentAgent** when you need multiple content variations or a comprehensive content strategy.
- Use **KeywordAgent** when you need deep keyword research with competitive analysis.
- Otherwise, call tools directly.
PROMPT;
    }

    /**
     * Build a memory context prefix (max ~800 tokens, 10 entries, 300 chars each).
     */
    private function buildMemoryPrefix(): string
    {
        if (! $this->memory) {
            return '';
        }

        $memories = $this->memory->all($this->task->id);

        if (empty($memories)) {
            return '';
        }

        $lines = ["## Prior Context (from earlier steps)"];
        foreach ($memories as $key => $value) {
            $lines[] = "- **{$key}**: {$value}";
        }
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Inject product marketing context into the system prompt (Stage 1).
     * Returns empty string if no context — prompt stays clean.
     */
    private function buildProductContextBlock(): string
    {
        if (! $this->marketingContext) {
            return '';
        }

        $block = $this->marketingContext->toPromptContext($this->task->id);

        return $block ? "\n{$block}" : '';
    }

    /**
     * Inject iteration rule if top performers are available (Ext-3).
     * Checks memory for 'top_performers' key first; if missing and PerformanceService
     * is available, fetches and stores it.
     */
    private function buildIterationBlock(): string
    {
        $summary = '';

        // Check memory first (cheap)
        if ($this->memory) {
            $cached = $this->memory->retrieve($this->task->id, 'top_performers');
            if ($cached) {
                $summary = $cached;
            }
        }

        // Fetch from PerformanceService if not cached
        if (! $summary && $this->performance) {
            $performers = $this->performance->getTopPerformers($this->task->id);
            if (! empty($performers['summary'])) {
                $summary = $performers['summary'];
                // Store for subsequent steps
                if ($this->memory) {
                    $this->memory->store($this->task->id, 'top_performers', $summary, 'Top performing content summary');
                }
            }
        }

        if (! $summary) {
            return '';
        }

        return <<<BLOCK

## Iteration Rule (Past Performance)
Before generating new content, build on what worked:
{$summary}
→ Reuse the winning hook formula and tone. Vary the angle, not the structure.

BLOCK;
    }
}
