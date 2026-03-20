<?php

namespace App\AgentSystem\Agents;

use App\AgentSystem\Gateway\AIGateway;
use App\AgentSystem\Tools\ToolRegistry;
use App\Models\AgentStep;
use App\Models\AgentTask;
use Illuminate\Support\Facades\Log;

/**
 * ContentAgent – sub-agent specialised in content creation.
 * Generates multiple content pieces, brand voice guidelines, and a full content strategy.
 */
class ContentAgent implements AgentInterface
{
    private const MAX_STEPS   = 4;   // sub-agents use fewer steps
    private const MAX_RETRIES = 2;

    public function __construct(
        private readonly AIGateway    $gateway,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    public function getName(): string          { return 'ContentAgent'; }
    public function getSpecialisation(): string { return 'Expert at generating and refining marketing content across multiple channels and formats.'; }

    public function execute(string $objective, AgentTask $task, array $context = []): array
    {
        $stepOffset = $task->current_step;
        $messages   = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'user',   'content' => "Objective: {$objective}\nContext: " . json_encode($context)],
        ];

        $collected = [];

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $stepNumber = $stepOffset + $i + 1;

            $step = AgentStep::create([
                'task_id'     => $task->id,
                'step_number' => $stepNumber,
                'agent_name'  => 'ContentAgent',
                'status'      => 'running',
            ]);

            $task->update(['current_step' => $stepNumber]);

            $decision = null;
            for ($r = 0; $r <= self::MAX_RETRIES; $r++) {
                if ($r > 0) sleep(self::MAX_RETRIES * $r);
                try {
                    $res      = $this->gateway->complete($messages);
                    $decision = $this->gateway->parseJson($res['content']);
                    $step->update(['tokens_used' => $res['tokens']['total'] ?? 0]);
                    break;
                } catch (\Throwable $e) {
                    Log::error("[ContentAgent] Step {$stepNumber} failed: " . $e->getMessage());
                    if ($r === self::MAX_RETRIES) {
                        $step->update(['status' => 'failed', 'result' => ['error' => $e->getMessage()]]);
                        return ['success' => false, 'data' => $collected, 'steps_used' => $i + 1, 'error' => $e->getMessage()];
                    }
                }
            }

            $action     = $decision['action']     ?? 'finish';
            $parameters = $decision['parameters'] ?? [];

            $step->update([
                'thought'    => $decision['thought'] ?? '',
                'action'     => $action,
                'parameters' => $parameters,
            ]);

            if ($decision['status'] === 'finish' || $action === 'finish') {
                $step->update(['status' => 'completed', 'result' => ['collected' => $collected]]);
                return ['success' => true, 'data' => $collected, 'steps_used' => $i + 1, 'error' => null];
            }

            $result = $this->toolRegistry->execute($action, $parameters);

            $step->update([
                'status' => $result['success'] ? 'completed' : 'failed',
                'result' => $result,
            ]);

            if ($result['success'] && ! empty($result['data'])) {
                $collected[$action] = $result['data'];
            }

            $messages[] = ['role' => 'assistant', 'content' => json_encode($decision)];
            $messages[] = ['role' => 'user',       'content' => 'Result: ' . json_encode($result)];
        }

        return ['success' => true, 'data' => $collected, 'steps_used' => self::MAX_STEPS, 'error' => null];
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
You are the ContentAgent, a specialist in marketing content creation.

Your available tools:
- **GenerateContentTool**: Generates ad copy, social posts, emails, blogs, SMS, taglines.

For each step, respond ONLY with valid JSON:
{
  "thought": "reasoning",
  "action": "GenerateContentTool | finish",
  "parameters": { ... },
  "status": "continue | finish"
}

Strategy:
1. Generate the primary content type first.
2. Then generate 1-2 variations for different channels.
3. Finish and return all generated content.

Rules:
- Output ONLY valid JSON. No extra text.
- After 3 content pieces or when objective is met, set status=finish.
PROMPT;
    }
}
