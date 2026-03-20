<?php

namespace App\Workflows;

use App\Models\Workflow;
use App\Jobs\ProcessWorkflowJob;
use App\Services\Knowledge\VectorStoreService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class WorkflowDispatcher
{
    public function __construct(
        private readonly VectorStoreService  $knowledge,
        private readonly TelegramBotService  $telegram,
    ) {}

    /**
     * Create and queue a new workflow from a Telegram instruction.
     */
    public function dispatch(
        string $type,
        string $name,
        array  $inputPayload,
        int    $chatId,
        int    $userId,
        int    $priority = 5,
        bool   $requiresApproval = false,
    ): Workflow {
        $workflow = Workflow::create([
            'id'               => (string) Str::uuid(),
            'name'             => $name,
            'type'             => $type,
            'status'           => Workflow::STATUS_INTAKE,
            'input_payload'    => $inputPayload,
            'chat_id'          => $chatId,
            'user_id'          => $userId,
            'priority'         => $priority,
            'requires_approval' => $requiresApproval,
            'metadata'         => ['dispatched_at' => now()->toIso8601String()],
        ]);

        Log::info('Workflow dispatched', ['id' => $workflow->id, 'type' => $type]);

        $this->telegram->sendMessage(
            $chatId,
            "⚡ Workflow `{$workflow->id}` started\n*{$name}*\nStatus: `{$workflow->status}`"
        );

        ProcessWorkflowJob::dispatch($workflow->id)
            ->onQueue($this->resolveQueue($type))
            ->delay(now()->addSeconds(1));

        return $workflow;
    }

    /**
     * Re-queue a failed workflow for retry.
     */
    public function retry(string $workflowId): bool
    {
        $workflow = Workflow::findOrFail($workflowId);

        if ($workflow->retry_count >= $workflow->max_retries) {
            Log::warning("Workflow max retries exceeded", ['id' => $workflowId]);
            return false;
        }

        if (! $workflow->transitionTo(Workflow::STATUS_INTAKE)) {
            return false;
        }

        $workflow->increment('retry_count');
        $workflow->update(['error_message' => null]);

        ProcessWorkflowJob::dispatch($workflow->id)
            ->onQueue($this->resolveQueue($workflow->type))
            ->delay(now()->addSeconds(pow(2, $workflow->retry_count) * 10)); // exponential backoff

        return true;
    }

    /**
     * Approve a workflow waiting for owner sign-off.
     */
    public function approve(string $workflowId, string $approvedBy): bool
    {
        $workflow = Workflow::findOrFail($workflowId);

        if ($workflow->status !== Workflow::STATUS_OWNER_APPROVAL) {
            return false;
        }

        $workflow->update([
            'approved_at'      => now(),
            'approved_by'      => $approvedBy,
            'approval_granted' => true,
        ]);

        $workflow->transitionTo(Workflow::STATUS_EXECUTION);

        ProcessWorkflowJob::dispatch($workflow->id)
            ->onQueue($this->resolveQueue($workflow->type));

        return true;
    }

    /**
     * Cancel a workflow.
     */
    public function cancel(string $workflowId): bool
    {
        $workflow = Workflow::findOrFail($workflowId);
        if ($workflow->isTerminal()) {
            return false;
        }
        return $workflow->transitionTo(Workflow::STATUS_CANCELLED);
    }

    private function resolveQueue(string $type): string
    {
        return match ($type) {
            'marketing' => 'marketing',
            'hiring'    => 'hiring',
            'media'     => 'media',
            'content'   => 'content',
            'growth'    => 'growth',
            default     => 'default',
        };
    }
}
