<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    protected $table = 'ai_requests';

    // ai_requests has no updated_at column
    const UPDATED_AT = null;
    protected $fillable = [
        'agent_run_id',
        'agent_task_id',
        'workflow_id',
        'provider',
        'model',
        'request_type',
        'tokens_in',
        'tokens_out',
        'cost_usd',
        'duration_ms',
        'status',
        'http_status',
        'error_message',
        'retry_number',
        'used_fallback',
        'fallback_model',
        'request_metadata',
        'requested_at',
    ];

    protected $casts = [
        'request_metadata' => 'array',
        'used_fallback'    => 'boolean',
        'cost_usd'         => 'float',
        'requested_at'     => 'datetime',
    ];

    // Use requested_at as the created_at equivalent
    const CREATED_AT = 'requested_at';
}
