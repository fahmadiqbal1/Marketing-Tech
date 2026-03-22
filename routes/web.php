<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PipelineActionController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\DashboardBasicAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', fn() => redirect('/dashboard'));

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        $lastActivity = \App\Models\AgentTask::whereIn('status', ['completed', 'failed'])
            ->latest('updated_at')
            ->value('updated_at');

        $oldestPending = DB::table('jobs')->orderBy('created_at')->value('created_at');
        $oldestPendingAt = $oldestPending ? Carbon::createFromTimestamp((int) $oldestPending) : null;
        $queueLagSeconds = $oldestPendingAt ? now()->diffInSeconds($oldestPendingAt) : 0;

        return response()->json([
            'status'             => 'healthy',
            'timestamp'          => now()->toIso8601String(),
            'database'           => 'reachable',
            'queue_pending_jobs' => $pendingJobs,
            'queue_failed_jobs'  => $failedJobs,
            'queue_lag_seconds'  => $queueLagSeconds,
            'last_task_activity' => optional($lastActivity)?->toIso8601String(),
            'worker_healthy'     => $queueLagSeconds < 300,
        ]);
    } catch (\Throwable $e) {
        Log::warning('Health endpoint degraded', ['error' => $e->getMessage()]);

        return response()->json([
            'status'    => 'degraded',
            'timestamp' => now()->toIso8601String(),
            'database'  => 'unavailable',
            'message'   => 'The application booted, but the configured database is not ready.',
            'details'   => str_contains($e->getMessage(), 'could not find driver')
                ? 'Install the configured database driver or switch DB_CONNECTION to a supported driver for local development.'
                : 'Check database connectivity, migrations, and runtime configuration.',
            'worker_healthy' => false,
        ], 503);
    }
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

    // Instagram OAuth (no throttle — user-initiated redirect/callback)
    Route::get('/social/auth/instagram/redirect',  [DashboardController::class, 'socialInstagramRedirect']);
    Route::get('/social/auth/instagram/callback',  [DashboardController::class, 'socialInstagramCallback']);

    Route::prefix('api')->group(function () {

        // ── Polling / read endpoints — 60 req/min (safe for 2 tabs + auto-refresh) ──
        Route::middleware('throttle:60,1')->group(function () {
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

            // Social media — reads/polling
            Route::get('/content-calendar',            [DashboardController::class, 'apiContentCalendar']);
            Route::get('/hashtag-sets',                [DashboardController::class, 'apiHashtagSets']);
            Route::get('/social-accounts',             [DashboardController::class, 'apiSocialAccounts']);
            Route::get('/trend-insights',              [DashboardController::class, 'apiTrendInsights']);
            Route::get('/social/health',               [DashboardController::class, 'apiSocialHealth']);
        });

        // ── Write / action endpoints — 10 req/min ────────────────────────────────
        Route::middleware('throttle:10,1')->group(function () {
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
            Route::delete('/social-accounts/{id}',              [DashboardController::class, 'apiDeleteSocialAccount']);
        });

        // ── Heavy / expensive operations — 5 req/min ────────────────────────────
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('/knowledge/github', [DashboardController::class, 'apiKnowledgeGitHub']);

            // Social media — heavy ops
            Route::post('/content-calendar/{id}/publish',       [DashboardController::class, 'apiPublishCalendarEntry']);
        });
    });
});
