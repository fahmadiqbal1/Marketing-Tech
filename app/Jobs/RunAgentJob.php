<?php
namespace App\Jobs;

use App\Models\AgentJob;
use App\Agents\AgentOrchestrator;
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

        /** @var \App\Agents\BaseAgent $agent */
        $agent = app($agentClass);
        $agent->run($job);
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
