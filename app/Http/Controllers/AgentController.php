<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgentTask;
use App\Models\AgentStep;
use App\Models\AgentTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * AgentController – handles all HTTP endpoints for the Master Agent System.
 *
 * Routes (defined in routes/agent.php):
 *   GET  /agent              → index()       renders Blade UI
 *   POST /agent/run          → run()         creates & queues a task
 *   GET  /agent/status/{id}  → status()      polls task progress
 *   POST /agent/pause/{id}   → pause()       pauses a running task
 *   POST /agent/resume/{id}  → resume()      resumes a paused task
 *   GET  /agent/tasks        → taskList()    list recent tasks
 *   POST /agent/update-api   → updateApi()   update AI credentials
 *   GET  /agent/config       → getConfig()   get current AI config
 */
class AgentController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // Page
    // ─────────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $recentTasks = AgentTask::latest()->limit(10)->get();
        return view('agent.index', compact('recentTasks'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Task lifecycle
    // ─────────────────────────────────────────────────────────────────────

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt'   => 'required|string|max:2000',
            'provider' => 'nullable|string|in:openai,gemini',
            'model'    => 'nullable|string|max:100',
        ]);

        $provider = $validated['provider'] ?? config('agent_system.default_provider', 'openai');

        // Validate that an API key exists for the chosen provider
        $apiKey = $provider === 'gemini'
            ? config('agent_system.gemini_api_key')
            : config('agent_system.openai_api_key');

        if (empty($apiKey) || str_contains($apiKey, 'CHANGE_ME')) {
            return response()->json([
                'error'          => 'API key not configured.',
                'api_needs_update' => true,
                'provider'       => $provider,
            ], 422);
        }

        $task = AgentTask::create([
            'user_input'  => $validated['prompt'],
            'status'      => 'pending',
            'ai_provider' => $provider,
            'model'       => $validated['model'] ?? null,
        ]);

        RunAgentTask::dispatch($task);

        return response()->json([
            'task_id' => $task->id,
            'status'  => 'queued',
            'message' => 'Task queued successfully. Poll /agent/status/' . $task->id . ' for progress.',
        ], 202);
    }

    public function status(int $id): JsonResponse
    {
        $task = AgentTask::with('steps')->findOrFail($id);

        return response()->json([
            'task_id'         => $task->id,
            'status'          => $task->status,
            'current_step'    => $task->current_step,
            'user_input'      => $task->user_input,
            'final_output'    => $task->final_output,
            'error_message'   => $task->error_message,
            'total_tokens'    => $task->total_tokens,
            'total_latency_ms'=> $task->total_latency_ms,
            'steps'           => $task->steps->map(fn(AgentStep $s) => [
                'id'          => $s->id,
                'step_number' => $s->step_number,
                'agent_name'  => $s->agent_name,
                'thought'     => $s->thought,
                'action'      => $s->action,
                'parameters'  => $s->parameters,
                'result'      => $s->result,
                'status'      => $s->status,
                'tokens_used' => $s->tokens_used,
                'latency_ms'  => $s->latency_ms,
                'retry_count' => $s->retry_count,
                'created_at'  => $s->created_at?->toIso8601String(),
            ]),
            'created_at'  => $task->created_at?->toIso8601String(),
            'updated_at'  => $task->updated_at?->toIso8601String(),
        ]);
    }

    public function pause(int $id): JsonResponse
    {
        $task = AgentTask::findOrFail($id);

        if ($task->status !== 'running') {
            return response()->json(['error' => 'Task is not currently running.'], 422);
        }

        $task->update(['status' => 'paused']);

        return response()->json(['message' => 'Task paused.', 'task_id' => $id]);
    }

    public function resume(int $id): JsonResponse
    {
        $task = AgentTask::findOrFail($id);

        if ($task->status !== 'paused') {
            return response()->json(['error' => 'Task is not paused.'], 422);
        }

        $task->update(['status' => 'running']);
        RunAgentTask::dispatch($task);

        return response()->json(['message' => 'Task resumed.', 'task_id' => $id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Task listing
    // ─────────────────────────────────────────────────────────────────────

    public function taskList(): JsonResponse
    {
        $tasks = AgentTask::latest()
            ->limit(20)
            ->get(['id', 'user_input', 'status', 'current_step', 'ai_provider', 'total_tokens', 'created_at', 'updated_at']);

        return response()->json(['tasks' => $tasks]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // API key management
    // ─────────────────────────────────────────────────────────────────────

    public function updateApi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key'  => 'required|string|min:10',
            'provider' => 'nullable|string|in:openai,gemini',
            'model'    => 'nullable|string|max:100',
        ]);

        $provider = $validated['provider'] ?? 'openai';

        // Write to .env file (safe for local dev)
        $this->writeEnvValue(
            $provider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY',
            $validated['api_key']
        );

        if (! empty($validated['model'])) {
            $this->writeEnvValue(
                $provider === 'gemini' ? 'AGENT_GEMINI_MODEL' : 'AGENT_OPENAI_MODEL',
                $validated['model']
            );
        }

        // Clear config cache so new values take effect
        \Artisan::call('config:clear');

        return response()->json([
            'message'  => 'API key updated successfully.',
            'provider' => $provider,
        ]);
    }

    public function getConfig(): JsonResponse
    {
        $openaiKey = config('agent_system.openai_api_key', '');
        $geminiKey = config('agent_system.gemini_api_key', '');

        return response()->json([
            'openai_configured' => ! empty($openaiKey) && ! str_contains($openaiKey, 'CHANGE_ME'),
            'gemini_configured' => ! empty($geminiKey) && ! str_contains($geminiKey, 'CHANGE_ME'),
            'default_provider'  => config('agent_system.default_provider', 'openai'),
            'openai_model'      => config('agent_system.openai_model', 'gpt-4o-mini'),
            'gemini_model'      => config('agent_system.gemini_model', 'gemini-1.5-flash'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    private function writeEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);

        // Escape special regex chars in value
        $safeValue = str_replace(['\\', '/'], ['\\\\', '\\/'], $value);

        if (preg_match("/^{$key}=.*/m", $content)) {
            $content = preg_replace("/^{$key}=.*/m", "{$key}={$safeValue}", $content);
        } else {
            $content .= "\n{$key}={$safeValue}";
        }

        file_put_contents($envPath, $content);
    }
}
