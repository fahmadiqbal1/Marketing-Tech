<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

foreach ([
    __DIR__.'/../storage/app/temp',
    __DIR__.'/../storage/framework/cache/data',
    __DIR__.'/../storage/framework/sessions',
    __DIR__.'/../storage/framework/testing',
    __DIR__.'/../storage/framework/views',
    __DIR__.'/../storage/logs',
] as $directory) {
    if (! is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
}

// If the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
