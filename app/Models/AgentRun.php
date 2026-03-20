<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'workflow_id', 'workflow_task_id',
        'agent_class', 'agent_type', 'status',
        'instruction', 'messages', 'tool_calls',
        'result', 'error_message',
        'steps_taken', 'max_steps', 'last_tool',
        'model_used', 'provider',
        'tokens_in', 'tokens_out', 'cost_usd',
        'chat_id', 'user_id', 'metadata',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'messages'     => 'array',
        'tool_calls'   => 'array',
        'metadata'     => 'array',
        'cost_usd'     => 'float',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkflowTask::class, 'workflow_task_id');
    }

    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }
}
