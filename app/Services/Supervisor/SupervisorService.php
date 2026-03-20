<?php

namespace App\Services\Supervisor;

use App\Models\Workflow;
use App\Models\AgentJob;
use App\Models\SystemEvent;
use App\Services\Telegram\TelegramBotService;
use App\Workflows\WorkflowDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SupervisorService
{
    // Thresholds
    private int $stuckWorkflowMinutes = 30;  // workflows stuck in non-terminal state
    private int $stuckJobMinutes      = 15;  // agent jobs stuck running
    private int $maxRetryAttempts     = 3;

    public function __construct(
        private readonly TelegramBotService  $telegram,
        private readonly WorkflowDispatcher  $dispatcher,
    ) {}

    /**
     * Main supervisor tick — run every minute from scheduler.
     */
    public function tick(): void
    {
        $this->detectStuckWorkflows();
        $this->detectStuckAgentJobs();
        $this->processDeadLetterQueue();
        $this->sendPendingAlerts();
        $this->updateHealthMetrics();
    }

    /**
     * Called when a workflow transitions to FAILED.
     */
    public function handleWorkflowFailure(Workflow $workflow, string $error): void
    {
        $this->emitEvent('workflow_failed', 'error', 'Workflow', $workflow->id, $error, [
            'workflow_type' => $workflow->type,
            'retry_count'   => $workflow->retry_count,
            'max_retries'   => $workflow->max_retries,
        ]);

        // Auto-retry if within limits
        if ($workflow->retry_count < $workflow->max_retries) {
            $retryIn = pow(2, $workflow->retry_count) * 30; // 30s, 60s, 120s
            Log::info("Scheduling workflow retry", [
                'id'      => $workflow->id,
                'attempt' => $workflow->retry_count + 1,
                'in_s'    => $retryIn,
            ]);

            dispatch(function () use ($workflow) {
                app(WorkflowDispatcher::class)->retry($workflow->id);
            })->delay(now()->addSeconds($retryIn));
        } else {
            // Move to dead-letter queue
            $this->deadLetter('workflow', $workflow->id, $error, $workflow->input_payload ?? []);

            // Notify admin
            $this->alertAdmin(
                "💀 Workflow permanently failed",
                "ID: `{$workflow->id}`\nName: {$workflow->name}\nError: " . substr($error, 0, 200),
                $workflow->chat_id
            );
        }
    }

    /**
     * Detect workflows stuck in non-terminal states.
     */
    private function detectStuckWorkflows(): void
    {
        $nonTerminal = [
            Workflow::STATUS_INTAKE,
            Workflow::STATUS_CONTEXT_RETRIEVAL,
            Workflow::STATUS_PLANNING,
            Workflow::STATUS_TASK_EXECUTION,
            Workflow::STATUS_REVIEW,
            Workflow::STATUS_EXECUTION,
            Workflow::STATUS_OBSERVATION,
            Workflow::STATUS_LEARNING,
        ];

        $stuck = Workflow::whereIn('status', $nonTerminal)
            ->where('updated_at', '<', now()->subMinutes($this->stuckWorkflowMinutes))
            ->whereNull('deleted_at')
            ->get();

        foreach ($stuck as $workflow) {
            Log::warning("Stuck workflow detected", [
                'id'     => $workflow->id,
                'status' => $workflow->status,
                'age_m'  => now()->diffInMinutes($workflow->updated_at),
            ]);

            $this->emitEvent(
                'workflow_stuck', 'warning', 'Workflow', $workflow->id,
                "Workflow stuck in {$workflow->status} for " . now()->diffInMinutes($workflow->updated_at) . " minutes",
                ['status' => $workflow->status]
            );

            // Re-dispatch if retry budget allows
            if ($workflow->retry_count < $workflow->max_retries) {
                $workflow->update(['status' => Workflow::STATUS_FAILED, 'error_message' => 'Stuck - auto-recovered']);
                app(WorkflowDispatcher::class)->retry($workflow->id);
            }
        }
    }

    /**
     * Detect agent jobs stuck in 'running' state.
     */
    private function detectStuckAgentJobs(): void
    {
        $stuck = AgentJob::where('status', 'running')
            ->where('updated_at', '<', now()->subMinutes($this->stuckJobMinutes))
            ->get();

        foreach ($stuck as $job) {
            Log::warning("Stuck agent job", ['id' => $job->id, 'type' => $job->agent_type]);

            $job->update([
                'status'        => 'failed',
                'error_message' => 'Timed out — stuck in running state',
            ]);

            $this->emitEvent(
                'agent_job_stuck', 'warning', 'AgentJob', $job->id,
                "Agent job stuck: {$job->agent_type}", []
            );
        }
    }

    /**
     * Process items in the dead-letter queue — notify admin.
     */
    private function processDeadLetterQueue(): void
    {
        $key   = 'ops:dead_letter_queue';
        $items = [];

        // Pop up to 10 items from the dead-letter list
        for ($i = 0; $i < 10; $i++) {
            $item = Cache::store('redis')->get($key . ':' . $i);
            if ($item) {
                $items[] = json_decode($item, true);
                Cache::store('redis')->forget($key . ':' . $i);
            }
        }

        foreach ($items as $item) {
            $this->alertAdmin(
                "🪦 Dead-letter item",
                "Type: {$item['entity_type']}\nID: {$item['entity_id']}\nError: " . substr($item['error'], 0, 150)
            );
        }
    }

    /**
     * Send pending Telegram notifications for error events.
     */
    private function sendPendingAlerts(): void
    {
        $pendingEvents = SystemEvent::where('severity', 'error')
            ->where('notified', false)
            ->where('occurred_at', '<', now()->subMinutes(2)) // slight delay to batch
            ->where('chat_id', '!=', null)
            ->limit(5)
            ->get();

        foreach ($pendingEvents as $event) {
            try {
                $this->telegram->sendMessage(
                    (int) $event->chat_id,
                    "⚠️ *System Event*\n`{$event->event_type}`\n{$event->message}"
                );
                $event->update(['notified' => true]);
            } catch (\Throwable $e) {
                Log::warning("Failed to send alert notification", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Update system health metrics in Redis for monitoring dashboard.
     */
    private function updateHealthMetrics(): void
    {
        try {
            $metrics = [
                'workflows_running'  => Workflow::whereIn('status', [Workflow::STATUS_TASK_EXECUTION, Workflow::STATUS_EXECUTION])->count(),
                'workflows_pending'  => Workflow::where('status', Workflow::STATUS_INTAKE)->count(),
                'workflows_failed_24h' => Workflow::where('status', Workflow::STATUS_FAILED)->where('updated_at', '>=', now()->subDay())->count(),
                'agent_jobs_running' => AgentJob::where('status', 'running')->count(),
                'updated_at'         => now()->toIso8601String(),
            ];

            Cache::put('ops:health_metrics', $metrics, now()->addMinutes(5));
        } catch (\Throwable $e) {
            Log::warning("Health metrics update failed", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send an alert to the admin Telegram chat.
     */
    private function alertAdmin(string $title, string $body, ?int $userChatId = null): void
    {
        $adminChatId = (int) config('agents.telegram.admin_chat_id');

        $message = "*{$title}*\n{$body}";

        if ($adminChatId) {
            try {
                $this->telegram->sendMessage($adminChatId, $message);
            } catch (\Throwable $e) {
                Log::error("Failed to alert admin", ['error' => $e->getMessage()]);
            }
        }

        if ($userChatId && $userChatId !== $adminChatId) {
            try {
                $this->telegram->sendMessage($userChatId, $message);
            } catch (\Throwable $e) {
                // Silent — user chat may be stale
            }
        }
    }

    /**
     * Push an item to the dead-letter queue.
     */
    private function deadLetter(string $entityType, string $entityId, string $error, array $payload): void
    {
        $item = json_encode([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'error'       => $error,
            'payload'     => $payload,
            'at'          => now()->toIso8601String(),
        ]);

        $key = 'ops:dead_letter_queue:' . now()->timestamp;
        Cache::put($key, $item, now()->addDays(7));

        Log::error("Item moved to dead-letter queue", ['type' => $entityType, 'id' => $entityId]);
    }

    /**
     * Emit a system event record.
     */
    private function emitEvent(
        string  $type,
        string  $severity,
        string  $entityType,
        string  $entityId,
        string  $message,
        array   $payload,
        ?int    $chatId = null,
    ): void {
        try {
            SystemEvent::create([
                'event_type'  => $type,
                'severity'    => $severity,
                'source'      => 'supervisor',
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'message'     => $message,
                'payload'     => $payload,
                'chat_id'     => $chatId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to emit system event", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Return current health status summary.
     */
    public function getHealthStatus(): array
    {
        return Cache::get('ops:health_metrics', [
            'workflows_running'    => 0,
            'workflows_pending'    => 0,
            'workflows_failed_24h' => 0,
            'agent_jobs_running'   => 0,
            'updated_at'           => null,
        ]);
    }
}
