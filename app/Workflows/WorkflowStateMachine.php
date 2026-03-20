<?php

namespace App\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowTask;
use App\Services\AI\AIRouter;
use App\Services\Knowledge\ContextGraphService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Supervisor\SupervisorService;
use Illuminate\Support\Facades\Log;

class WorkflowStateMachine
{
    public function __construct(
        private readonly AIRouter             $aiRouter,
        private readonly ContextGraphService  $contextGraph,
        private readonly WorkflowTaskRunner   $taskRunner,
        private readonly TelegramBotService   $telegram,
        private readonly SupervisorService    $supervisor,
    ) {}

    /**
     * Advance a workflow through its state machine.
     * Called repeatedly by ProcessWorkflowJob until terminal state.
     */
    public function advance(Workflow $workflow): void
    {
        try {
            match ($workflow->status) {
                Workflow::STATUS_INTAKE            => $this->handleIntake($workflow),
                Workflow::STATUS_CONTEXT_RETRIEVAL => $this->handleContextRetrieval($workflow),
                Workflow::STATUS_PLANNING          => $this->handlePlanning($workflow),
                Workflow::STATUS_TASK_EXECUTION    => $this->handleTaskExecution($workflow),
                Workflow::STATUS_REVIEW            => $this->handleReview($workflow),
                Workflow::STATUS_OWNER_APPROVAL    => $this->handleOwnerApproval($workflow),
                Workflow::STATUS_EXECUTION         => $this->handleExecution($workflow),
                Workflow::STATUS_OBSERVATION       => $this->handleObservation($workflow),
                Workflow::STATUS_LEARNING          => $this->handleLearning($workflow),
                default                            => null,
            };
        } catch (\Throwable $e) {
            $this->handleFailure($workflow, $e);
        }
    }

    // ── State handlers ────────────────────────────────────────────

    private function handleIntake(Workflow $workflow): void
    {
        $workflow->log('info', 'state.intake', 'Workflow received and validated');

        // Validate input payload has required fields
        $input = $workflow->input_payload;
        if (empty($input)) {
            throw new \RuntimeException('Workflow input payload is empty');
        }

        $workflow->transitionTo(Workflow::STATUS_CONTEXT_RETRIEVAL);
        $this->advance($workflow->fresh());
    }

    private function handleContextRetrieval(Workflow $workflow): void
    {
        $workflow->log('info', 'state.context_retrieval', 'Retrieving relevant context from knowledge graph');

        $instruction = $workflow->input_payload['instruction'] ?? $workflow->name;

        // Pull semantically relevant nodes from context graph
        $context = $this->contextGraph->retrieveForWorkflow(
            instruction: $instruction,
            workflowType: $workflow->type,
            topK: 8,
        );

        $workflow->update(['context' => $context]);
        $workflow->log('info', 'state.context_retrieved', 'Context retrieved', ['node_count' => count($context['nodes'] ?? [])]);

        $workflow->transitionTo(Workflow::STATUS_PLANNING);
        $this->advance($workflow->fresh());
    }

    private function handlePlanning(Workflow $workflow): void
    {
        $workflow->log('info', 'state.planning', 'Generating task plan');

        $plan = $this->generatePlan($workflow);

        // Create WorkflowTask records from plan
        foreach ($plan['tasks'] as $i => $taskDef) {
            WorkflowTask::create([
                'workflow_id'  => $workflow->id,
                'name'         => $taskDef['name'],
                'type'         => $taskDef['type'],
                'sequence'     => $i,
                'agent_type'   => $taskDef['agent_type'] ?? null,
                'skill_name'   => $taskDef['skill'] ?? null,
                'input'        => $taskDef['input'] ?? [],
                'metadata'     => $taskDef['metadata'] ?? [],
                'timeout_seconds' => $taskDef['timeout'] ?? 300,
                'depends_on_task_id' => $taskDef['depends_on'] ?? null,
            ]);
        }

        $workflow->update(['plan' => $plan]);
        $workflow->log('info', 'state.plan_created', 'Plan created', ['task_count' => count($plan['tasks'])]);

        // Check if this workflow type requires approval before executing
        if ($workflow->requires_approval) {
            $this->requestApproval($workflow, 'Plan ready for approval');
            $workflow->transitionTo(Workflow::STATUS_OWNER_APPROVAL);
        } else {
            $workflow->transitionTo(Workflow::STATUS_TASK_EXECUTION);
            $this->advance($workflow->fresh());
        }
    }

