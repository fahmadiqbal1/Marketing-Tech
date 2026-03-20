<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentStep extends Model
{
    protected $fillable = [
        'task_id',
        'step_number',
        'agent_name',
        'thought',
        'action',
        'parameters',
        'result',
        'status',
        'tokens_used',
        'latency_ms',
        'retry_count',
    ];

    protected $casts = [
        'parameters'  => 'array',
        'result'      => 'array',
        'tokens_used' => 'integer',
        'latency_ms'  => 'integer',
        'retry_count' => 'integer',
        'step_number' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'task_id');
    }
}
