<?php

namespace App\Jobs;

use App\AgentSystem\AgentRunner;
use App\Models\AgentTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that runs an AgentTask asynchronously.
 * Timeout: 10 minutes (to accommodate up to 10 LLM round-trips).
 */
class RunAgentTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;       // 10 minutes

    public int $tries = 1;         // do not auto-retry the whole job (AgentRunner handles step-level retries)

    public int $backoff = 0;

    public function __construct(public readonly AgentTask $task)
    {
        $this->onQueue('agents');
    }

    public function handle(AgentRunner $runner): void
    {
        $runner->run($this->task);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[RunAgentTask] Job failed for task {$this->task->id}: ".$exception->getMessage());

        $this->task->update([
            'status' => 'failed',
            'error_message' => 'Job worker error: '.$exception->getMessage(),
        ]);
    }
}
