<?php

namespace App\AgentSystem\Agents;

use App\AgentSystem\Gateway\AIGateway;
use App\AgentSystem\Tools\ToolRegistry;
use App\Models\AgentStep;
use App\Models\AgentTask;
use App\Services\MemoryService;
use Illuminate\Support\Facades\Log;

/**
 * MasterAgent – orchestrates the full THINK → DECIDE → ACT → OBSERVE loop.
 *
 * - Max 10 steps per task
 * - Retries failed AI calls up to 2 times per step
 * - Delegates to sub-agents when specialised work is needed
 * - Records every step in agent_steps table
 * - Persists key observations in agent_memories for future context
 */
class MasterAgent
{
    private const MAX_STEPS     = 10;
    private const MAX_RETRIES   = 2;
    private const RETRY_DELAY_S = 2;

    /** @var array<int,array{role:string,content:string}> */
    private array $messages = [];

    /** Aggregated data from all previous steps – passed to SummarizeTool at the end. */
    private array $collectedData = [];

    private int $stepOffset = 0;

    public function __construct(
        private readonly AgentTask     $task,
        private readonly AIGateway     $gateway,
        private readonly ToolRegistry  $toolRegistry,
        private readonly ContentAgent  $contentAgent,
        private readonly KeywordAgent  $keywordAgent,
        private readonly ?MemoryService $memory = null,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────

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
                return $summaryResult['data'];
            }
        }

        return [
            'summary'        => $summary ?? 'Task completed.',
            'collected_data' => $this->collectedData,
        ];
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

        return <<<PROMPT
You are a Master Marketing Agent. You orchestrate a multi-step workflow to fulfil marketing tasks for businesses.

## Your Loop
THINK (reason about what to do) → DECIDE (pick action) → ACT (the system executes) → OBSERVE (read result) → REPEAT.

## Sub-Agents Available
- **ContentAgent**: Specialised for complex content creation tasks (multiple content pieces, brand voice).
- **KeywordAgent**: Specialised for deep keyword research, SEO strategy, and ad targeting.

## Tools Available
{$catalogue}
{$memoryPrefix}
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
1. Always start with understanding the task (audience analysis, competitor analysis, or keywords first).
2. Generate content AFTER you have keywords and audience insights.
3. Use SummarizeTool as your LAST step to consolidate everything into a marketing plan.
4. When action = "finish", set status = "finish" and write a clear summary.
5. Maximum {steps_limit} steps total — plan efficiently.
6. If a tool fails, adapt your plan (try a different tool or approach).
7. Output ONLY valid JSON. No markdown headers. No extra text.

## When to Use Sub-Agents
- Use **ContentAgent** when you need multiple content variations or a comprehensive content strategy.
- Use **KeywordAgent** when you need deep keyword research with competitive analysis.
- Otherwise, call tools directly.
PROMPT;
    }

    /**
     * Build a memory context prefix (max ~800 tokens, 10 entries, 500 chars each).
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
}
