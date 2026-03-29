<?php

return [
    'username' => env('DASHBOARD_USERNAME'),
    'password' => env('DASHBOARD_PASSWORD'),

    /*
     * Throttle limits for the dashboard API routes.
     * Override via .env to suit your load profile without code changes.
     * Defaults are generous: 120 reads/min, 60 writes/min, 30 heavy/min.
     */
    'throttle_read'  => (int) env('DASHBOARD_THROTTLE_READ',  120),
    'throttle_write' => (int) env('DASHBOARD_THROTTLE_WRITE', 60),
    'throttle_heavy' => (int) env('DASHBOARD_THROTTLE_HEAVY', 30),
];
