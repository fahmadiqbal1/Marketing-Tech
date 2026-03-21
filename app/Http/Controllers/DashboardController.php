<?php

namespace App\Http\Controllers;

use App\Models\AgentJob;
use App\Models\AgentStep;
use App\Models\AgentTask;
use App\Models\KnowledgeBase;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\AI\OpenAIService;
use App\Services\ApiCredentialService;
use App\Services\Dashboard\DashboardStatsService;
use App\Services\Knowledge\VectorStoreService;
use App\Workflows\WorkflowDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * DashboardController – thin controller.
 * All query logic lives in DashboardStatsService.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $stats,
        private readonly ApiCredentialService $credentials,
    ) {}

    // ── Page renders ──────────────────────────────────────────────────

    public function overview()   { return view('overview'); }
    public function workflows()  { return view('workflows'); }
    public function jobs()       { return view('jobs'); }
    public function campaigns()  { return view('campaigns'); }
    public function candidates() { return view('candidates'); }
    public function content()    { return view('content'); }
    public function system()     { return view('system'); }
    public function pipeline()   { return view('pipeline', ['agents' => config('agents.agents', [])]); }
    public function knowledge()  { return view('knowledge'); }

    // ── API: Overview stats ───────────────────────────────────────────

    public function apiStats(): JsonResponse
    {
        return response()->json($this->stats->getStats());
    }

    // ── API: Workflows ────────────────────────────────────────────────

    public function apiWorkflows(Request $request): JsonResponse
    {
        $workflows = $this->stats->getWorkflows($request->only(['status', 'type', 'search']));
        return response()->json($workflows);
    }

    public function apiWorkflowDetail(string $id): JsonResponse
    {
        return response()->json($this->stats->getWorkflowDetail($id));
    }

    public function apiWorkflowApprove(string $id): JsonResponse
    {
        $approved = app(WorkflowDispatcher::class)->approve($id, 'dashboard');
        return response()->json(['approved' => $approved]);
    }

    public function apiWorkflowCancel(string $id): JsonResponse
    {
        $cancelled = app(WorkflowDispatcher::class)->cancel($id);
        return response()->json(['cancelled' => $cancelled]);
    }

    public function apiWorkflowRetry(string $id): JsonResponse
    {
        $retried = app(WorkflowDispatcher::class)->retry($id);
        return response()->json(['retried' => $retried]);
    }

    // ── API: Agent Jobs ───────────────────────────────────────────────

    public function apiJobs(): JsonResponse
    {
        return response()->json($this->stats->getJobs());
    }

    // ── API: Campaigns ────────────────────────────────────────────────

    public function apiCampaigns(): JsonResponse
    {
        return response()->json($this->stats->getCampaigns());
    }

    // ── API: Candidates ───────────────────────────────────────────────

    public function apiCandidates(): JsonResponse
    {
        return response()->json($this->stats->getCandidates());
    }

    // ── API: Content ──────────────────────────────────────────────────

    public function apiContent(Request $request): JsonResponse
    {
        $items = $this->stats->getContent($request->only(['type', 'status']));
        return response()->json($items);
    }

    // ── API: System Events ────────────────────────────────────────────

    public function apiSystemEvents(Request $request): JsonResponse
    {
        $events = $this->stats->getSystemEvents($request->only(['level']));
        return response()->json($events);
    }

    // ── API: AI Costs ─────────────────────────────────────────────────

    public function apiAiCosts(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 7);
        return response()->json($this->stats->getAiCosts($days));
    }

    // ── API: Pipeline ─────────────────────────────────────────────────

    public function apiPipeline(): JsonResponse
    {
        $agentDefs = config('agents.agents', []);

        // Recent steps across all agents (last 60)
        $recentSteps = AgentStep::latest()
            ->limit(60)
            ->get(['id', 'task_id', 'agent_job_id', 'agent_name', 'action', 'thought', 'status', 'tokens_used', 'latency_ms', 'knowledge_chunks_used', 'from_cache', 'created_at'])
            ->toArray();

        // Group steps by agent name
        $stepsByAgent = collect($recentSteps)->groupBy('agent_name');

        // Running job counts — check both legacy AgentTask and modern AgentJob
        $runningLegacy = AgentTask::where('status', 'running')->count();
        $runningModern = AgentJob::where('status', 'running')->count();
        $totalRunning  = $runningLegacy + $runningModern;

        // Per-agent running counts from modern system (agent_type matches config key)
        $runningPerAgent = AgentJob::where('status', 'running')
            ->selectRaw('agent_type, count(*) as cnt')
            ->groupBy('agent_type')
            ->pluck('cnt', 'agent_type');

        $agents = [];
        foreach ($agentDefs as $name => $def) {
            $promptKey    = 'AGENT_' . strtoupper($name) . '_PROMPT';
            $customPrompt = $this->credentials->retrieve($promptKey);
            $agents[]     = [
                'name'          => $name,
                'provider'      => $def['provider'] ?? 'openai',
                'model'         => $def['model'] ?? 'gpt-4o',
                'queue'         => $def['queue'] ?? $name,
                'max_steps'     => $def['max_steps'] ?? 15,
                'tools'         => $def['tools'] ?? [],
                'system_prompt' => $customPrompt ?? ($def['system_prompt'] ?? ''),
                'recent_steps'  => $stepsByAgent->get($name, collect())->take(5)->values()->toArray(),
                'active_jobs'   => (int) ($runningPerAgent[$name] ?? 0),
            ];
        }

        return response()->json([
            'agents'            => $agents,
            'total_running'     => $totalRunning,
            'total_steps_today' => AgentStep::whereDate('created_at', today())->count(),
        ]);
    }

    // ── API: Knowledge ────────────────────────────────────────────────

    public function apiKnowledge(Request $request): JsonResponse
    {
        $query = KnowledgeBase::whereNull('parent_id') // top-level entries only
            ->select(['id', 'title', 'category', 'tags', 'chunk_index', 'access_count', 'last_accessed_at', 'created_at']);

        if ($search = $request->query('search')) {
            $query->where('title', 'ilike', "%{$search}%")
                  ->orWhere('content', 'ilike', "%{$search}%");
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $paginated = $query->latest()->paginate(20);

        // Stats
        $totalEntries = KnowledgeBase::whereNull('parent_id')->count();
        $totalChunks  = KnowledgeBase::whereNotNull('parent_id')->count() + $totalEntries;
        $categories   = KnowledgeBase::whereNull('parent_id')
            ->distinct('category')
            ->pluck('category')
            ->filter()
            ->values();

        return response()->json([
            'data'          => $paginated->items(),
            'total'         => $paginated->total(),
            'per_page'      => $paginated->perPage(),
            'current_page'  => $paginated->currentPage(),
            'last_page'     => $paginated->lastPage(),
            'stats'         => [
                'total_entries' => $totalEntries,
                'total_chunks'  => $totalChunks,
                'categories'    => $categories,
            ],
        ]);
    }

    public function apiKnowledgeCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'    => 'required|string|max:255',
            'content'  => 'required|string',
            'category' => 'nullable|string|max:100',
            'tags'     => 'nullable|array',
        ]);

        try {
            $vectorStore = app(VectorStoreService::class);
            $id = $vectorStore->store(
                $validated['title'],
                $validated['content'],
                $validated['tags'] ?? [],
                $validated['category'] ?? 'general',
            );
            return response()->json(['id' => $id, 'message' => 'Knowledge stored successfully.'], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to store knowledge: ' . $e->getMessage()], 500);
        }
    }

    public function apiKnowledgeDelete(string $id): JsonResponse
    {
        // Delete the entry and all its chunks
        $deleted = KnowledgeBase::where('id', $id)
            ->orWhere('parent_id', $id)
            ->delete();

        return response()->json(['deleted' => $deleted > 0]);
    }

    // ── API: Agent Prompt Override ────────────────────────────────────

    // ── API: Test Connection ──────────────────────────────────────────

    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:openai,anthropic,gemini',
        ]);

        $provider = $validated['provider'];
        $start    = (int) round(microtime(true) * 1000);

        try {
            switch ($provider) {
                case 'openai':
                    $key = $this->credentials->retrieve('OPENAI_API_KEY') ?? '';
                    if (empty($key) || str_contains($key, 'CHANGE_ME')) {
                        throw new \RuntimeException('OpenAI API key is not configured.');
                    }
                    $response = Http::withToken($key)
                        ->timeout(10)
                        ->get('https://api.openai.com/v1/models');
                    if ($response->failed()) {
                        throw new \RuntimeException('OpenAI responded with HTTP ' . $response->status() . ': ' . ($response->json('error.message') ?? 'Unknown error'));
                    }
                    $modelCount = count($response->json('data') ?? []);
                    $message = "Connected. {$modelCount} models available.";
                    break;

                case 'anthropic':
                    $key = $this->credentials->retrieve('ANTHROPIC_API_KEY') ?? '';
                    if (empty($key) || str_contains($key, 'CHANGE_ME')) {
                        throw new \RuntimeException('Anthropic API key is not configured.');
                    }
                    $response = Http::withHeaders([
                        'x-api-key'         => $key,
                        'anthropic-version' => '2023-06-01',
                        'content-type'      => 'application/json',
                    ])->timeout(10)->post('https://api.anthropic.com/v1/messages/count_tokens', [
                        'model'    => 'claude-haiku-4-5-20251001',
                        'messages' => [['role' => 'user', 'content' => 'ping']],
                    ]);
                    if ($response->failed()) {
                        throw new \RuntimeException('Anthropic responded with HTTP ' . $response->status() . ': ' . ($response->json('error.message') ?? 'Unknown error'));
                    }
                    $tokens = $response->json('input_tokens') ?? '?';
                    $message = "Connected. Token count: {$tokens}.";
                    break;

                case 'gemini':
                    $key = $this->credentials->retrieve('GEMINI_API_KEY') ?? '';
                    if (empty($key) || str_contains($key, 'CHANGE_ME')) {
                        throw new \RuntimeException('Gemini API key is not configured.');
                    }
                    $response = Http::timeout(10)
                        ->get("https://generativelanguage.googleapis.com/v1beta/models?key={$key}");
                    if ($response->failed()) {
                        throw new \RuntimeException('Gemini responded with HTTP ' . $response->status() . ': ' . ($response->json('error.message') ?? 'Unknown error'));
                    }
                    $modelCount = count($response->json('models') ?? []);
                    $message = "Connected. {$modelCount} models available.";
                    break;

                default:
                    throw new \RuntimeException("Unknown provider: {$provider}");
            }

            $latencyMs = (int) round(microtime(true) * 1000) - $start;

            return response()->json([
                'success'    => true,
                'provider'   => $provider,
                'message'    => $message,
                'latency_ms' => $latencyMs,
            ]);

        } catch (\Throwable $e) {
            $latencyMs = (int) round(microtime(true) * 1000) - $start;

            return response()->json([
                'success'    => false,
                'provider'   => $provider,
                'message'    => $e->getMessage(),
                'latency_ms' => $latencyMs,
            ], 422);
        }
    }

    // ── API: Agent Prompt Override ────────────────────────────────────

    public function apiUpdatePrompt(Request $request, string $name): JsonResponse
    {
        $agents = array_keys(config('agents.agents', []));
        if (! in_array($name, $agents)) {
            return response()->json(['error' => 'Unknown agent: ' . $name], 404);
        }

        $validated = $request->validate(['prompt' => 'required|string|min:10|max:5000']);

        $promptKey = 'AGENT_' . strtoupper($name) . '_PROMPT';
        $this->credentials->store($name, $promptKey, $validated['prompt']);

        return response()->json(['saved' => true, 'agent' => $name]);
    }

    // ── API: Save Platform Config ─────────────────────────────────────

    public function savePlatform(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:openai,anthropic,gemini',
            'api_key'  => 'required|string|min:10',
            'model'    => 'nullable|string|max:100',
        ]);

        $provider = $validated['provider'];
        $envKey   = match($provider) {
            'anthropic' => 'ANTHROPIC_API_KEY',
            'gemini'    => 'GEMINI_API_KEY',
            default     => 'OPENAI_API_KEY',
        };

        $this->credentials->store($provider, $envKey, $validated['api_key']);

        if (! empty($validated['model'])) {
            $modelKey = match($provider) {
                'anthropic' => 'ANTHROPIC_DEFAULT_MODEL',
                'gemini'    => 'GEMINI_DEFAULT_MODEL',
                default     => 'OPENAI_DEFAULT_MODEL',
            };
            $this->credentials->store($provider, $modelKey, $validated['model']);
        }

        return response()->json(['saved' => true, 'provider' => $provider]);
    }
}
