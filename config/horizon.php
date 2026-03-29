<?php

use Laravel\Horizon\Horizon;

Horizon::routeSmsNotificationsTo('');
Horizon::routeSlackNotificationsTo('', '#ops-alerts');

return [
    'domain'  => env('HORIZON_DOMAIN'),
    'path'    => 'horizon',
    'use'     => 'default',
    'prefix'  => env('HORIZON_PREFIX', 'ops:horizon:'),

    'middleware' => ['web'],

    'waits'  => ['redis:default' => 60],
    'trim'   => [
        'recent'           => 60,
        'pending'          => 60,
        'completed'        => 60,
        'recent_failed'    => 10080,
        'failed'           => 10080,
        'monitored'        => 10080,
    ],

    'silenced' => [],
    'metrics'  => [
        'trim_snapshots' => [
            'job'  => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,
    'memory_limit'     => 512,

    'defaults' => [
        'supervisor-1' => [
            'connection'    => 'redis',
            'queue'         => ['default'],
            'balance'       => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'  => 1,
            'maxProcesses'  => 5,
            'maxTime'       => 0,
            'maxJobs'       => 0,
            'memory'        => 256,
            'tries'         => 3,
            'timeout'       => 120,
            'nice'          => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-marketing' => [
                'connection'   => 'redis',
                'queue'        => ['marketing'],
                'balance'      => 'simple',
                'processes'    => 3,
                'tries'        => 3,
                'timeout'      => 300,
                'memory'       => 256,
            ],
            'supervisor-media' => [
                'connection'   => 'redis',
                'queue'        => ['media'],
                'balance'      => 'simple',
                'processes'    => 2,
                'tries'        => 2,
                'timeout'      => 600,
                'memory'       => 512,
            ],
            'supervisor-hiring' => [
                'connection'   => 'redis',
                'queue'        => ['hiring'],
                'balance'      => 'simple',
                'processes'    => 2,
                'tries'        => 3,
                'timeout'      => 180,
            ],
            'supervisor-content' => [
                'connection'   => 'redis',
                'queue'        => ['content'],
                'balance'      => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 8,
                'tries'        => 3,
                'timeout'      => 240,
            ],
            'supervisor-growth' => [
                'connection'   => 'redis',
                'queue'        => ['growth'],
                'balance'      => 'simple',
                'processes'    => 2,
                'tries'        => 3,
                'timeout'      => 300,
            ],
            'supervisor-knowledge' => [
                'connection'   => 'redis',
                'queue'        => ['knowledge'],
                'balance'      => 'simple',
                'processes'    => 2,
                'tries'        => 3,
                'timeout'      => 180,
            ],
            // Agent tasks: isolated from default queue — never starved by ingestion
            'supervisor-agents' => [
                'connection'   => 'redis',
                'queue'        => ['agents'],
                'balance'      => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 10,
                'tries'        => 3,
                'timeout'      => 660,   // must exceed RunAgentTask::$timeout (600s)
                'memory'       => 256,
            ],
            // General work (webhooks, notifications, misc)
            'supervisor-default' => [
                'connection'   => 'redis',
                'queue'        => ['default'],
                'balance'      => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 5,
                'tries'        => 3,
                'timeout'      => 120,
            ],
            // Social posting: DispatchScheduledPosts runs every minute on this queue
            'supervisor-social' => [
                'connection'   => 'redis',
                'queue'        => ['social'],
                'balance'      => 'simple',
                'processes'    => 3,
                'tries'        => 3,
                'timeout'      => 120,
            ],
            // Low-priority background ingestion (GitHub, bulk imports) — isolated
            'supervisor-low' => [
                'connection'   => 'redis',
                'queue'        => ['low'],
                'balance'      => 'simple',
                'processes'    => 2,
                'tries'        => 2,
                'timeout'      => 360,
                'memory'       => 256,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection'   => 'redis',
                'queue'        => ['agents', 'default', 'marketing', 'media', 'hiring', 'content', 'growth', 'knowledge', 'social'],
                'balance'      => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 8,
                'tries'        => 3,
                'timeout'      => 660,
            ],
            'supervisor-low' => [
                'connection'   => 'redis',
                'queue'        => ['low'],
                'balance'      => 'simple',
                'processes'    => 2,
                'tries'        => 2,
                'timeout'      => 360,
            ],
        ],
    ],
];
