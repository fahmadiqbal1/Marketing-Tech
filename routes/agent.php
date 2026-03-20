<?php

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent System Routes
| Registered by App\Providers\AgentSystemServiceProvider
|--------------------------------------------------------------------------
*/

Route::prefix('agent')->name('agent.')->group(function () {

    // UI
    Route::get('/',           [AgentController::class, 'index'])->name('index');

    // Task lifecycle
    Route::post('/run',          [AgentController::class, 'run'])->name('run');
    Route::get('/status/{id}',   [AgentController::class, 'status'])->name('status')
        ->where('id', '[0-9]+');
    Route::post('/pause/{id}',   [AgentController::class, 'pause'])->name('pause')
        ->where('id', '[0-9]+');
    Route::post('/resume/{id}',  [AgentController::class, 'resume'])->name('resume')
        ->where('id', '[0-9]+');

    // Task listing
    Route::get('/tasks',         [AgentController::class, 'taskList'])->name('tasks');

    // API key management
    Route::post('/update-api',   [AgentController::class, 'updateApi'])->name('update-api');
    Route::get('/config',        [AgentController::class, 'getConfig'])->name('config');
});
