<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentVariation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'agent_job_id',
        'variation_label',
        'content',
        'metadata',
        'is_winner',
        'created_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'is_winner'   => 'boolean',
        'created_at'  => 'datetime',
    ];

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'agent_job_id');
    }

    public function performance(): HasMany
    {
        return $this->hasMany(ContentPerformance::class, 'content_variation_id')
            ->orderBy('recorded_at', 'desc');
    }

    /**
     * Return the latest performance score for this variation, or 0.
     */
    public function latestScore(): float
    {
        return (float) ($this->performance()->value('score') ?? 0.0);
    }
}