    private function handleTaskExecution(Workflow $workflow): void
    {
        $workflow->log('info', 'state.task_execution', 'Executing workflow tasks');

        $pendingTasks = $workflow->tasks()->where('status', 'pending')->get();

        if ($pendingTasks->isEmpty()) {
            $workflow->log('info', 'state.tasks_complete', 'All tasks completed');
            $workflow->transitionTo(Workflow::STATUS_REVIEW);
            $this->advance($workflow->fresh());
            return;
        }

        // Find next executable task (dependencies met)
        foreach ($pendingTasks as $task) {
            if ($this->taskRunner->canExecute($task)) {
                $this->taskRunner->execute($task, $workflow);
                break; // One task at a time per cycle, re-queue for next
            }
        }

        // Re-check if all done
        $remaining = $workflow->tasks()->whereNotIn('status', ['completed', 'skipped'])->count();
        if ($remaining === 0) {
            $workflow->transitionTo(Workflow::STATUS_REVIEW);
            $this->advance($workflow->fresh());
        }
    }

    private function handleReview(Workflow $workflow): void
    {
        $workflow->log('info', 'state.review', 'Reviewing task outputs');

        $tasks  = $workflow->tasks()->get();
        $failed = $tasks->where('status', 'failed');

        if ($failed->count() > 0) {
            $names = $failed->pluck('name')->implode(', ');
            throw new \RuntimeException("Tasks failed: {$names}");
        }

        // Collect all outputs into workflow output
        $outputs = $tasks->where('status', 'completed')
            ->mapWithKeys(fn($t) => [$t->name => $t->output])
            ->toArray();

        $workflow->update(['output' => $outputs]);

        // High-stakes workflows need human sign-off
        if ($this->requiresPostExecutionApproval($workflow)) {
            $this->requestApproval($workflow, 'Results ready for review');
            $workflow->transitionTo(Workflow::STATUS_OWNER_APPROVAL);
        } else {
            $workflow->transitionTo(Workflow::STATUS_EXECUTION);
            $this->advance($workflow->fresh());
        }
    }

    private function handleOwnerApproval(Workflow $workflow): void
    {
        // Waiting state — nothing to do here
        // Approval comes via WorkflowDispatcher::approve() called from Telegram
        $workflow->log('debug', 'state.awaiting_approval', 'Waiting for owner approval');
    }

    private function handleExecution(Workflow $workflow): void
    {
        $workflow->log('info', 'state.execution', 'Executing approved output');

        // This is where campaigns get sent, jobs get posted, etc.
        $executor = $this->resolveExecutor($workflow->type);
        if ($executor) {
            $result = $executor->execute($workflow);
            $output = $workflow->output;
            $output['execution_result'] = $result;
            $workflow->update(['output' => $output]);
        }

        $workflow->transitionTo(Workflow::STATUS_OBSERVATION);
        $this->advance($workflow->fresh());
    }

    private function handleObservation(Workflow $workflow): void
    {
        $workflow->log('info', 'state.observation', 'Observing execution results');

        // Collect any metrics/signals from the execution
        $observations = [
            'completed_tasks' => $workflow->tasks()->where('status', 'completed')->count(),
            'total_tasks'     => $workflow->tasks()->count(),
            'duration_s'      => $workflow->started_at ? now()->diffInSeconds($workflow->started_at) : 0,
            'output_keys'     => array_keys($workflow->output ?? []),
        ];

        $meta = $workflow->metadata;
        $meta['observations'] = $observations;
        $workflow->update(['metadata' => $meta]);

        $workflow->transitionTo(Workflow::STATUS_LEARNING);
        $this->advance($workflow->fresh());
    }

    private function handleLearning(Workflow $workflow): void
    {
        $workflow->log('info', 'state.learning', 'Storing learnings in context graph');

        // Extract learnings and persist to context graph for future workflows
        $this->contextGraph->learnFromWorkflow($workflow);

        // Notify user
        $summary = $this->buildCompletionSummary($workflow);
        if ($workflow->chat_id) {
            $this->telegram->sendMessage($workflow->chat_id, $summary);
        }

        $workflow->transitionTo(Workflow::STATUS_COMPLETED);
        $workflow->log('info', 'state.completed', 'Workflow completed successfully');
    }

