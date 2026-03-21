<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgentTask;
use App\Models\AgentStep;
use App\Models\AgentTask;
use App\Services\ApiCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * AgentController – thin HTTP layer for the Master Agent System.
 *
 * Routes (routes/agent.php):
 *   GET  /agent              → index()
 *   POST /agent/run          → run()        (throttled + CheckAgentToken)
 *   GET  /agent/status/{id}  → status()
 *   POST /agent/pause/{id}   → pause()
 *   POST /agent/resume/{id}  → resume()
 *   GET  /agent/tasks        → taskList()
 *   POST /agent/update-api   → updateApi()
 *   GET  /agent/config       → getConfig()
 */
class AgentController extends Controller
{
    public function __construct(private readonly ApiCredentialService $credentials) {}

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
            'prompt'          => 'required|string|max:2000',
            'provider'        => 'nullable|string|in:openai,anthropic,gemini',
            'model'           => 'nullable|string|max:100',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $provider = $validated['provider'] ?? config('agent_system.default_provider', 'openai');

        // ── Idempotency check ─────────────────────────────────────────
        if (! empty($validated['idempotency_key'])) {
            $cacheKey = 'agent:idem:' . $validated['idempotency_key'];
            $existing = Cache::get($cacheKey);
            if ($existing) {
                return response()->json([
                    'task_id' => $existing,
                    'status'  => 'existing',
                    'message' => 'Idempotency key matched existing task.',
                ], 200);
            }
        }

        // ── Validate API key exists ───────────────────────────────────
        $envKey = match($provider) {
            'gemini'    => 'GEMINI_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            default     => 'OPENAI_API_KEY',
        };
        $apiKey = $this->credentials->retrieve($envKey);

        if (empty($apiKey) || str_contains($apiKey, 'CHANGE_ME')) {
            return response()->json([
                'error'            => 'API key not configured.',
                'api_needs_update' => true,
                'provider'         => $provider,
            ], 422);
        }

        // ── Create task ───────────────────────────────────────────────
        $task = AgentTask::create([
            'user_input'  => $validated['prompt'],
            'status'      => 'pending',
            'ai_provider' => $provider,
            'model'       => $validated['model'] ?? null,
        ]);

        RunAgentTask::dispatch($task);

        // Store idempotency key → task mapping (1 hour)
        if (! empty($validated['idempotency_key'])) {
            Cache::put('agent:idem:' . $validated['idempotency_key'], $task->id, 3600);
        }

        return response()->json([
            'task_id' => $task->id,
            'status'  => 'queued',
            'message' => 'Task queued. Poll /agent/status/' . $task->id . ' for progress.',
        ], 202);
    }

    public function status(int $id): JsonResponse
    {
        $task = AgentTask::with('steps')->findOrFail($id);

        return response()->json([
            'task_id'          => $task->id,
            'status'           => $task->status,
            'current_step'     => $task->current_step,
            'user_input'       => $task->user_input,
            'final_output'     => $task->final_output,
            'error_message'    => $task->error_message,
            'total_tokens'     => $task->total_tokens,
            'total_latency_ms' => $task->total_latency_ms,
            'steps'            => $task->steps->map(fn (AgentStep $s) => [
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
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
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
    // API key management (no .env writes — uses encrypted DB store)
    // ─────────────────────────────────────────────────────────────────────

    public function updateApi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key'  => 'required|string|min:10',
            'provider' => 'nullable|string|in:openai,gemini,anthropic',
            'model'    => 'nullable|string|max:100',
        ]);

        $provider = $validated['provider'] ?? 'openai';
        $envKey   = match($provider) {
            'gemini'    => 'GEMINI_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            default     => 'OPENAI_API_KEY',
        };

        $this->credentials->store($provider, $envKey, $validated['api_key']);

        if (! empty($validated['model'])) {
            // Use the same key names as savePlatform() so both endpoints are consistent
            $modelEnvKey = match($provider) {
                'gemini'    => 'GEMINI_DEFAULT_MODEL',
                'anthropic' => 'ANTHROPIC_DEFAULT_MODEL',
                default     => 'OPENAI_DEFAULT_MODEL',
            };
            $this->credentials->store($provider, $modelEnvKey, $validated['model']);
        }

        return response()->json([
            'message'  => 'API key stored securely.',
            'provider' => $provider,
        ]);
    }

    public function getConfig(): JsonResponse
    {
        $openaiKey    = $this->credentials->retrieve('OPENAI_API_KEY') ?? '';
        $geminiKey    = $this->credentials->retrieve('GEMINI_API_KEY') ?? '';
        $anthropicKey = $this->credentials->retrieve('ANTHROPIC_API_KEY') ?? '';

        return response()->json([
            'openai_configured'    => ! empty($openaiKey) && ! str_contains($openaiKey, 'CHANGE_ME'),
            'gemini_configured'    => ! empty($geminiKey) && ! str_contains($geminiKey, 'CHANGE_ME'),
            'anthropic_configured' => ! empty($anthropicKey) && ! str_contains($anthropicKey, 'CHANGE_ME'),
            'default_provider'     => config('agent_system.default_provider', 'openai'),
            'openai_model'         => config('agent_system.openai_model', 'gpt-4o-mini'),
            'gemini_model'         => config('agent_system.gemini_model', 'gemini-1.5-flash'),
            'anthropic_model'      => config('agents.anthropic.default_model', 'claude-opus-4-5'),
        ]);
    }
}
