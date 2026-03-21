<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedOutput extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'agent_job_id',
        'type',
        'content',
        'version',
        'is_winner',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'is_winner'  => 'boolean',
        'version'    => 'integer',
        'created_at' => 'datetime',
    ];

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'agent_job_id');
    }
}
