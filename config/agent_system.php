<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    | Supported: "openai", "gemini"
    */
    'default_provider' => env('AGENT_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Settings
    |--------------------------------------------------------------------------
    */
    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model'   => env('AGENT_OPENAI_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | Google Gemini Settings
    |--------------------------------------------------------------------------
    */
    'gemini_api_key' => env('GEMINI_API_KEY'),
    'gemini_model'   => env('AGENT_GEMINI_MODEL', 'gemini-1.5-flash'),

    /*
    |--------------------------------------------------------------------------
    | Agent Constraints
    |--------------------------------------------------------------------------
    */
    'max_steps_per_task' => env('AGENT_MAX_STEPS', 10),
    'max_retries'        => env('AGENT_MAX_RETRIES', 2),
    'request_timeout'    => env('AGENT_REQUEST_TIMEOUT', 90),    // seconds

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    | Set to null to use the default queue connection.
    */
    'queue_connection' => env('AGENT_QUEUE_CONNECTION', null),
    'queue_name'       => env('AGENT_QUEUE_NAME', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Access Token (optional)
    |--------------------------------------------------------------------------
    | If set, all mutating agent endpoints require the header:
    |   X-Agent-Token: <value>
    | Leave empty for open access (development / trusted internal networks).
    */
    'access_token' => env('AGENT_ACCESS_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Memory Retention
    |--------------------------------------------------------------------------
    | agent_memories rows older than this many days are pruned automatically.
    */
    'memory_retention_days' => env('AGENT_MEMORY_RETENTION_DAYS', 30),

];
