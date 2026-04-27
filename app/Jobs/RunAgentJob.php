<?php
namespace App\Jobs;

use App\Agents\AgentOrchestrator;
use App\Exceptions\RateLimitException;
use App\Models\AgentJob;
use App\Models\Workflow;
use App\Models\WorkflowTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600;
    public int $backoff = 30;

    public function __construct(public readonly string $agentJobId) {}

    public function handle(): void
    {
        $job = AgentJob::find($this->agentJobId);
        if (! $job || in_array($job->status, ['completed', 'failed', 'cancelled'])) {
            return;
        }

        $agentClass = $job->agent_class;
        if (! class_exists($agentClass)) {
            $job->update(['status' => 'failed', 'error_message' => "Agent class not found: {$agentClass}"]);
            return;
        }

        try {
            /** @var \App\Agents\BaseAgent $agent */
            $agent = app($agentClass);
            $agent->run($job);

            // Workflow DAG: on success, unblock dependent steps
            $job->refresh();
            if ($job->status === 'completed' && $job->workflow_id) {
                $this->advanceWorkflowDag($job);
            }
        } catch (RateLimitException $e) {
            // Release back to queue after the provider's requested back-off window.
            // This keeps the Horizon worker free to process other jobs immediately.
            Log::info('RunAgentJob: rate limit — releasing job', [
                'job_id'      => $this->agentJobId,
                'retry_after' => $e->getRetryAfter(),
            ]);
            $job->update(['status' => 'pending']);
            $this->release($e->getRetryAfter());
        }
    }

    /**
     * After a successful agent job, mark its WorkflowTask done and dispatch
     * any tasks that were waiting on it.
     */
    private function advanceWorkflowDag(AgentJob $job): void
    {
        $taskId = $job->metadata['workflow_task_id'] ?? null;
        if (! $taskId) {
            return;
        }

        WorkflowTask::where('id', $taskId)->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'output'       => ['result' => $job->result],
        ]);

        // Find tasks in the same workflow that depend on this task and are still pending
        $unblocked = WorkflowTask::where('workflow_id', $job->workflow_id)
            ->where('depends_on_task_id', $taskId)
            ->where('status', 'pending')
            ->get();

        if ($unblocked->isEmpty()) {
            $this->checkWorkflowCompletion($job->workflow_id);
            return;
        }

        $workflow    = Workflow::find($job->workflow_id);
        $orchestrator = app(AgentOrchestrator::class);

        foreach ($unblocked as $task) {
            $step = [
                'agent_type'  => $task->agent_type,
                'instruction' => $task->input['instruction'] ?? '',
            ];
            $orchestrator->dispatchWorkflowStep($workflow, $task->id, $step, $job->user_id, $job->chat_id);
        }
    }

    private function checkWorkflowCompletion(string $workflowId): void
    {
        $pending = WorkflowTask::where('workflow_id', $workflowId)
            ->whereNotIn('status', ['completed', 'failed'])
            ->count();

        if ($pending === 0) {
            Workflow::where('id', $workflowId)->update([
                'status'       => Workflow::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
            Log::info('Workflow DAG completed', ['workflow_id' => $workflowId]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("RunAgentJob failed", ['job_id' => $this->agentJobId, 'error' => $e->getMessage()]);
        AgentJob::where('id', $this->agentJobId)->update([
            'status'        => 'failed',
            'error_message' => substr($e->getMessage(), 0, 1000),
        ]);
    }
}
