<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentTask extends Model
{
    protected $fillable = [
        'campaign_id',
        'user_input',
        'status',
        'current_step',
        'final_output',
        'ai_provider',
        'model',
        'total_tokens',
        'total_latency_ms',
        'error_message',
    ];

    protected $casts = [
        'final_output'     => 'array',
        'current_step'     => 'integer',
        'total_tokens'     => 'integer',
        'total_latency_ms' => 'integer',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(AgentStep::class, 'task_id')->orderBy('step_number');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }
}
