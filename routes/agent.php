<?php

use App\Http\Controllers\AgentController;
use App\Http\Middleware\CheckAgentToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent System Routes
| Registered by App\Providers\AgentSystemServiceProvider
|--------------------------------------------------------------------------
*/

Route::prefix('agent')->name('agent.')->group(function () {

    // UI (no auth required)
    Route::get('/', [AgentController::class, 'index'])->name('index');

    // Read-only (status / listing / config — no auth required)
    Route::get('/status/{id}', [AgentController::class, 'status'])->name('status')
        ->where('id', '[0-9]+');
    Route::get('/tasks',  [AgentController::class, 'taskList'])->name('tasks');
    Route::get('/config', [AgentController::class, 'getConfig'])->name('config');

    // State-mutating endpoints: throttled + optional token auth
    Route::middleware(['throttle:10,1', CheckAgentToken::class])->group(function () {
        Route::post('/run',          [AgentController::class, 'run'])->name('run');
        Route::post('/pause/{id}',   [AgentController::class, 'pause'])->name('pause')
            ->where('id', '[0-9]+');
        Route::post('/resume/{id}',  [AgentController::class, 'resume'])->name('resume')
            ->where('id', '[0-9]+');
        Route::post('/update-api',   [AgentController::class, 'updateApi'])->name('update-api');
    });
});
