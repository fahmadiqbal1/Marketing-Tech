<?php

namespace App\Http\Controllers;

use App\Jobs\IngestGitHubRepo;
use App\Models\AgentJob;
use App\Models\Candidate;
use App\Models\ContentItem;
use App\Models\CustomAiPlatform;
use App\Models\AgentStep;
use App\Models\AgentTask;
use App\Models\ContentVariation;
use App\Models\GeneratedOutput;
use App\Models\KnowledgeBase;
use App\Services\CampaignContextService;
use App\Services\Marketing\CampaignService;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\AI\OpenAIService;
use App\Services\ApiCredentialService;
use App\Services\Dashboard\DashboardStatsService;
use App\Services\Knowledge\VectorStoreService;
use App\Workflows\WorkflowDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DashboardController – thin controller.
 * All query logic lives in DashboardStatsService.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService  $stats,
        private readonly ApiCredentialService   $credentials,
        private readonly CampaignContextService $campaignContext,
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

    public function apiJobs(Request $request): JsonResponse
    {
        $data = $this->stats->getJobs($request->only(['status', 'agent_type']));

        $topReason = AgentJob::where('status', 'failed')
            ->whereNotNull('error_message')
            ->latest()
            ->limit(20)
            ->pluck('error_message')
            ->groupBy(fn ($m) => Str::limit($m, 60))
            ->sortByDesc(fn ($g) => $g->count())
            ->keys()
            ->first();

        $data['top_failure_reason'] = $topReason;

        return response()->json($data);
    }

    // ── API: Campaigns ────────────────────────────────────────────────

    public function apiCampaigns(): JsonResponse
    {
        return response()->json($this->stats->getCampaigns());
    }

    // ── API: Candidates ───────────────────────────────────────────────

    public function apiCandidates(Request $request): JsonResponse
    {
        return response()->json($this->stats->getCandidates($request->only(['search', 'pipeline_stage', 'min_score'])));
    }

    public function apiCandidateDetail(string $id): JsonResponse
    {
        try {
            $candidate = Candidate::findOrFail($id);
            return response()->json(['candidate' => $candidate]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Not found'], 404);
        }
    }

    // ── API: Content ──────────────────────────────────────────────────

    public function apiContent(Request $request): JsonResponse
    {
        $items = $this->stats->getContent($request->only(['type', 'status', 'search']));
        return response()->json($items);
    }

    public function apiContentDetail(string $id): JsonResponse
    {
        try {
            $item = ContentItem::findOrFail($id);
            return response()->json(['item' => $item]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Not found'], 404);
        }
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
        // Fail fast if schema is not fully migrated — prevents silent degradation
        if (! Schema::hasColumn('agent_steps', 'agent_job_id')) {
            Log::critical('Schema mismatch: agent_job_id missing from agent_steps. Run: php artisan migrate');
            return response()->json([
                'error' => 'System not fully migrated. Please run: php artisan migrate',
            ], 500);
        }

        $agentDefs = config('agents.agents', []);

        // Recent steps across all agents (last 60)
        $recentSteps = AgentStep::latest()
            ->limit(60)
            ->get(['id', 'task_id', 'agent_job_id', 'agent_name', 'action', 'thought', 'status', 'tokens_used', 'latency_ms', 'knowledge_chunks_used', 'knowledge_scores', 'tool_success', 'tool_error', 'from_cache', 'created_at'])
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

        // Count knowledge entries per agent.
        // Agents store knowledge under their own name as category (e.g. 'content', 'marketing').
        // AgentSkillsSeeder also stores entries under 'agent-skills' with title "Agent Skills: {Name}".
        // We count both to give an accurate picture of what knowledge exists for each agent.
        $knowledgeCounts = KnowledgeBase::selectRaw('category, count(*) as cnt')
            ->groupBy('category')
            ->pluck('cnt', 'category');

        // Count agent-skills entries per agent name (title contains agent name, case-insensitive)
        $agentNames    = array_keys($agentDefs);
        $skillCounts   = [];
        if (! empty($agentNames) && KnowledgeBase::where('category', 'agent-skills')->exists()) {
            foreach ($agentNames as $agentName) {
                $skillCounts[$agentName] = KnowledgeBase::where('category', 'agent-skills')
                    ->whereRaw('lower(title) like ?', ['%' . strtolower($agentName) . '%'])
                    ->count();
            }
        }

        $totalKnowledge = KnowledgeBase::count();

        $agents = [];
        foreach ($agentDefs as $name => $def) {
            $promptKey    = 'AGENT_' . strtoupper($name) . '_PROMPT';
            $customPrompt = $this->credentials->retrieve($promptKey);
            $agents[]     = [
                'name'            => $name,
                'provider'        => $def['provider'] ?? 'openai',
                'model'           => $def['model'] ?? 'gpt-4o',
                'queue'           => $def['queue'] ?? $name,
                'max_steps'       => $def['max_steps'] ?? 15,
                'tools'           => $def['tools'] ?? [],
                'system_prompt'   => $customPrompt ?? ($def['system_prompt'] ?? ''),
                'knowledge_count' => (int) ($knowledgeCounts[$name] ?? 0) + (int) ($skillCounts[strtolower($name)] ?? 0),
                'recent_steps'    => $stepsByAgent->get($name, collect())->take(5)->values()->toArray(),
                'active_jobs'     => (int) ($runningPerAgent[$name] ?? 0),
            ];
        }

        return response()->json([
            'agents'            => $agents,
            'total_running'     => $totalRunning,
            'total_steps_today' => AgentStep::whereDate('created_at', today())->count(),
            'total_knowledge'   => $totalKnowledge,
        ]);
    }

    // ── API: Campaign Detail ──────────────────────────────────────────

    public function campaignDetail(string $id)
    {
        return view('campaign-detail', ['campaignId' => $id]);
    }

    public function apiCampaignDetail(string $id): JsonResponse
    {
        $jobs = AgentJob::where('campaign_id', $id)
            ->latest()
            ->get(['id', 'agent_type', 'ai_provider', 'instruction', 'status', 'steps_taken', 'total_tokens', 'created_at', 'completed_at']);

        $outputs = GeneratedOutput::query()
            ->join('agent_jobs', 'generated_outputs.agent_job_id', '=', 'agent_jobs.id')
            ->where('agent_jobs.campaign_id', $id)
            ->orderByDesc('generated_outputs.created_at')
            ->limit(20)
            ->get(['generated_outputs.id', 'generated_outputs.type', 'generated_outputs.version',
                   'generated_outputs.is_winner', 'generated_outputs.metadata', 'generated_outputs.created_at',
                   'generated_outputs.agent_job_id']);

        return response()->json([
            'campaign_id'  => $id,
            'jobs'         => $jobs,
            'outputs'      => $outputs,
            'best_assets'  => $this->campaignContext->getBestPerformingAssets($id),
            'context'      => $this->campaignContext->getCampaignContext($id),
        ]);
    }

    // ── API: Knowledge ────────────────────────────────────────────────

    public function apiKnowledge(Request $request): JsonResponse
    {
        $query = KnowledgeBase::whereNull('parent_id') // top-level entries only
            ->select(['id', 'title', 'category', 'tags', 'chunk_index', 'access_count', 'last_accessed_at', 'created_at']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('content', 'ilike', "%{$search}%");
            });
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
            'meta'          => ['database_available' => true],
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

    public function apiKnowledgeGitHub(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'repo_url'     => 'required|string|url',
            'category'     => 'nullable|string|max:100',
            'github_token' => 'nullable|string|max:255',
            'branch'       => 'nullable|string|max:100',
        ]);

        // Ensure it's a GitHub URL
        if (! str_contains($validated['repo_url'], 'github.com')) {
            return response()->json(['error' => 'Only GitHub repository URLs are supported.'], 422);
        }

        $token = ! empty($validated['github_token'])
            ? $validated['github_token']
            : $this->credentials->retrieve('GITHUB_TOKEN');

        IngestGitHubRepo::dispatch(
            repoUrl:      $validated['repo_url'],
            category:     $validated['category'] ?? 'general',
            extensions:   ['md', 'txt', 'php', 'js', 'ts', 'py', 'json', 'yaml', 'yml'],
            githubToken:  $token,
            branch:       $validated['branch'] ?? 'main',
        )->onQueue('low');

        return response()->json([
            'dispatched' => true,
            'message'    => 'Import queued. Files will appear in the knowledge base shortly.',
            'repo'       => $validated['repo_url'],
        ]);
    }

    public function apiKnowledgeImportStatus(Request $request): JsonResponse
    {
        $repoUrl = $request->query('repo_url', '');
        if (empty($repoUrl)) {
            return response()->json(['error' => 'repo_url required'], 422);
        }
        // Normalize before hashing — must match IngestGitHubRepo::normalizeRepoUrl()
        $repoUrl  = strtolower(rtrim(preg_replace('#\.git$#', '', trim($repoUrl)), '/'));
        $cacheKey = 'github-import:' . md5($repoUrl);
        $status   = Cache::get($cacheKey);
        if (! $status) {
            return response()->json(['status' => 'not_found']);
        }
        return response()->json($status);
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
            'provider' => 'required|string|max:100',
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

                case 'telegram':
                    $token = $this->credentials->retrieve('TELEGRAM_BOT_TOKEN') ?? '';
                    if (empty($token) || str_contains($token, 'CHANGE_ME')) {
                        throw new \RuntimeException('Telegram bot token is not configured.');
                    }
                    $response = Http::timeout(10)
                        ->get("https://api.telegram.org/bot{$token}/getMe");
                    if ($response->failed()) {
                        throw new \RuntimeException('Telegram responded with HTTP ' . $response->status());
                    }
                    if (! ($response->json('ok') ?? false)) {
                        throw new \RuntimeException('Telegram error: ' . ($response->json('description') ?? 'Unknown'));
                    }
                    $botName = $response->json('result.username') ?? 'unknown';
                    $message = "Connected. Bot: @{$botName}.";
                    break;

                default:
                    // Custom platform
                    $platform = CustomAiPlatform::where('name', $provider)->where('is_active', true)->first();
                    if (! $platform) {
                        throw new \RuntimeException("Unknown provider: {$provider}");
                    }
                    $apiKey = $this->credentials->retrieve($platform->api_key_env) ?? '';
                    if (empty($apiKey)) {
                        throw new \RuntimeException("API key for {$platform->name} is not configured.");
                    }
                    $headers = ['Content-Type' => 'application/json'];
                    if ($platform->auth_type === 'x-api-key') {
                        $headers[$platform->auth_header ?: 'X-API-Key'] = $apiKey;
                    } else {
                        $headers['Authorization'] = "Bearer {$apiKey}";
                    }
                    $resp = Http::withHeaders($headers)->timeout(10)->post(
                        rtrim($platform->api_base_url, '/') . '/chat/completions',
                        ['model' => $platform->default_model, 'messages' => [['role' => 'user', 'content' => 'ping']], 'max_tokens' => 5]
                    );
                    if ($resp->failed()) {
                        throw new \RuntimeException("{$platform->name} responded HTTP {$resp->status()}");
                    }
                    if (empty($resp->json('choices.0.message.content'))) {
                        throw new \RuntimeException("{$platform->name} returned unexpected response shape");
                    }
                    $message = "Connected to {$platform->name}.";
                    break;
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
            'provider' => 'required|string|max:100',
            'api_key'  => 'required|string|min:10',
            'model'    => 'nullable|string|max:100',
        ]);

        $provider = $validated['provider'];

        $envKey = match($provider) {
            'anthropic' => 'ANTHROPIC_API_KEY',
            'gemini'    => 'GEMINI_API_KEY',
            'openai'    => 'OPENAI_API_KEY',
            default     => CustomAiPlatform::where('name', $provider)->value('api_key_env') ?? 'OPENAI_API_KEY',
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

    // ── API: Custom AI Platforms ──────────────────────────────────────

    public function apiCustomPlatforms(): JsonResponse
    {
        $platforms = CustomAiPlatform::orderBy('name')->get()->map(fn ($p) => [
            'id'           => $p->id,
            'name'         => $p->name,
            'website_url'  => $p->website_url,
            'api_base_url' => $p->api_base_url,
            'default_model' => $p->default_model,
            'api_key_env'  => $p->api_key_env,
            'auth_type'    => $p->auth_type,
            'is_active'    => $p->is_active,
            'configured'   => ! empty($this->credentials->retrieve($p->api_key_env)),
        ]);

        return response()->json(['data' => $platforms]);
    }

    public function apiCustomPlatformCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:80|unique:custom_ai_platforms,name',
            'website_url'   => 'nullable|url|max:255',
            'api_base_url'  => 'required|url|max:255',
            'default_model' => 'required|string|max:100',
            'api_key_env'   => 'required|string|max:100|regex:/^[A-Z0-9_]+$/',
            'auth_type'     => 'required|in:bearer,x-api-key',
            'auth_header'   => 'nullable|string|max:100',
        ]);

        $platform = CustomAiPlatform::create($validated);

        return response()->json(['created' => true, 'id' => $platform->id], 201);
    }

    public function apiCustomPlatformDelete(string $id): JsonResponse
    {
        $platform = CustomAiPlatform::findOrFail($id);
        $platform->delete();

        return response()->json(['deleted' => true]);
    }
}
