<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'workflow_id', 'campaign_id', 'agent_type', 'agent_class', 'ai_provider', 'model',
        'instruction', 'short_description', 'status',
        'result', 'error_message', 'steps_taken', 'total_tokens', 'last_tool', 'metadata',
        'chat_id', 'user_id', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'total_tokens' => 'integer',
        'steps_taken'  => 'integer',
    ];

    public function agentSteps(): HasMany
    {
        return $this->hasMany(AgentStep::class, 'agent_job_id')->orderBy('step_number');
    }

    public function contentVariations(): HasMany
    {
        return $this->hasMany(ContentVariation::class, 'agent_job_id');
    }

    public function generatedOutputs(): HasMany
    {
        return $this->hasMany(GeneratedOutput::class, 'agent_job_id');
    }
}
