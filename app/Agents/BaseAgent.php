<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Models\AgentStep;
use App\Services\AI\OpenAIService;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Knowledge\VectorStoreService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class BaseAgent
{
    protected string $agentType;
    protected string $provider   = 'openai';
    protected string $model;
    protected int    $maxSteps   = 15;
    protected array  $tools      = [];
    protected string $systemPrompt = '';

    public function __construct(
        protected readonly OpenAIService    $openai,
        protected readonly AnthropicService $anthropic,
        protected readonly GeminiService    $gemini,
        protected readonly TelegramBotService $telegram,
        protected readonly VectorStoreService $knowledge,
    ) {
        $config = config("agents.agents.{$this->agentType}", []);
        $this->provider     = $config['provider']    ?? $this->provider;
        $this->model        = $config['model']       ?? 'gpt-4o';
        $this->maxSteps     = $config['max_steps']   ?? $this->maxSteps;
        $this->systemPrompt = $config['system_prompt'] ?? $this->systemPrompt;
        $this->tools        = $config['tools']       ?? $this->tools;
    }

    /**
     * Execute the agent loop for a given job.
     */
    final public function run(AgentJob $job): void
    {
        $job->update(['status' => 'running', 'started_at' => now()]);
        $this->notifyUser($job, "⚡ *{$this->agentType}* agent started...");

        $messages  = $this->buildInitialMessages($job);
        $stepCount = 0;
        $result    = null;

        try {
            while ($stepCount < $this->maxSteps) {
                $stepCount++;

                Log::debug("Agent step {$stepCount}", ['job_id' => $job->id, 'agent' => $this->agentType]);

                // Call AI model — track latency
                $aiStart   = (int) round(microtime(true) * 1000);
                $response  = $this->callAI($messages, $this->getToolDefinitions());
                $aiLatency = (int) round(microtime(true) * 1000) - $aiStart;
                $tokensUsed = $this->extractTokensUsed($response);

                // Check for tool calls
                $toolCalls = $this->extractToolCalls($response);

                if (empty($toolCalls)) {
                    // No tool calls — extract final text response
                    $result = $this->extractText($response);

                    // Record final step in pipeline
                    $this->recordStep($job, $stepCount, 'finish', null, [], $result, $tokensUsed, $aiLatency);
                    break;
                }

                // Execute tool calls and append results to messages
                $messages[] = $this->buildAssistantMessage($response);
                $toolResults = [];

                foreach ($toolCalls as $toolCall) {
                    $toolStart = (int) round(microtime(true) * 1000);

                    $toolResult = $this->executeTool(
                        name:     $toolCall['name'],
                        args:     $toolCall['arguments'],
                        job:      $job,
                    );

                    $toolLatency = (int) round(microtime(true) * 1000) - $toolStart;
                    $resultStr   = is_string($toolResult) ? $toolResult : json_encode($toolResult);

                    $toolResults[] = [
                        'tool_call_id' => $toolCall['id'],
                        'name'         => $toolCall['name'],
                        'content'      => $resultStr,
                    ];

                    // Record this step in agent_steps for the pipeline dashboard
                    $this->recordStep($job, $stepCount, $toolCall['name'], null, $toolCall['arguments'], $resultStr, $tokensUsed, $aiLatency + $toolLatency);

                    Log::debug("Tool executed", [
                        'tool'    => $toolCall['name'],
                        'job_id'  => $job->id,
                        'success' => ! empty($toolResult),
                    ]);
                }

                $messages[] = $this->buildToolResultMessage($toolResults);

                // Update progress
                $job->update([
                    'steps_taken' => $stepCount,
                    'last_tool'   => $toolCalls[0]['name'] ?? null,
                ]);
            }

            if ($stepCount >= $this->maxSteps && $result === null) {
                $result = "Agent reached maximum steps ({$this->maxSteps}). Partial work may have been completed.";
                Log::warning("Agent hit max steps", ['job_id' => $job->id]);
            }

            // Store result and update job
            $job->update([
                'status'       => 'completed',
                'result'       => $result,
                'steps_taken'  => $stepCount,
                'completed_at' => now(),
            ]);

            $this->notifyUser($job, "✅ *{$this->agentType}* completed:\n\n{$result}");

        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();

            Log::error("Agent failed", [
                'job_id'  => $job->id,
                'agent'   => $this->agentType,
                'error'   => $errorMsg,
                'trace'   => $e->getTraceAsString(),
            ]);

            $job->update([
                'status'        => 'failed',
                'error_message' => $errorMsg,
                'completed_at'  => now(),
            ]);

            $this->notifyUser($job, "❌ *{$this->agentType}* failed: " . Str::limit($errorMsg, 200));

            throw $e;
        }
    }

    /**
     * Execute a named tool. Subclasses implement specific tools here.
     */
    abstract protected function executeTool(string $name, array $args, AgentJob $job): mixed;

    /**
     * Return tool definitions in OpenAI function-calling format.
     */
    abstract protected function getToolDefinitions(): array;

    // ─── AI Provider Abstraction ──────────────────────────────────

    protected function callAI(array $messages, array $tools = []): array
    {
        return match ($this->provider) {
            'anthropic' => $this->anthropic->chat(
                messages:     $messages,
                model:        $this->model,
                systemPrompt: $this->systemPrompt,
                tools:        $this->convertToolsForAnthropic($tools),
                maxTokens:    config('agents.anthropic.max_tokens', 8192),
            ),
            'gemini' => $this->gemini->chat(
                messages:     $messages,
                model:        $this->model,
                systemPrompt: $this->systemPrompt,
                tools:        $tools,
                maxTokens:    config('agents.openai.max_tokens', 4096),
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

    private function buildInitialMessages(AgentJob $job): array
    {
        $messages = [];

        // Inject relevant knowledge context if available
        $context = $this->knowledge->search($job->instruction, topK: 3);
        if (! empty($context)) {
            $contextText = "Relevant knowledge from the knowledge base:\n\n";
            foreach ($context as $item) {
                $contextText .= "- {$item['content']}\n";
            }
            $messages[] = ['role' => 'user', 'content' => $contextText];
            $messages[] = ['role' => 'assistant', 'content' => 'I have reviewed the relevant knowledge context and will use it to inform my response.'];
        }

        $messages[] = ['role' => 'user', 'content' => $job->instruction];

        return $messages;
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
                'type'         => 'tool_result',
                'tool_use_id'  => $r['tool_call_id'],
                'content'      => $r['content'],
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

    private function convertToolsForAnthropic(array $openAiTools): array
    {
        return array_map(fn($tool) => [
            'name'         => $tool['function']['name'],
            'description'  => $tool['function']['description'],
            'input_schema' => $tool['function']['parameters'],
        ], $openAiTools);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Write a step record to agent_steps so the pipeline dashboard can display it.
     */
    private function recordStep(
        AgentJob $job,
        int      $stepNumber,
        string   $action,
        ?string  $thought,
        array    $parameters,
        mixed    $result,
        int      $tokensUsed,
        int      $latencyMs,
    ): void {
        try {
            AgentStep::create([
                'task_id'     => null,
                'agent_job_id'=> $job->id,
                'step_number' => $stepNumber,
                'agent_name'  => $this->agentType,
                'action'      => $action,
                'thought'     => $thought,
                'parameters'  => $parameters,
                'result'      => is_string($result) ? ['output' => $result] : (array) $result,
                'status'      => 'completed',
                'tokens_used' => $tokensUsed,
                'latency_ms'  => $latencyMs,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record agent step', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Extract total tokens used from an AI response (works for OpenAI and Gemini responses).
     */
    private function extractTokensUsed(array $response): int
    {
        // Anthropic format
        if (isset($response['usage']['input_tokens'])) {
            return ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);
        }
        // OpenAI / Gemini-normalized format
        return $response['usage']['total_tokens'] ?? 0;
    }

    protected function notifyUser(AgentJob $job, string $message): void
    {
        if ($job->chat_id) {
            try {
                $this->telegram->sendMessage($job->chat_id, $message);
            } catch (\Throwable $e) {
                Log::warning("Failed to notify user", ['error' => $e->getMessage()]);
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
