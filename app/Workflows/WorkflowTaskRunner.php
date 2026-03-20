<?php

namespace App\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowTask;
use App\Agents\AgentOrchestrator;
use App\Services\Skills\SkillExecutorService;
use App\Services\Media\MediaPipelineService;
use Illuminate\Support\Facades\Log;

class WorkflowTaskRunner
{
    public function __construct(
        private readonly AgentOrchestrator   $orchestrator,
        private readonly SkillExecutorService $skills,
        private readonly MediaPipelineService $mediaPipeline,
    ) {}

    /**
     * Check if a task's dependencies are all met.
     */
    public function canExecute(WorkflowTask $task): bool
    {
        if (! $task->depends_on_task_id) {
            return true;
        }

        $dependency = WorkflowTask::find($task->depends_on_task_id);
        return $dependency && $dependency->status === 'completed';
    }

    /**
     * Execute a single workflow task synchronously.
     */
    public function execute(WorkflowTask $task, Workflow $workflow): void
    {
        $task->update(['status' => 'running', 'started_at' => now()]);

        try {
            $result = match ($task->type) {
                'agent_run'     => $this->runAgentTask($task, $workflow),
                'skill_exec'    => $this->runSkillTask($task, $workflow),
                'media_process' => $this->runMediaTask($task, $workflow),
                default         => throw new \RuntimeException("Unknown task type: {$task->type}"),
            };

            $task->update([
                'status'       => 'completed',
                'output'       => is_array($result) ? $result : ['result' => $result],
                'completed_at' => now(),
            ]);

            $workflow->log('info', 'task.completed', "Task '{$task->name}' completed", [
                'task_id' => $task->id,
                'type'    => $task->type,
            ]);

        } catch (\Throwable $e) {
            $this->handleTaskFailure($task, $workflow, $e);
        }
    }

    // ── Task type handlers ────────────────────────────────────────

    private function runAgentTask(WorkflowTask $task, Workflow $workflow): array
    {
        $agentType   = $task->agent_type ?? 'content';
        $instruction = $task->input['instruction'] ?? $workflow->input_payload['instruction'] ?? $task->name;

        // Enrich with workflow context
        if (! empty($workflow->context['nodes'])) {
            $contextText = collect($workflow->context['nodes'])
                ->take(3)
                ->map(fn($n) => $n['title'] . ': ' . $n['content'])
                ->implode("\n");
            $instruction = $instruction . "\n\nRelevant context:\n" . $contextText;
        }

        $agentJob = $this->orchestrator->dispatch(
            agentType:   $agentType,
            instruction: $instruction,
            chatId:      $workflow->chat_id ?? 0,
            userId:      $workflow->user_id ?? 0,
        );

        // For task runner, we wait for completion (sync execution)
        // The agent job is dispatched to queue and we poll
        $maxWaitSeconds = $task->timeout_seconds ?? 300;
        $polled = 0;

        while ($polled < $maxWaitSeconds) {
            sleep(3);
            $polled += 3;
            $agentJob->refresh();

            if ($agentJob->status === 'completed') {
                $task->update(['metadata' => array_merge($task->metadata ?? [], ['agent_job_id' => $agentJob->id])]);
                return ['agent_job_id' => $agentJob->id, 'result' => $agentJob->result];
            }

            if ($agentJob->status === 'failed') {
                throw new \RuntimeException("Agent job failed: " . $agentJob->error_message);
            }

            if ($agentJob->status === 'cancelled') {
                throw new \RuntimeException("Agent job cancelled");
            }
        }

        throw new \RuntimeException("Agent task timed out after {$maxWaitSeconds}s");
    }

    private function runSkillTask(WorkflowTask $task, Workflow $workflow): array
    {
        $skillName = $task->skill_name;
        if (! $skillName) {
            throw new \RuntimeException("Skill task missing skill_name");
        }

        return $this->skills->execute($skillName, $task->input ?? [], $workflow->id);
    }

    private function runMediaTask(WorkflowTask $task, Workflow $workflow): array
    {
        $mediaAssetId = $task->input['media_asset_id'] ?? null;
        if (! $mediaAssetId) {
            throw new \RuntimeException("Media task missing media_asset_id");
        }

        return $this->mediaPipeline->processAsset($mediaAssetId, $task->input);
    }

    // ── Failure handling ──────────────────────────────────────────

    private function handleTaskFailure(WorkflowTask $task, Workflow $workflow, \Throwable $e): void
    {
        $message = $e->getMessage();

        if ($task->retry_count < $task->max_retries) {
            $task->increment('retry_count');
            $task->update([
                'status'        => 'pending',
                'error_message' => $message,
                'started_at'    => null,
            ]);
            $workflow->log('warning', 'task.retry', "Task '{$task->name}' retrying ({$task->retry_count}/{$task->max_retries})", [
                'error' => $message,
            ]);
        } else {
            $task->update([
                'status'        => 'failed',
                'error_message' => $message,
                'completed_at'  => now(),
            ]);
            $workflow->log('error', 'task.failed', "Task '{$task->name}' failed permanently", [
                'error' => $message,
            ]);
            Log::error("WorkflowTask failed permanently", ['task_id' => $task->id, 'error' => $message]);
            throw $e;
        }
    }
}
