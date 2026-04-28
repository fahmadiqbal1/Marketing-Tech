<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PipelineActionController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\DashboardBasicAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn() => redirect('/dashboard'));

// ── Authentication routes ─────────────────────────────────────────────────────
Route::get('/login',    [AuthController::class, 'showLogin'])->name('login');
Route::post('/login',   [AuthController::class, 'login']);
Route::post('/logout',  [AuthController::class, 'logout'])->name('logout');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register',[AuthController::class, 'register']);

Route::get('/health', function () {
    $checks  = [];
    $healthy = true;

    // ── Database ──────────────────────────────────────────────────────────
    try {
        DB::connection()->getPdo();
        $pendingJobs      = DB::table('jobs')->count();
        $failedJobs       = DB::table('failed_jobs')->count();
        $oldestPending    = DB::table('jobs')->orderBy('created_at')->value('created_at');
        $oldestAt         = $oldestPending ? Carbon::createFromTimestamp((int) $oldestPending) : null;
        $queueLagSeconds  = $oldestAt ? now()->diffInSeconds($oldestAt) : 0;

        $lastActivity = \App\Models\AgentTask::whereIn('status', ['completed', 'failed'])
            ->latest('updated_at')->value('updated_at');

        $checks['database'] = [
            'ok'                 => true,
            'pending_jobs'       => $pendingJobs,
            'failed_jobs'        => $failedJobs,
            'queue_lag_seconds'  => $queueLagSeconds,
            'worker_healthy'     => $queueLagSeconds < 300,
            'last_task_activity' => optional($lastActivity)?->toIso8601String(),
        ];

        // Check pgvector extension (PostgreSQL only)
        if (config('database.default') === 'pgsql') {
            $hasVector = DB::selectOne("SELECT COUNT(*) as cnt FROM pg_extension WHERE extname = 'vector'")?->cnt > 0;
            $checks['database']['pgvector'] = $hasVector ? 'enabled' : 'missing';
            if (! $hasVector) {
                $healthy = false;
            }
        }
    } catch (\Throwable $e) {
        $checks['database'] = ['ok' => false, 'error' => $e->getMessage()];
        $healthy = false;
    }

    // ── Redis ─────────────────────────────────────────────────────────────
    try {
        $pong = Redis::ping();
        $pongStr = is_object($pong) ? (string) $pong : (string) $pong;
        $checks['redis'] = ['ok' => str_contains(strtoupper($pongStr), 'PONG')];
    } catch (\Throwable $e) {
        $checks['redis'] = ['ok' => false, 'error' => $e->getMessage()];
        $healthy = false;
    }

    // ── Storage (local write test) ────────────────────────────────────────
    try {
        $testPath = 'health-check-' . time() . '.tmp';
        Storage::put($testPath, 'ok');
        Storage::delete($testPath);
        $checks['storage'] = ['ok' => true, 'disk' => config('filesystems.default')];
    } catch (\Throwable $e) {
        $checks['storage'] = ['ok' => false, 'error' => $e->getMessage()];
        $healthy = false;
    }

    // ── Cache ─────────────────────────────────────────────────────────────
    try {
        Cache::put('health-check', 'ok', 5);
        $val = Cache::get('health-check');
        $checks['cache'] = ['ok' => $val === 'ok', 'driver' => config('cache.default')];
    } catch (\Throwable $e) {
        $checks['cache'] = ['ok' => false, 'error' => $e->getMessage()];
        $healthy = false;
    }

    if (! $healthy) {
        Log::warning('Health check degraded', ['checks' => $checks]);
    }

    return response()->json([
        'status'    => $healthy ? 'healthy' : 'degraded',
        'timestamp' => now()->toIso8601String(),
        'ok'        => $healthy,
        'checks'    => $checks,
    ], $healthy ? 200 : 503);
});

