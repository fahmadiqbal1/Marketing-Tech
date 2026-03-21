<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap Horizon services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * Access is granted to: local/dev environments, or authenticated users.
     * The web middleware (configured in horizon.php) handles session handling.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return app()->environment('local')
                || auth()->check();
        });
    }
}
