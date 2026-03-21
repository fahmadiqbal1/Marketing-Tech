<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgentJob;
use App\Models\AgentJob;
use App\Models\AgentStep;
use App\Services\ApiCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * AgentController – thin HTTP layer for the Master Agent System.
 *
 * All task operations now use the modern AgentJob / RunAgentJob pipeline.
 *
 * Routes (routes/agent.php):
 *   GET  /agent              → index()
 *   POST /agent/run          → run()        (throttled + CheckAgentToken)
 *   GET  /agent/status/{id}  → status()     (UUID)
 *   POST /agent/pause/{id}   → pause()      (UUID)
 *   POST /agent/resume/{id}  → resume()     (UUID)
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
        $recentTasks = AgentJob::latest()->limit(10)->get();
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
            'type'            => 'nullable|string|in:marketing,content,media,hiring,growth,knowledge',
            'idempotency_key' => 'nullable|string|max:64',
            'campaign_id'     => 'nullable|uuid',
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
        $envKey = match ($provider) {
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

        // ── Determine agent type and class ────────────────────────────
        $agentType = $validated['type'] ?? 'content';
        $agentDefs = config('agents.agents', []);

        if (! isset($agentDefs[$agentType])) {
            $agentType = 'content';
        }

        $agentClass = $agentDefs[$agentType]['class'];
        $queue      = $agentDefs[$agentType]['queue'] ?? $agentType;

        // ── Create job ────────────────────────────────────────────────
        $job = AgentJob::create([
            'agent_type'        => $agentType,
            'agent_class'       => $agentClass,
            'ai_provider'       => $provider,
            'model'             => $validated['model'] ?? null,
            'instruction'       => $validated['prompt'],
            'short_description' => Str::limit($validated['prompt'], 80),
            'status'            => 'pending',
            'campaign_id'       => $validated['campaign_id'] ?? null,
            'metadata'          => [],
        ]);

        RunAgentJob::dispatch($job->id)->onQueue($queue);

        // Store idempotency key → job mapping (1 hour)
        if (! empty($validated['idempotency_key'])) {
            Cache::put('agent:idem:' . $validated['idempotency_key'], $job->id, 3600);
        }

        return response()->json([
            'task_id' => $job->id,
            'status'  => 'queued',
            'message' => 'Task queued. Poll /agent/status/' . $job->id . ' for progress.',
        ], 202);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $afterStep = (int) $request->query('after_step', -1);

        $job = AgentJob::findOrFail($id);

        $stepsQuery = $job->agentSteps();
        if ($afterStep >= 0) {
            $stepsQuery->where('step_number', '>', $afterStep);
        }
        $steps = $stepsQuery->get();

        return response()->json([
            'task_id'          => $job->id,
            'status'           => $job->status,
            'campaign_id'      => $job->campaign_id,
            // backward-compat field names expected by the frontend
            'current_step'     => $job->steps_taken,
            'user_input'       => $job->instruction,
            'final_output'     => $this->parseFinalOutput($job->result),
            'error_message'    => $job->error_message,
            'total_tokens'     => $job->total_tokens ?? 0,
            'total_latency_ms' => null,
            'ai_provider'      => $job->ai_provider,
            'steps'            => $steps->map(fn(AgentStep $s) => [
                'id'                    => $s->id,
                'step_number'           => $s->step_number,
                'agent_name'            => $s->agent_name,
                'thought'               => $s->thought,
                'action'                => $s->action,
                'parameters'            => $s->parameters,
                'result'                => $s->result,
                'knowledge_chunks_used' => $s->knowledge_chunks_used,
                'knowledge_scores'      => $s->knowledge_scores,
                'from_cache'            => $s->from_cache,
                'tool_success'          => $s->tool_success,
                'tool_error'            => $s->tool_error,
                'status'                => $s->status,
                'tokens_used'           => $s->tokens_used,
                'latency_ms'            => $s->latency_ms,
                'retry_count'           => $s->retry_count ?? 0,
                'created_at'            => $s->created_at?->toIso8601String(),
            ]),
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ]);
    }

    public function pause(string $id): JsonResponse
    {
        $job = AgentJob::findOrFail($id);

        if ($job->status !== 'running') {
            return response()->json(['error' => 'Task is not currently running.'], 422);
        }

        $job->update(['status' => 'paused']);

        return response()->json(['message' => 'Task paused.', 'task_id' => $id]);
    }

    public function resume(string $id): JsonResponse
    {
        $job = AgentJob::findOrFail($id);

        if ($job->status !== 'paused') {
            return response()->json(['error' => 'Task is not paused.'], 422);
        }

        $queue = config("agents.agents.{$job->agent_type}.queue", 'default');
        $job->update(['status' => 'pending']);
        RunAgentJob::dispatch($job->id)->onQueue($queue);

        return response()->json(['message' => 'Task resumed.', 'task_id' => $id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Task listing
    // ─────────────────────────────────────────────────────────────────────

    public function taskList(): JsonResponse
    {
        $jobs = AgentJob::latest()
            ->limit(20)
            ->get(['id', 'instruction', 'status', 'steps_taken', 'ai_provider', 'total_tokens', 'created_at', 'updated_at']);

        // Map to backward-compatible field names the frontend expects
        $tasks = $jobs->map(fn($j) => [
            'id'           => $j->id,
            'user_input'   => $j->instruction,
            'status'       => $j->status,
            'current_step' => $j->steps_taken,
            'ai_provider'  => $j->ai_provider,
            'total_tokens' => $j->total_tokens,
            'created_at'   => $j->created_at?->toIso8601String(),
            'updated_at'   => $j->updated_at?->toIso8601String(),
        ]);

        return response()->json(['tasks' => $tasks]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // API key management
    // ─────────────────────────────────────────────────────────────────────

    public function updateApi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key'  => 'required|string|min:10',
            'provider' => 'nullable|string|in:openai,gemini,anthropic',
            'model'    => 'nullable|string|max:100',
        ]);

        $provider = $validated['provider'] ?? 'openai';
        $envKey   = match ($provider) {
            'gemini'    => 'GEMINI_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            default     => 'OPENAI_API_KEY',
        };

        $this->credentials->store($provider, $envKey, $validated['api_key']);

        if (! empty($validated['model'])) {
            $modelEnvKey = match ($provider) {
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
            'openai_model'         => $this->credentials->retrieve('OPENAI_DEFAULT_MODEL') ?? config('agents.openai.default_model', 'gpt-4o'),
            'gemini_model'         => $this->credentials->retrieve('GEMINI_DEFAULT_MODEL') ?? config('agents.gemini.default_model', 'gemini-2.0-flash'),
            'anthropic_model'      => $this->credentials->retrieve('ANTHROPIC_DEFAULT_MODEL') ?? config('agents.anthropic.default_model', 'claude-opus-4-5'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function parseFinalOutput(?string $result): mixed
    {
        if ($result === null) {
            return null;
        }
        $decoded = json_decode($result, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $result;
    }
}
