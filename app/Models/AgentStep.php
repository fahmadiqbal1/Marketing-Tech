<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentStep extends Model
{
    protected $fillable = [
        'task_id',
        'agent_job_id',
        'step_number',
        'agent_name',
        'thought',
        'action',
        'parameters',
        'result',
        'knowledge_chunks_used',
        'from_cache',
        'status',
        'tokens_used',
        'latency_ms',
        'retry_count',
    ];

    protected $casts = [
        'parameters'           => 'array',
        'result'               => 'array',
        'knowledge_chunks_used'=> 'array',
        'from_cache'           => 'boolean',
        'tokens_used'          => 'integer',
        'latency_ms'           => 'integer',
        'retry_count'          => 'integer',
        'step_number'          => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'task_id');
    }

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'agent_job_id');
    }
}