    // ── Failure handling ──────────────────────────────────────────

    private function handleFailure(Workflow $workflow, \Throwable $e): void
    {
        $message = $e->getMessage();
        Log::error("Workflow failure", ['id' => $workflow->id, 'error' => $message, 'state' => $workflow->status]);

        $workflow->log('error', 'state.failed', $message, ['exception' => get_class($e)]);
        $workflow->transitionTo(Workflow::STATUS_FAILED, $message);

        // Emit system event for supervisor
        $this->supervisor->handleWorkflowFailure($workflow, $message);

        // Notify user
        if ($workflow->chat_id) {
            $this->telegram->sendMessage(
                $workflow->chat_id,
                "❌ Workflow `{$workflow->id}` failed\n*{$workflow->name}*\nError: " . substr($message, 0, 200)
                . "\n\nAttempts: {$workflow->retry_count}/{$workflow->max_retries}"
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function generatePlan(Workflow $workflow): array
    {
        $instruction = $workflow->input_payload['instruction'] ?? $workflow->name;
        $type        = $workflow->type;
        $context     = json_encode($workflow->context ?? []);

        $prompt = <<<PROMPT
You are a workflow planner. Generate a task execution plan for this {$type} workflow.

Instruction: {$instruction}
Available context: {$context}

Available task types:
- agent_run: run an AI agent (specify agent_type: marketing|content|hiring|growth|media|knowledge)
- skill_exec: execute a registered skill (specify skill: skill_name)
- media_process: process media through the pipeline

Return ONLY valid JSON:
{
  "tasks": [
    {
      "name": "task_name",
      "type": "agent_run|skill_exec|media_process",
      "agent_type": "agent_name_if_agent_run",
      "skill": "skill_name_if_skill_exec",
      "input": {"key": "value"},
      "timeout": 300,
      "depends_on": null
    }
  ],
  "estimated_duration_minutes": 5,
  "requires_approval": false
}

Keep it simple — 1-5 tasks maximum. Each task must directly contribute to fulfilling the instruction.
PROMPT;

        $raw  = $this->aiRouter->complete($prompt, 'gpt-4o', 1024, 0.2);
        $plan = json_decode($raw, true);

        if (! $plan || empty($plan['tasks'])) {
            // Fallback single-task plan
            $plan = [
                'tasks' => [[
                    'name'       => 'execute_instruction',
                    'type'       => 'agent_run',
                    'agent_type' => $type,
                    'input'      => ['instruction' => $instruction],
                    'timeout'    => 300,
                ]],
                'estimated_duration_minutes' => 3,
                'requires_approval' => false,
            ];
        }

        return $plan;
    }

    private function requiresPostExecutionApproval(Workflow $workflow): bool
    {
        // Campaign sends and job postings need human approval
        return in_array($workflow->type, ['marketing', 'hiring'])
            && ! $workflow->approval_granted;
    }

    private function requestApproval(Workflow $workflow, string $reason): void
    {
        if (! $workflow->chat_id) {
            return;
        }

        $this->telegram->sendMessage(
            $workflow->chat_id,
            "⏳ *Approval required*\n\nWorkflow: `{$workflow->id}`\n{$workflow->name}\n\nReason: {$reason}\n\nReply with `/approve {$workflow->id}` to proceed.",
            $this->telegram->inlineKeyboard([[
                ['text' => 'Approve', 'callback_data' => "confirm_run:" . base64_encode($workflow->id)],
                ['text' => 'Cancel',  'callback_data' => "cancel_job:{$workflow->id}"],
            ]])
        );
    }

    private function buildCompletionSummary(Workflow $workflow): string
    {
        $duration = $workflow->started_at
            ? now()->diffInSeconds($workflow->started_at) . 's'
            : 'unknown';

        return "✅ *Workflow completed*\n"
             . "`{$workflow->id}`\n"
             . "*{$workflow->name}*\n"
             . "Duration: {$duration}\n"
             . "Tasks: {$workflow->tasks()->where('status','completed')->count()} completed";
    }

    private function resolveExecutor(string $type): ?object
    {
        // Future: resolve type-specific executors (CampaignExecutor, JobPostingExecutor)
        return null;
    }
}
