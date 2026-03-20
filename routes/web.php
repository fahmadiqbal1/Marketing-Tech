<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// ── Root redirect ──────────────────────────────────────────────────────
Route::get('/', fn() => redirect('/dashboard'));

// ── Health ─────────────────────────────────────────────────────────────
Route::get('/health', fn() => response()->json(['status' => 'healthy', 'timestamp' => now()->toIso8601String()]));

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
