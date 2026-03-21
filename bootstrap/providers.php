<?php

/**
 * Additional service providers for the Marketing-Tech platform.
 * Laravel 11 reads this file automatically during bootstrap.
 * The framework's default providers (AppServiceProvider, etc.) are still loaded.
 */
return [
    App\Providers\AgentSystemServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
