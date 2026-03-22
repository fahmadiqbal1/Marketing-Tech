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

    Route::prefix('api')->group(function () {
        Route::get('/stats',                      [DashboardController::class, 'apiStats']);
        Route::get('/workflows',                  [DashboardController::class, 'apiWorkflows']);
        Route::get('/workflows/{id}',             [DashboardController::class, 'apiWorkflowDetail']);
        Route::post('/workflows/{id}/approve',    [DashboardController::class, 'apiWorkflowApprove']);
        Route::post('/workflows/{id}/cancel',     [DashboardController::class, 'apiWorkflowCancel']);
        Route::post('/workflows/{id}/retry',      [DashboardController::class, 'apiWorkflowRetry']);
        Route::get('/jobs',                       [DashboardController::class, 'apiJobs']);
        Route::get('/campaigns',                  [DashboardController::class, 'apiCampaigns']);
        Route::get('/candidates',                 [DashboardController::class, 'apiCandidates']);
        Route::get('/content',                    [DashboardController::class, 'apiContent']);
        Route::get('/content/{id}',               [DashboardController::class, 'apiContentDetail']);
        Route::get('/system-events',              [DashboardController::class, 'apiSystemEvents']);
        Route::get('/ai-costs',                   [DashboardController::class, 'apiAiCosts']);

        Route::get('/settings',                   [SettingsController::class, 'show']);
        Route::post('/settings',                  [SettingsController::class, 'update']);
        Route::post('/settings/telegram/webhook', [SettingsController::class, 'registerWebhook']);

        // Pipeline & Knowledge
        Route::get('/pipeline',                   [DashboardController::class, 'apiPipeline']);
        Route::get('/knowledge',                  [DashboardController::class, 'apiKnowledge']);
        Route::post('/knowledge',                 [DashboardController::class, 'apiKnowledgeCreate']);
        Route::post('/knowledge/github',          [DashboardController::class, 'apiKnowledgeGitHub']);
        Route::delete('/knowledge/{id}',          [DashboardController::class, 'apiKnowledgeDelete']);
        Route::post('/agents/{name}/prompt',      [DashboardController::class, 'apiUpdatePrompt']);
        Route::post('/platform',                  [DashboardController::class, 'savePlatform']);
        Route::post('/test-connection',           [DashboardController::class, 'testConnection']);
        Route::get('/custom-platforms',           [DashboardController::class, 'apiCustomPlatforms']);
        Route::post('/custom-platforms',          [DashboardController::class, 'apiCustomPlatformCreate']);
        Route::delete('/custom-platforms/{id}',   [DashboardController::class, 'apiCustomPlatformDelete']);

        // Pipeline actions (Phase 4)
        Route::post('/pipeline/steps/{id}/skip',           [PipelineActionController::class, 'skipStep']);
        Route::post('/pipeline/jobs/{id}/retry',           [PipelineActionController::class, 'retryJob']);

        // Winner promotion & re-run (Phase 5)
        Route::post('/pipeline/jobs/{id}/promote-winner',  [PipelineActionController::class, 'promoteWinner']);
        Route::post('/pipeline/jobs/{id}/rerun-from-winner', [PipelineActionController::class, 'rerunFromWinner']);

        // Variations & performance
        Route::get('/variations/{jobId}',         [PipelineActionController::class, 'listVariations']);
        Route::post('/variations/{id}/performance', [PipelineActionController::class, 'recordPerformance']);

        // Campaign intelligence
        Route::get('/campaigns/{id}/intelligence', [PipelineActionController::class, 'campaignIntelligence']);
        Route::get('/campaigns/{id}/detail',       [DashboardController::class, 'apiCampaignDetail']);
    });
});