Route::prefix('dashboard')->middleware(DashboardBasicAuth::class)->group(function () {
    Route::get('/',           [DashboardController::class, 'overview']);
    Route::get('/workflows',  [DashboardController::class, 'workflows']);
    Route::get('/jobs',       [DashboardController::class, 'jobs']);
    Route::get('/campaigns',          [DashboardController::class, 'campaigns']);
    Route::get('/campaigns/{id}',     [DashboardController::class, 'campaignDetail']);
    Route::get('/candidates', [DashboardController::class, 'candidates']);
    Route::get('/content',    [DashboardController::class, 'content']);
    Route::get('/system',     [DashboardController::class, 'system']);
    Route::get('/settings',   [SettingsController::class,  'index']);
    Route::get('/pipeline',   [DashboardController::class, 'pipeline']);
    Route::get('/knowledge',  [DashboardController::class, 'knowledge']);
    Route::get('/social',     [DashboardController::class, 'social']);

    // Social OAuth flows — all platforms (no throttle — user-initiated)
    Route::get('/social/auth/instagram/redirect',  [DashboardController::class, 'socialInstagramRedirect']);
    Route::get('/social/auth/instagram/callback',  [DashboardController::class, 'socialInstagramCallback']);
    Route::get('/social/auth/twitter/redirect',    [DashboardController::class, 'socialTwitterRedirect']);
    Route::get('/social/auth/twitter/callback',    [DashboardController::class, 'socialTwitterCallback']);
    Route::get('/social/auth/linkedin/redirect',   [DashboardController::class, 'socialLinkedInRedirect']);
    Route::get('/social/auth/linkedin/callback',   [DashboardController::class, 'socialLinkedInCallback']);
    Route::get('/social/auth/facebook/redirect',   [DashboardController::class, 'socialFacebookRedirect']);
    Route::get('/social/auth/facebook/callback',   [DashboardController::class, 'socialFacebookCallback']);
    Route::get('/social/auth/tiktok/redirect',     [DashboardController::class, 'socialTikTokRedirect']);
    Route::get('/social/auth/tiktok/callback',     [DashboardController::class, 'socialTikTokCallback']);
    Route::get('/social/auth/youtube/redirect',    [DashboardController::class, 'socialYouTubeRedirect']);
    Route::get('/social/auth/youtube/callback',    [DashboardController::class, 'socialYouTubeCallback']);

    Route::prefix('api')->group(function () {

        // ── Polling / read endpoints ─────────────────────────────────────────────
        Route::middleware('throttle:' . config('dashboard.throttle_read', 120) . ',1')->group(function () {
            Route::get('/stats',                       [DashboardController::class, 'apiStats']);
            Route::get('/workflows',                   [DashboardController::class, 'apiWorkflows']);
            Route::get('/workflows/{id}',              [DashboardController::class, 'apiWorkflowDetail']);
            Route::get('/jobs',                        [DashboardController::class, 'apiJobs']);
            Route::get('/campaigns',                   [DashboardController::class, 'apiCampaigns']);
            Route::get('/campaigns/{id}/intelligence', [PipelineActionController::class, 'campaignIntelligence']);
            Route::get('/campaigns/{id}/detail',       [DashboardController::class, 'apiCampaignDetail']);
            Route::get('/candidates',                  [DashboardController::class, 'apiCandidates']);
            Route::get('/candidates/{id}',             [DashboardController::class, 'apiCandidateDetail']);
            Route::get('/content',                     [DashboardController::class, 'apiContent']);
            Route::get('/content/{id}',                [DashboardController::class, 'apiContentDetail']);
            Route::get('/system-events',               [DashboardController::class, 'apiSystemEvents']);
            Route::get('/ai-costs',                    [DashboardController::class, 'apiAiCosts']);
            Route::get('/settings',                    [SettingsController::class,  'show']);
            Route::get('/pipeline',                    [DashboardController::class, 'apiPipeline']);
            Route::get('/knowledge',                   [DashboardController::class, 'apiKnowledge']);
            Route::get('/knowledge/import-status',     [DashboardController::class, 'apiKnowledgeImportStatus']);
            Route::get('/custom-platforms',            [DashboardController::class, 'apiCustomPlatforms']);
            Route::get('/variations/{jobId}',          [PipelineActionController::class, 'listVariations']);
            Route::get('/mcp-servers',                 [DashboardController::class, 'apiMcpServers']);

            // Social media — reads/polling
            Route::get('/content-calendar',            [DashboardController::class, 'apiContentCalendar']);
            Route::get('/hashtag-sets',                [DashboardController::class, 'apiHashtagSets']);
            Route::get('/social-accounts',             [DashboardController::class, 'apiSocialAccounts']);
            Route::get('/trend-insights',              [DashboardController::class, 'apiTrendInsights']);
            Route::get('/social/health',               [DashboardController::class, 'apiSocialHealth']);
            Route::get('/social-credentials',         [DashboardController::class, 'apiSocialCredentials']);
        });

        // ── Write / action endpoints ──────────────────────────────────────────────
        Route::middleware('throttle:' . config('dashboard.throttle_write', 60) . ',1')->group(function () {
            Route::post('/campaigns',                           [DashboardController::class,      'apiCreateCampaign']);
            Route::post('/campaigns/{id}/pause',               [DashboardController::class,      'apiPauseCampaign']);
            Route::post('/campaigns/{id}/resume',              [DashboardController::class,      'apiResumeCampaign']);
            Route::post('/workflows/{id}/approve',              [DashboardController::class,      'apiWorkflowApprove']);
            Route::post('/workflows/{id}/cancel',               [DashboardController::class,      'apiWorkflowCancel']);
            Route::post('/workflows/{id}/retry',                [DashboardController::class,      'apiWorkflowRetry']);
            Route::post('/settings',                            [SettingsController::class,       'update']);
            Route::post('/settings/telegram/webhook',           [SettingsController::class,       'registerWebhook']);
            Route::post('/platform',                            [DashboardController::class,      'savePlatform']);
            Route::post('/test-connection',                     [DashboardController::class,      'testConnection']);
            Route::post('/agents/{name}/prompt',                [DashboardController::class,      'apiUpdatePrompt']);
            Route::post('/knowledge',                           [DashboardController::class,      'apiKnowledgeCreate']);
            Route::delete('/knowledge/{id}',                    [DashboardController::class,      'apiKnowledgeDelete']);
            Route::post('/custom-platforms',                    [DashboardController::class,      'apiCustomPlatformCreate']);
            Route::delete('/custom-platforms/{id}',             [DashboardController::class,      'apiCustomPlatformDelete']);
            Route::post('/mcp-servers',                         [DashboardController::class,      'apiMcpServerCreate']);
            Route::delete('/mcp-servers/{id}',                  [DashboardController::class,      'apiMcpServerDelete']);
            Route::post('/pipeline/steps/{id}/skip',            [PipelineActionController::class, 'skipStep']);
            Route::post('/pipeline/jobs/{id}/retry',            [PipelineActionController::class, 'retryJob']);
            Route::post('/pipeline/jobs/{id}/promote-winner',   [PipelineActionController::class, 'promoteWinner']);
            Route::post('/pipeline/jobs/{id}/rerun-from-winner',[PipelineActionController::class, 'rerunFromWinner']);
            Route::post('/variations/{id}/performance',         [PipelineActionController::class, 'recordPerformance']);

            // Social media — writes/actions
            Route::post('/content-calendar',                    [DashboardController::class, 'apiCreateCalendarEntry']);
            Route::put('/content-calendar/{id}',                [DashboardController::class, 'apiUpdateCalendarEntry']);
            Route::delete('/content-calendar/{id}',             [DashboardController::class, 'apiDeleteCalendarEntry']);
            Route::post('/content-calendar/{id}/approve',       [DashboardController::class, 'apiApproveCalendarEntry']);
            Route::post('/content-calendar/{id}/reject',        [DashboardController::class, 'apiRejectCalendarEntry']);
            Route::post('/hashtag-sets',                        [DashboardController::class, 'apiCreateHashtagSet']);
            Route::delete('/hashtag-sets/{id}',                 [DashboardController::class, 'apiDeleteHashtagSet']);
            Route::post('/social-accounts',                     [DashboardController::class, 'apiUpsertSocialAccount']);
            Route::patch('/social-accounts/{id}',               [DashboardController::class, 'apiPatchSocialAccount']);
            Route::delete('/social-accounts/{id}',              [DashboardController::class, 'apiDeleteSocialAccount']);

            // Hiring: candidate resume intake + stage updates
            Route::post('/candidates/apply', [DashboardController::class, 'apiCandidateApply']);
            Route::patch('/candidates/{id}', [DashboardController::class, 'apiPatchCandidate']);

            // Social platform OAuth app credentials
            Route::post('/social-credentials', [DashboardController::class, 'apiStoreSocialCredentials']);
            Route::post('/social-credentials/{platform}/verify', [DashboardController::class, 'apiVerifySocialCredentials']);
            Route::post('/social-accounts/{id}/test', [DashboardController::class, 'apiTestSocialAccount']);
            Route::get('/social/quota-status', [DashboardController::class, 'apiSocialQuotaStatus']);

            // Intelligence layer
            Route::get('/intelligence/stats', [DashboardController::class, 'apiIntelligenceStats']);
        });

        // Intelligence dashboard page
        Route::get('/intelligence', [DashboardController::class, 'intelligence'])->name('dashboard.intelligence');

        // ── Heavy / expensive operations ──────────────────────────────────────────
        Route::middleware('throttle:' . config('dashboard.throttle_heavy', 30) . ',1')->group(function () {
            Route::post('/knowledge/github', [DashboardController::class, 'apiKnowledgeGitHub']);

            // Social media — heavy ops
            Route::post('/content-calendar/{id}/publish',       [DashboardController::class, 'apiPublishCalendarEntry']);
        });
    });
});
