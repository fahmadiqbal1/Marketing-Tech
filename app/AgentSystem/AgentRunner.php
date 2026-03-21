<?php

namespace App\AgentSystem;

use App\AgentSystem\Agents\ContentAgent;
use App\AgentSystem\Agents\KeywordAgent;
use App\AgentSystem\Agents\MasterAgent;
use App\AgentSystem\Gateway\AIGateway;
use App\AgentSystem\Tools\ToolRegistry;
use App\Models\AgentTask;
use App\Services\AI\CostCalculatorService;
use App\Services\ApiCredentialService;
use App\Services\MemoryService;
use Illuminate\Support\Facades\Log;

/**
 * AgentRunner – wires together all dependencies and launches the MasterAgent.
 * Called from the queued RunAgentTask job.
 */
class AgentRunner
{
    public function __construct(
        private readonly ?CostCalculatorService $costCalc          = null,
        private readonly ?ApiCredentialService  $credentialService = null,
        private readonly ?MemoryService         $memory            = null,
    ) {}

    public function run(AgentTask $task): void
    {
        // DEPRECATED: AgentRunner uses the legacy AgentTask/agent_tasks system.
        // New tasks should be dispatched via AgentOrchestrator → RunAgentJob → BaseAgent.
        // This runner remains for any in-flight legacy tasks in the queue.
        Log::warning('[AgentRunner] DEPRECATED legacy agent runner executing task', [
            'task_id'  => $task->id,
            'provider' => $task->ai_provider,
            'notice'   => 'Migrate callers to AgentOrchestrator::dispatch() / RunAgentJob',
        ]);

        Log::info("[AgentRunner] Starting task {$task->id}: {$task->user_input}");

        try {
            $gateway = new AIGateway(
                provider:          $task->ai_provider,
                model:             $task->model ?: null,
                costCalc:          $this->costCalc,
                credentialService: $this->credentialService,
            );

            // Link all ai_requests logged by this gateway to this task
            $gateway->setContext($task->id);

            $toolRegistry = new ToolRegistry($gateway);
            $contentAgent = new ContentAgent($gateway, $toolRegistry);
            $keywordAgent = new KeywordAgent($gateway, $toolRegistry);

            $master = new MasterAgent(
                task:         $task,
                gateway:      $gateway,
                toolRegistry: $toolRegistry,
                contentAgent: $contentAgent,
                keywordAgent: $keywordAgent,
                memory:       $this->memory,
            );

            $master->execute();

            Log::info("[AgentRunner] Task {$task->id} finished with status: {$task->fresh()->status}");

        } catch (\Throwable $e) {
            Log::error("[AgentRunner] Task {$task->id} crashed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $task->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
