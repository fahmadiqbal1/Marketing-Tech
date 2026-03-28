<?php

namespace App\Http\Controllers;

use App\Jobs\IngestGitHubRepo;
use App\Models\AgentJob;
use App\Models\Campaign;
use App\Models\Candidate;
use App\Models\ContentCalendar;
use App\Models\ContentItem;
use App\Models\CustomAiPlatform;
use App\Models\HashtagSet;
use App\Models\SocialAccount;
use App\Services\IterationEngineService;
use App\Services\Social\SocialPlatformService;
use App\Services\Social\Platforms\InstagramService;
use App\Services\Social\Platforms\TwitterService;
use App\Services\Social\Platforms\LinkedInService;
use App\Services\Social\Platforms\FacebookService;
use App\Services\Social\Platforms\TikTokService;
use App\Services\Social\Platforms\YouTubeService;
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

    public function apiCreateCampaign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'type'        => 'required|in:email,social,sms,push,ads',
            'audience'    => 'nullable|string|max:255',
            'subject'     => 'nullable|string|max:500',
            'schedule_at' => 'nullable|date',
        ]);

        $campaign = Campaign::create(array_merge($validated, ['status' => 'draft']));

        return response()->json($campaign, 201);
    }

    public function apiPauseCampaign(string $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->update(['status' => 'paused']);

        return response()->json($campaign->fresh());
    }

    public function apiResumeCampaign(string $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->update(['status' => 'active']);

        return response()->json($campaign->fresh());
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

    public function apiPatchCandidate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'pipeline_stage' => 'sometimes|string|in:new,screening,interview,offer,hired,rejected',
            'pipeline_notes' => 'sometimes|nullable|string|max:2000',
            'score'          => 'sometimes|numeric|min:0|max:100',
        ]);

        try {
            $candidate = Candidate::findOrFail($id);

            if (isset($validated['pipeline_stage'])) {
                $validated['stage_updated_at'] = now();
            }

            $candidate->update($validated);

            return response()->json(['success' => true, 'candidate' => $candidate->fresh()]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Not found'], 404);
        }
    }

    /**
     * Resume intake API: Accepts candidate application, parses CV, scores, updates pipeline, notifies via Telegram.
     */
    public function apiCandidateApply(Request $request): JsonResponse
    {
        $request->validate([
            'cv_text' => 'required|string',
            'candidate_name' => 'nullable|string',
            'source' => 'nullable|string',
            'job_id' => 'required|uuid|exists:job_postings,id',
        ]);

        $args = [
            'cv_text' => $request->input('cv_text'),
            'candidate_name' => $request->input('candidate_name'),
            'source' => $request->input('source', 'direct'),
            'job_id' => $request->input('job_id'),
        ];

        // 1. Parse CV and create candidate
        $agent = app(\App\Agents\HiringAgent::class);
        $parseResult = json_decode($agent->toolParseCV($args), true);
        if (!($parseResult['success'] ?? false)) {
            return response()->json(['error' => $parseResult['error'] ?? 'Failed to parse CV'], 422);
        }
        $candidateId = $parseResult['data']['candidate_id'] ?? null;
        if (!$candidateId) {
            return response()->json(['error' => 'Candidate creation failed'], 500);
        }

        // 2. Score candidate
        $scoreResult = json_decode($agent->toolScoreCandidate([
            'candidate_id' => $candidateId,
            'job_id' => $args['job_id'],
        ]), true);
        if (!($scoreResult['success'] ?? false)) {
            return response()->json(['error' => $scoreResult['error'] ?? 'Failed to score candidate'], 500);
        }

        // 3. Update pipeline stage to 'screening'
        $agent->toolUpdatePipeline([
            'candidate_id' => $candidateId,
            'stage' => 'screening',
            'notes' => 'Auto-screened on application',
        ]);

        // 4. Notify via Telegram (if enabled)
        try {
            $agent->telegram->sendMessage([
                'text' => "New candidate applied: " . ($parseResult['data']['name'] ?? 'Unknown'),
            ]);
        } catch (\Throwable $e) {
            // Ignore Telegram errors
        }

        return response()->json(['success' => true, 'candidate_id' => $candidateId]);
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

    // ─── Social Media — Page & OAuth ─────────────────────────────────────────

    public function social(): \Illuminate\View\View
    {
        return view('social');
    }

    public function socialInstagramRedirect(): \Illuminate\Http\RedirectResponse
    {
        $instagram = new InstagramService();

        if (! $instagram->isConfigured()) {
            return redirect('/dashboard/social')->with('error', 'Instagram API credentials are not configured. Set SOCIAL_INSTAGRAM_CLIENT_ID and SOCIAL_INSTAGRAM_CLIENT_SECRET in .env');
        }

        $data = $instagram->getAuthorizationUrl();
        session(['oauth_instagram_state' => $data['state']]);

        return redirect($data['url']);
    }

    public function socialInstagramCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        if ($request->has('error')) {
            return redirect('/dashboard/social')->with('error', 'Instagram authorization denied: ' . $request->get('error_description'));
        }

        if ($request->get('state') !== session('oauth_instagram_state')) {
            return redirect('/dashboard/social')->with('error', 'Instagram OAuth state mismatch — possible CSRF.');
        }
        session()->forget('oauth_instagram_state');

        $code      = $request->get('code');
        $instagram = new InstagramService();

        try {
            $tokenData = $instagram->exchangeCode($code);

            // Fetch user profile to get platform_user_id and handle
            $profile = \Illuminate\Support\Facades\Http::get('https://graph.instagram.com/me', [
                'fields'       => 'id,username',
                'access_token' => $tokenData['access_token'],
            ])->json();

            SocialAccount::updateOrCreate(
                ['platform' => 'instagram', 'handle' => $profile['username'] ?? 'unknown'],
                [
                    'platform_user_id' => $profile['id'] ?? null,
                    'access_token'     => $tokenData['access_token'],
                    'token_expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
                    'is_connected'     => true,
                    'last_error'       => null,
                    'last_synced_at'   => now(),
                ]
            );

            return redirect('/dashboard/social')->with('success', 'Instagram connected successfully');
        } catch (\Throwable $e) {
            Log::error('Instagram OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect('/dashboard/social')->with('error', 'Failed to connect Instagram: ' . $e->getMessage());
        }
    }

    // ─── Social OAuth — Twitter ───────────────────────────────────────────────

    public function socialTwitterRedirect(Request $request): \Illuminate\Http\RedirectResponse
    {
        $svc = new TwitterService();
        if (! $svc->isConfigured()) {
            return redirect('/dashboard/social')->with('error', 'Twitter API credentials not configured. Set SOCIAL_TWITTER_CLIENT_ID and SOCIAL_TWITTER_CLIENT_SECRET in .env');
        }
        $codeVerifier = bin2hex(random_bytes(32));
        $data = $svc->getAuthorizationUrl($codeVerifier);
        session(['oauth_twitter_verifier' => $data['code_verifier'], 'oauth_twitter_state' => $data['state']]);
        return redirect($data['url']);
    }

    public function socialTwitterCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        if ($request->has('error')) {
            return redirect('/dashboard/social')->with('error', 'Twitter authorization denied: ' . $request->get('error_description'));
        }
        if ($request->get('state') !== session('oauth_twitter_state')) {
            return redirect('/dashboard/social')->with('error', 'Twitter OAuth state mismatch — possible CSRF.');
        }
        $svc = new TwitterService();
        try {
            $tokenData    = $svc->exchangeCode($request->get('code'), session('oauth_twitter_verifier'));
            $profile      = \Illuminate\Support\Facades\Http::withToken($tokenData['access_token'])
                ->get('https://api.twitter.com/2/users/me', ['user.fields' => 'username'])->json();
            $userId       = $profile['data']['id']       ?? null;
            $username     = $profile['data']['username'] ?? 'unknown';
            SocialAccount::updateOrCreate(
                ['platform' => 'twitter', 'handle' => $username],
                [
                    'platform_user_id' => $userId,
                    'access_token'     => $tokenData['access_token'],
                    'refresh_token'    => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
                    'is_connected'     => true,
                    'last_error'       => null,
                    'last_synced_at'   => now(),
                ]
            );
            session()->forget(['oauth_twitter_verifier', 'oauth_twitter_state']);
            return redirect('/dashboard/social')->with('success', 'Twitter / X connected successfully.');
        } catch (\Throwable $e) {
            Log::error('Twitter OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect('/dashboard/social')->with('error', 'Failed to connect Twitter: ' . $e->getMessage());
        }
    }

    // ─── Social OAuth — LinkedIn ──────────────────────────────────────────────

    public function socialLinkedInRedirect(): \Illuminate\Http\RedirectResponse
    {
        $svc = new LinkedInService();
        if (! $svc->isConfigured()) {
            return redirect('/dashboard/social')->with('error', 'LinkedIn API credentials not configured. Set SOCIAL_LINKEDIN_CLIENT_ID and SOCIAL_LINKEDIN_CLIENT_SECRET in .env');
        }
        $data = $svc->getAuthorizationUrl();
        session(['oauth_linkedin_state' => $data['state']]);
        return redirect($data['url']);
    }

    public function socialLinkedInCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        if ($request->has('error')) {
            return redirect('/dashboard/social')->with('error', 'LinkedIn authorization denied: ' . $request->get('error_description'));
        }
        if ($request->get('state') !== session('oauth_linkedin_state')) {
            return redirect('/dashboard/social')->with('error', 'LinkedIn OAuth state mismatch — possible CSRF.');
        }
        $svc = new LinkedInService();
        try {
            $tokenData = $svc->exchangeCode($request->get('code'));
            $profile   = \Illuminate\Support\Facades\Http::withToken($tokenData['access_token'])
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->get('https://api.linkedin.com/v2/me', ['projection' => '(id,localizedFirstName,localizedLastName)'])->json();
            $userId    = $profile['id'] ?? null;
            $name      = trim(($profile['localizedFirstName'] ?? '') . ' ' . ($profile['localizedLastName'] ?? '')) ?: 'unknown';

            // Fetch organizations the user admins — store for org selection
            $orgsResponse = \Illuminate\Support\Facades\Http::withToken($tokenData['access_token'])
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->get('https://api.linkedin.com/v2/organizationAcls', [
                    'q'    => 'roleAssignee',
                    'role' => 'ADMINISTRATOR',
                ]);

            $organizations = [];
            if ($orgsResponse->ok()) {
                foreach ($orgsResponse->json('elements', []) as $acl) {
                    $orgUrn = $acl['organization'] ?? null;
                    if ($orgUrn) {
                        $organizations[] = ['urn' => $orgUrn, 'name' => $acl['organizationName'] ?? $orgUrn];
                    }
                }
            }

            $defaultOrgUrn = $organizations[0]['urn'] ?? null;

            SocialAccount::updateOrCreate(
                ['platform' => 'linkedin', 'handle' => $userId ?? $name],
                [
                    'platform_user_id' => $userId,
                    'display_name'     => $name,
                    'access_token'     => $tokenData['access_token'],
                    'refresh_token'    => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
                    'metadata'         => [
                        'organization_urn'  => $defaultOrgUrn,
                        'organizations'     => $organizations,
                    ],
                    'is_connected'     => true,
                    'last_error'       => null,
                    'last_synced_at'   => now(),
                ]
            );
            session()->forget('oauth_linkedin_state');
            $orgMsg = $defaultOrgUrn ? ' (' . count($organizations) . ' organization(s) found)' : '';
            return redirect('/dashboard/social')->with('success', "LinkedIn connected successfully{$orgMsg}.");
        } catch (\Throwable $e) {
            Log::error('LinkedIn OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect('/dashboard/social')->with('error', 'Failed to connect LinkedIn: ' . $e->getMessage());
        }
    }

    // ─── Social OAuth — Facebook ──────────────────────────────────────────────

    public function socialFacebookRedirect(): \Illuminate\Http\RedirectResponse
    {
        $svc = new FacebookService();
        if (! $svc->isConfigured()) {
            return redirect('/dashboard/social')->with('error', 'Facebook API credentials not configured. Set SOCIAL_FACEBOOK_CLIENT_ID and SOCIAL_FACEBOOK_CLIENT_SECRET in .env');
        }
        $data = $svc->getAuthorizationUrl();
        session(['oauth_facebook_state' => $data['state']]);
        return redirect($data['url']);
    }

    public function socialFacebookCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        if ($request->has('error')) {
            return redirect('/dashboard/social')->with('error', 'Facebook authorization denied: ' . $request->get('error_description'));
        }
        if ($request->get('state') !== session('oauth_facebook_state')) {
            return redirect('/dashboard/social')->with('error', 'Facebook OAuth state mismatch — possible CSRF.');
        }
        $svc = new FacebookService();
        try {
            $tokenData   = $svc->exchangeCode($request->get('code'));
            $userToken   = $tokenData['user_access_token'];

            // Fetch ALL managed pages and create/update a SocialAccount per page
            $pagesResponse = \Illuminate\Support\Facades\Http::get(
                'https://graph.facebook.com/v19.0/me/accounts',
                ['access_token' => $userToken]
            );

            $pages     = $pagesResponse->ok() ? $pagesResponse->json('data', []) : [];
            $pageCount = 0;

            foreach ($pages as $page) {
                $pageId    = $page['id'];
                $pageName  = $page['name'] ?? 'Facebook Page';
                $pageToken = $page['access_token'] ?? $userToken;

                SocialAccount::updateOrCreate(
                    ['platform' => 'facebook', 'handle' => $pageId],
                    [
                        'platform_user_id' => $pageId,
                        'display_name'     => $pageName,
                        'access_token'     => $pageToken,
                        'token_expires_at' => null, // page tokens don't expire
                        'metadata'         => [
                            'user_access_token' => $userToken,
                            'page_id'           => $pageId,
                            'page_name'         => $pageName,
                        ],
                        'is_connected'  => true,
                        'last_error'    => null,
                        'last_synced_at'=> now(),
                    ]
                );
                $pageCount++;
            }

            // Fallback: if no pages returned, store with the single page from exchangeCode
            if ($pageCount === 0) {
                $pageId   = $tokenData['page_id']   ?? 'page';
                $pageName = $tokenData['page_name'] ?? 'Facebook Page';
                SocialAccount::updateOrCreate(
                    ['platform' => 'facebook', 'handle' => $pageId],
                    [
                        'platform_user_id' => $pageId,
                        'display_name'     => $pageName,
                        'access_token'     => $tokenData['page_access_token'],
                        'token_expires_at' => null,
                        'metadata'         => ['user_access_token' => $userToken, 'page_id' => $pageId, 'page_name' => $pageName],
                        'is_connected'     => true,
                        'last_error'       => null,
                        'last_synced_at'   => now(),
                    ]
                );
                $pageCount = 1;
            }

            session()->forget('oauth_facebook_state');
            return redirect('/dashboard/social')->with('success', "Facebook connected: {$pageCount} page(s) linked.");
        } catch (\Throwable $e) {
            Log::error('Facebook OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect('/dashboard/social')->with('error', 'Failed to connect Facebook: ' . $e->getMessage());
        }
    }

    // ─── Social OAuth — TikTok ────────────────────────────────────────────────

    public function socialTikTokRedirect(Request $request): \Illuminate\Http\RedirectResponse
    {
        $svc = new TikTokService();
        if (! $svc->isConfigured()) {
            return redirect('/dashboard/social')->with('error', 'TikTok API credentials not configured. Set SOCIAL_TIKTOK_CLIENT_KEY and SOCIAL_TIKTOK_CLIENT_SECRET in .env');
        }
        $codeVerifier = bin2hex(random_bytes(32));
        $data = $svc->getAuthorizationUrl($codeVerifier);
        session(['oauth_tiktok_verifier' => $data['code_verifier'], 'oauth_tiktok_state' => $data['state']]);
        return redirect($data['url']);
    }

    public function socialTikTokCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        if ($request->has('error')) {
            return redirect('/dashboard/social')->with('error', 'TikTok authorization denied: ' . $request->get('error_description', $request->get('error')));
        }
        if ($request->get('state') !== session('oauth_tiktok_state')) {
            return redirect('/dashboard/social')->with('error', 'TikTok OAuth state mismatch — possible CSRF.');
        }
        $svc = new TikTokService();
        try {
            $tokenData = $svc->exchangeCode($request->get('code'), session('oauth_tiktok_verifier'));
            $openId    = $tokenData['open_id'] ?? null;
            // Fetch creator info
            $profile   = \Illuminate\Support\Facades\Http::withToken($tokenData['access_token'])
                ->post('https://open.tiktok.com/v2/user/info/', ['fields' => 'open_id,display_name,avatar_url'])
                ->json('data.user', []);
            $handle    = $openId ?? 'tiktok_user';
            SocialAccount::updateOrCreate(
                ['platform' => 'tiktok', 'handle' => $handle],
                [
                    'platform_user_id' => $openId,
                    'display_name'     => $profile['display_name'] ?? null,
                    'access_token'     => $tokenData['access_token'],
                    'refresh_token'    => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
                    'is_connected'     => true,
                    'last_error'       => null,
                    'last_synced_at'   => now(),
                ]
            );
            session()->forget(['oauth_tiktok_verifier', 'oauth_tiktok_state']);
            return redirect('/dashboard/social')->with('success', 'TikTok connected successfully.');
        } catch (\Throwable $e) {
            Log::error('TikTok OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect('/dashboard/social')->with('error', 'Failed to connect TikTok: ' . $e->getMessage());
        }
    }

    // ─── Social OAuth — YouTube ───────────────────────────────────────────────

    public function socialYouTubeRedirect(): \Illuminate\Http\RedirectResponse
    {
        $svc = new YouTubeService();
        if (! $svc->isConfigured()) {
            return redirect('/dashboard/social')->with('error', 'YouTube API credentials not configured. Set SOCIAL_YOUTUBE_CLIENT_ID and SOCIAL_YOUTUBE_CLIENT_SECRET in .env');
        }
        $data = $svc->getAuthorizationUrl();
        session(['oauth_youtube_state' => $data['state']]);
        return redirect($data['url']);
    }

    public function socialYouTubeCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        if ($request->has('error')) {
            return redirect('/dashboard/social')->with('error', 'YouTube authorization denied: ' . $request->get('error'));
        }
        if ($request->get('state') !== session('oauth_youtube_state')) {
            return redirect('/dashboard/social')->with('error', 'YouTube OAuth state mismatch — possible CSRF.');
        }
        $svc = new YouTubeService();
        try {
            $tokenData = $svc->exchangeCode($request->get('code'));
            // Fetch channel info
            $channel   = \Illuminate\Support\Facades\Http::withToken($tokenData['access_token'])
                ->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'snippet', 'mine' => 'true'])
                ->json('items.0', []);
            $channelId    = $channel['id']                       ?? null;
            $channelTitle = $channel['snippet']['title']         ?? 'YouTube Channel';
            $handle       = $channel['snippet']['customUrl']     ?? $channelId ?? 'youtube';
            SocialAccount::updateOrCreate(
                ['platform' => 'youtube', 'handle' => ltrim($handle, '@')],
                [
                    'platform_user_id' => $channelId,
                    'display_name'     => $channelTitle,
                    'access_token'     => $tokenData['access_token'],
                    'refresh_token'    => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
                    'is_connected'     => true,
                    'last_error'       => null,
                    'last_synced_at'   => now(),
                ]
            );
            session()->forget('oauth_youtube_state');
            return redirect('/dashboard/social')->with('success', "YouTube channel \"{$channelTitle}\" connected successfully.");
        } catch (\Throwable $e) {
            Log::error('YouTube OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect('/dashboard/social')->with('error', 'Failed to connect YouTube: ' . $e->getMessage());
        }
    }

    // ─── Social Media — Content Calendar ─────────────────────────────────────

    public function apiContentCalendar(Request $request): JsonResponse
    {
        $query = ContentCalendar::withoutTrashed();

        if ($platform = $request->get('platform')) {
            $query->where('platform', $platform);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($week = $request->get('week')) {
            try {
                $start = \Carbon\Carbon::parse($week)->startOfWeek();
                $end   = $start->copy()->endOfWeek();
                $query->whereBetween('scheduled_at', [$start, $end]);
            } catch (\Throwable) {}
        }

        $entries = $query->orderBy('scheduled_at')->paginate((int) $request->get('per_page', 50));

        return response()->json($entries);
    }

    public function apiCreateCalendarEntry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'platform'      => 'required|in:tiktok,instagram,facebook,twitter,linkedin,youtube',
            'content_type'  => 'required|in:reel,story,post,thread,carousel,live,ad,video,short',
            'draft_content' => 'nullable|string',
            'status'        => 'in:draft,scheduled,pending_approval',
            'scheduled_at'  => 'nullable|date',
            'hashtags'      => 'nullable|array',
            'metadata'      => 'nullable|array',
        ]);

        // Validate platform/content_type combo
        $invalid = [
            'instagram' => ['thread', 'video'],
            'twitter'   => ['reel', 'story', 'carousel', 'video', 'short'],
            'linkedin'  => ['reel', 'story', 'video', 'short'],
            'facebook'  => ['thread', 'short'],
            'youtube'   => ['thread', 'story', 'live', 'ad'],
        ];

        if (isset($invalid[$validated['platform']]) && in_array($validated['content_type'], $invalid[$validated['platform']])) {
            return response()->json([
                'error' => "content_type '{$validated['content_type']}' is not supported on {$validated['platform']}",
            ], 422);
        }

        // Scheduling conflict check: no other non-failed entry within ±15 min on same platform
        if (! empty($validated['scheduled_at'])) {
            $time = \Carbon\Carbon::parse($validated['scheduled_at']);
            $conflict = ContentCalendar::where('platform', $validated['platform'])
                ->where('status', '!=', 'failed')
                ->whereBetween('scheduled_at', [$time->copy()->subMinutes(15), $time->copy()->addMinutes(15)])
                ->exists();
            if ($conflict) {
                return response()->json([
                    'error' => "Another {$validated['platform']} post is already scheduled within 15 minutes of this time.",
                ], 422);
            }
        }

        $entry = ContentCalendar::create($validated);

        return response()->json($entry, 201);
    }

    public function apiUpdateCalendarEntry(Request $request, string $id): JsonResponse
    {
        $entry     = ContentCalendar::findOrFail($id);
        $validated = $request->validate([
            'title'                => 'sometimes|string|max:255',
            'draft_content'        => 'nullable|string',
            'status'               => 'sometimes|in:draft,scheduled,pending_approval,published,failed',
            'moderation_status'    => 'sometimes|in:pending,approved,rejected,auto_approved',
            'scheduled_at'         => 'nullable|date',
            'hashtags'             => 'nullable|array',
            'metadata'             => 'nullable|array',
        ]);

        // Scheduling conflict check on reschedule
        if (! empty($validated['scheduled_at'])) {
            $time = \Carbon\Carbon::parse($validated['scheduled_at']);
            $conflict = ContentCalendar::where('platform', $entry->platform)
                ->where('id', '!=', $entry->id)
                ->where('status', '!=', 'failed')
                ->whereBetween('scheduled_at', [$time->copy()->subMinutes(15), $time->copy()->addMinutes(15)])
                ->exists();
            if ($conflict) {
                return response()->json([
                    'error' => "Another {$entry->platform} post is already scheduled within 15 minutes of this time.",
                ], 422);
            }
        }

        $entry->update($validated);

        return response()->json($entry->fresh());
    }

    public function apiDeleteCalendarEntry(string $id): JsonResponse
    {
        $entry = ContentCalendar::findOrFail($id);
        $entry->delete();

        return response()->json(['deleted' => true]);
    }

    public function apiApproveCalendarEntry(string $id): JsonResponse
    {
        $entry = ContentCalendar::findOrFail($id);
        $entry->update(['moderation_status' => 'approved', 'status' => 'scheduled']);

        return response()->json(['approved' => true, 'entry' => $entry->fresh()]);
    }

    public function apiRejectCalendarEntry(Request $request, string $id): JsonResponse
    {
        $entry = ContentCalendar::findOrFail($id);
        $entry->update([
            'moderation_status' => 'rejected',
            'moderation_notes'  => $request->get('reason'),
            'status'            => 'draft',
        ]);

        return response()->json(['rejected' => true, 'entry' => $entry->fresh()]);
    }

    public function apiPublishCalendarEntry(string $id): JsonResponse
    {
        $entry = ContentCalendar::findOrFail($id);

        if ($entry->status === 'published') {
            return response()->json(['error' => 'Already published'], 422);
        }

        $posted = false;
        $result = [];

        try {
            $social = app(SocialPlatformService::class);

            if ($social->autoPostEnabled()) {
                $account = SocialAccount::connected()->forPlatform($entry->platform)->first();

                if ($account) {
                    $result = $social->publishWithRateLimit($account, $entry);
                    $entry->update([
                        'status'           => 'published',
                        'published_at'     => now(),
                        'external_post_id' => $result['post_id'] ?? null,
                    ]);
                    $posted = true;
                }
            }

            if (! $posted) {
                // Simulated publish
                $result = [
                    'post_id'     => 'sim_' . Str::random(10),
                    'impressions' => rand(100, 5000),
                    'clicks'      => rand(5, 300),
                    'conversions' => rand(0, 20),
                    'simulated'   => true,
                ];
                $entry->update(['status' => 'published', 'published_at' => now()]);
            }

            // Feed IterationEngine if variation is linked
            if ($entry->content_variation_id) {
                $impressions = $result['impressions'] ?? 0;
                $clicks      = $result['clicks']      ?? 0;
                $conversions = $result['conversions']  ?? 0;
                $source      = ($result['simulated'] ?? true) ? 'simulated' : 'real';

                app(IterationEngineService::class)->recordPerformance(
                    $entry->content_variation_id, $impressions, $clicks, $conversions, $source
                );
            }

            // Audit log
            \App\Models\SystemEvent::create([
                'level'   => 'info',
                'message' => sprintf(
                    "Published to %s: %s [%s]",
                    $entry->platform,
                    $entry->title,
                    $posted ? 'real' : 'simulated'
                ),
            ]);

            return response()->json([
                'published' => true,
                'simulated' => ! $posted,
                'post_id'   => $result['post_id'] ?? null,
                'entry'     => $entry->fresh(),
            ]);
        } catch (\Throwable $e) {
            $entry->increment('retry_count');
            $entry->update(['last_error' => $e->getMessage()]);

            if ($entry->retry_count >= 3) {
                $entry->update(['status' => 'failed']);
            }

            Log::error("apiPublishCalendarEntry failed for {$id}", ['error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─── Social Media — Hashtag Sets ─────────────────────────────────────────

    public function apiHashtagSets(Request $request): JsonResponse
    {
        $platform = $request->get('platform');
        $cacheKey = 'hashtag-sets:' . ($platform ?? 'all');

        $sets = Cache::remember($cacheKey, 3600, function () use ($platform) {
            $query = HashtagSet::orderByDesc('usage_count');
            if ($platform) {
                $query->forPlatform($platform);
            }
            return $query->get();
        });

        return response()->json(['data' => $sets]);
    }

    public function apiCreateHashtagSet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'platform'   => 'required|in:tiktok,instagram,facebook,twitter,linkedin',
            'niche'      => 'nullable|string|max:100',
            'tags'       => 'required|array|min:1',
            'tags.*'     => 'string',
            'reach_tier' => 'in:low,medium,high',
        ]);

        $set = HashtagSet::create($validated);

        // Bust cache
        Cache::forget('hashtag-sets:' . $validated['platform']);
        Cache::forget('hashtag-sets:all');

        return response()->json($set, 201);
    }

    public function apiDeleteHashtagSet(string $id): JsonResponse
    {
        $set = HashtagSet::findOrFail($id);
        $platform = $set->platform;
        $set->delete();

        Cache::forget('hashtag-sets:' . $platform);
        Cache::forget('hashtag-sets:all');

        return response()->json(['deleted' => true]);
    }

    // ─── Social Media — Social Accounts ──────────────────────────────────────

    public function apiSocialAccounts(): JsonResponse
    {
        $accounts = SocialAccount::orderBy('platform')->get()->makeVisible(['platform_user_id', 'token_expires_at', 'last_error', 'last_synced_at']);

        return response()->json(['data' => $accounts]);
    }

    public function apiUpsertSocialAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform'         => 'required|in:tiktok,instagram,facebook,twitter,linkedin',
            'handle'           => 'required|string|max:255',
            'display_name'     => 'nullable|string|max:255',
            'access_token'     => 'nullable|string',
            'follower_count'   => 'nullable|integer|min:0',
            'metadata'         => 'nullable|array',
        ]);

        $account = SocialAccount::updateOrCreate(
            ['platform' => $validated['platform'], 'handle' => $validated['handle']],
            array_merge($validated, [
                'is_connected' => ! empty($validated['access_token']),
            ])
        );

        return response()->json($account, 201);
    }

    public function apiPatchSocialAccount(Request $request, string $id): JsonResponse
    {
        $account   = SocialAccount::findOrFail($id);
        $validated = $request->validate(['metadata' => 'required|array']);

        // Merge into existing metadata so callers can update a single key
        $account->update(['metadata' => array_merge($account->metadata ?? [], $validated['metadata'])]);

        return response()->json($account->fresh());
    }

    public function apiDeleteSocialAccount(string $id): JsonResponse
    {
        $account = SocialAccount::findOrFail($id);
        $account->delete();

        return response()->json(['deleted' => true]);
    }

    // ─── Social Media — Analytics ─────────────────────────────────────────────

    public function apiTrendInsights(Request $request): JsonResponse
    {
        $platform = $request->get('platform', 'all');
        $cacheKey = "trend-insights:{$platform}";

        $insights = Cache::remember($cacheKey, 900, function () use ($platform) {
            $query = KnowledgeBase::whereNull('deleted_at')
                ->latest()
                ->limit(30);

            if ($platform !== 'all') {
                $query->where(function ($q) use ($platform) {
                    $q->where('content', 'ilike', "%{$platform}%")
                        ->orWhere('tags', 'ilike', "%{$platform}%");
                });
            }

            return $query->get(['id', 'title', 'category', 'tags', 'created_at'])
                ->map(fn ($k) => [
                    'type'       => 'analytical_insight',
                    'id'         => $k->id,
                    'title'      => $k->title,
                    'category'   => $k->category,
                    'age_days'   => $k->created_at->diffInDays(now()),
                    'confidence' => $k->created_at->diffInDays(now()) < 7 ? 'high'
                        : ($k->created_at->diffInDays(now()) < 30 ? 'medium' : 'low'),
                ]);
        });

        return response()->json([
            'platform'   => $platform,
            'insights'   => $insights,
            'cached_for' => '15 minutes',
            'note'       => 'Insights derived from knowledge base entries only.',
        ]);
    }

    public function apiSocialHealth(): JsonResponse
    {
        $connectedAccounts = SocialAccount::connected()->get(['platform', 'handle', 'token_expires_at', 'last_synced_at', 'last_error']);

        $scheduledThisWeek  = ContentCalendar::where('status', 'scheduled')
            ->whereBetween('scheduled_at', [now(), now()->addWeek()])
            ->count();

        $pendingApproval = ContentCalendar::where('moderation_status', 'pending')->count();

        $failedPosts = ContentCalendar::where('status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        $tokensExpiringSoon = $connectedAccounts->filter(fn ($a) => $a->token_expires_at && $a->token_expires_at->lt(now()->addHours(24)))->count();

        return response()->json([
            'connected_accounts'   => $connectedAccounts,
            'scheduled_this_week'  => $scheduledThisWeek,
            'pending_approval'     => $pendingApproval,
            'failed_last_24h'      => $failedPosts,
            'tokens_expiring_soon' => $tokensExpiringSoon,
            'auto_post_enabled'    => config('services.social.auto_post_enabled', false),
            'timestamp'            => now()->toIso8601String(),
        ]);
    }

    // ── API: Social Credentials (OAuth app credentials) ───────────────

    public function apiSocialCredentials(): JsonResponse
    {
        $platforms = [
            'instagram' => ['id_key' => 'SOCIAL_INSTAGRAM_CLIENT_ID', 'secret_key' => 'SOCIAL_INSTAGRAM_CLIENT_SECRET'],
            'twitter'   => ['id_key' => 'SOCIAL_TWITTER_CLIENT_ID',   'secret_key' => 'SOCIAL_TWITTER_CLIENT_SECRET'],
            'linkedin'  => ['id_key' => 'SOCIAL_LINKEDIN_CLIENT_ID',  'secret_key' => 'SOCIAL_LINKEDIN_CLIENT_SECRET'],
            'facebook'  => ['id_key' => 'SOCIAL_FACEBOOK_CLIENT_ID',  'secret_key' => 'SOCIAL_FACEBOOK_CLIENT_SECRET'],
            'tiktok'    => ['id_key' => 'SOCIAL_TIKTOK_CLIENT_KEY',   'secret_key' => 'SOCIAL_TIKTOK_CLIENT_SECRET'],
            'youtube'   => ['id_key' => 'SOCIAL_YOUTUBE_CLIENT_ID',   'secret_key' => 'SOCIAL_YOUTUBE_CLIENT_SECRET'],
        ];

        $result = [];
        foreach ($platforms as $platform => $keys) {
            $clientId     = $this->credentials->retrieve($keys['id_key']) ?? '';
            $clientSecret = $this->credentials->retrieve($keys['secret_key']) ?? '';
            $isConfigured = ! empty($clientId) && ! str_contains($clientId, 'CHANGE_ME')
                         && ! empty($clientSecret) && ! str_contains($clientSecret, 'CHANGE_ME');

            $result[] = [
                'platform'        => $platform,
                'client_id'       => $isConfigured ? substr($clientId, 0, 8) . str_repeat('*', max(0, strlen($clientId) - 8)) : '',
                'client_secret'   => $isConfigured ? str_repeat('*', 16) : '',
                'is_configured'   => $isConfigured,
                'status'          => $isConfigured ? 'configured' : 'missing',
                'last_tested_at'  => null,
                'last_test_error' => null,
            ];
        }

        return response()->json($result);
    }

    public function apiStoreSocialCredentials(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform'      => 'required|string|in:instagram,twitter,linkedin,facebook,tiktok,youtube',
            'client_id'     => 'required|string|min:4',
            'client_secret' => 'required|string|min:4',
        ]);

        $platform = $validated['platform'];

        $idKey     = match ($platform) {
            'tiktok' => 'SOCIAL_TIKTOK_CLIENT_KEY',
            default  => 'SOCIAL_' . strtoupper($platform) . '_CLIENT_ID',
        };
        $secretKey = 'SOCIAL_' . strtoupper($platform) . '_CLIENT_SECRET';

        $this->credentials->store($platform, $idKey, $validated['client_id']);
        $this->credentials->store($platform, $secretKey, $validated['client_secret']);

        return response()->json([
            'status'         => 'configured',
            'platform'       => $platform,
            'last_tested_at' => now()->toIso8601String(),
        ]);
    }
}
