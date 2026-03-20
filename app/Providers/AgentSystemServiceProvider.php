<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * AgentSystemServiceProvider – registers all Agent System routes and bindings.
 * Loaded via bootstrap/providers.php (Laravel 11 style).
 * Does NOT touch any existing provider or route file.
 */
class AgentSystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge agent_system config (allows values to be overridden in app config)
        $this->mergeConfigFrom(
            config_path('agent_system.php'),
            'agent_system'
        );

        // Bind AgentRunner as singleton
        $this->app->singleton(\App\AgentSystem\AgentRunner::class);
    }

    public function boot(): void
    {
        // Register agent routes under the web middleware group
        Route::middleware('web')
            ->group(base_path('routes/agent.php'));
    }
}
