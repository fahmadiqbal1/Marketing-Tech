<?php

namespace App\AgentSystem;

use App\AgentSystem\Agents\ContentAgent;
use App\AgentSystem\Agents\KeywordAgent;
use App\AgentSystem\Agents\MasterAgent;
use App\AgentSystem\Gateway\AIGateway;
use App\AgentSystem\Tools\ToolRegistry;
use App\Models\AgentTask;
use Illuminate\Support\Facades\Log;

/**
 * AgentRunner – wires together all dependencies and launches the MasterAgent.
 * Called from the queued job OR directly for synchronous execution.
 */
class AgentRunner
{
    public function run(AgentTask $task): void
    {
        Log::info("[AgentRunner] Starting task {$task->id}: {$task->user_input}");

        try {
            $gateway      = new AIGateway($task->ai_provider, model: $task->model ?: null);
            $toolRegistry = new ToolRegistry($gateway);
            $contentAgent = new ContentAgent($gateway, $toolRegistry);
            $keywordAgent = new KeywordAgent($gateway, $toolRegistry);

            $master = new MasterAgent(
                task:         $task,
                gateway:      $gateway,
                toolRegistry: $toolRegistry,
                contentAgent: $contentAgent,
                keywordAgent: $keywordAgent,
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
