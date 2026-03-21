<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// ── Root redirect ──────────────────────────────────────────────────────
Route::get('/', fn() => redirect('/dashboard'));

// ── Health ─────────────────────────────────────────────────────────────
Route::get('/health', function () {
    $pendingJobs  = \Illuminate\Support\Facades\DB::table('jobs')->count();
    $failedJobs   = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();

    // Last job that completed (approximated by most recent agent_task update)
    $lastActivity = \App\Models\AgentTask::whereIn('status', ['completed', 'failed'])
        ->latest('updated_at')
        ->value('updated_at');

    // Queue lag: time since oldest pending job was queued
    $oldestPending = \Illuminate\Support\Facades\DB::table('jobs')
        ->orderBy('created_at')
        ->value('created_at');

    $queueLagSeconds = $oldestPending
        ? now()->diffInSeconds(\Illuminate\Support\Carbon\Carbon::createFromTimestamp($oldestPending))
        : 0;

    return response()->json([
        'status'              => 'healthy',
        'timestamp'           => now()->toIso8601String(),
        'queue_pending_jobs'  => $pendingJobs,
        'queue_failed_jobs'   => $failedJobs,
        'queue_lag_seconds'   => $queueLagSeconds,
        'last_task_activity'  => $lastActivity,
        'worker_healthy'      => $queueLagSeconds < 300, // warn if lag > 5 min
    ]);
});

// ── Dashboard pages ────────────────────────────────────────────────────
Route::prefix('dashboard')->group(function () {

    Route::get('/',            [DashboardController::class, 'overview']);
    Route::get('/workflows',   [DashboardController::class, 'workflows']);
    Route::get('/jobs',        [DashboardController::class, 'jobs']);
    Route::get('/campaigns',   [DashboardController::class, 'campaigns']);
    Route::get('/candidates',  [DashboardController::class, 'candidates']);
    Route::get('/content',     [DashboardController::class, 'content']);
    Route::get('/system',      [DashboardController::class, 'system']);
    Route::get('/settings',    [SettingsController::class,  'index']);

    // ── JSON data endpoints (no auth for local dev) ────────────────
    Route::prefix('api')->group(function () {
        Route::get('/stats',                         [DashboardController::class, 'apiStats']);
        Route::get('/workflows',                     [DashboardController::class, 'apiWorkflows']);
        Route::get('/workflows/{id}',                [DashboardController::class, 'apiWorkflowDetail']);
        Route::post('/workflows/{id}/approve',       [DashboardController::class, 'apiWorkflowApprove']);
        Route::post('/workflows/{id}/cancel',        [DashboardController::class, 'apiWorkflowCancel']);
        Route::post('/workflows/{id}/retry',         [DashboardController::class, 'apiWorkflowRetry']);
        Route::get('/jobs',                          [DashboardController::class, 'apiJobs']);
        Route::get('/campaigns',                     [DashboardController::class, 'apiCampaigns']);
        Route::get('/candidates',                    [DashboardController::class, 'apiCandidates']);
        Route::get('/content',                       [DashboardController::class, 'apiContent']);
        Route::get('/system-events',                 [DashboardController::class, 'apiSystemEvents']);
        Route::get('/ai-costs',                      [DashboardController::class, 'apiAiCosts']);

        Route::get('/settings',                      [SettingsController::class, 'show']);
        Route::post('/settings',                     [SettingsController::class, 'update']);
        Route::post('/settings/telegram/webhook',    [SettingsController::class, 'registerWebhook']);
    });
});
