<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Workflows\WorkflowStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;  // State machine handles its own retries
    public int $timeout = 600;

    public function __construct(
        public readonly string $workflowId
    ) {}

    public function handle(WorkflowStateMachine $stateMachine): void
    {
        $workflow = Workflow::find($this->workflowId);

        if (! $workflow) {
            Log::warning("ProcessWorkflowJob: workflow not found", ['id' => $this->workflowId]);
            return;
        }

        if ($workflow->isTerminal()) {
            Log::debug("ProcessWorkflowJob: workflow already terminal", ['id' => $this->workflowId, 'status' => $workflow->status]);
            return;
        }

        if ($workflow->status === Workflow::STATUS_OWNER_APPROVAL) {
            Log::debug("ProcessWorkflowJob: workflow awaiting approval", ['id' => $this->workflowId]);
            return;
        }

        $stateMachine->advance($workflow);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ProcessWorkflowJob itself failed", [
            'workflow_id' => $this->workflowId,
            'error'       => $e->getMessage(),
        ]);

        $workflow = Workflow::find($this->workflowId);
        $workflow?->transitionTo(Workflow::STATUS_FAILED, $e->getMessage());
    }
}
