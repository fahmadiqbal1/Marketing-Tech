<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentCalendar extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'content_calendar';

    protected $fillable = [
        'title', 'platform', 'content_type', 'draft_content',
        'status', 'moderation_status', 'moderation_notes',
        'scheduled_at', 'published_at',
        'agent_job_id', 'content_variation_id', 'campaign_id', 'external_post_id',
        'hashtags', 'metadata',
        'retry_count', 'last_error',
    ];

    protected $casts = [
        'hashtags'      => 'array',
        'metadata'      => 'array',
        'scheduled_at'  => 'datetime',
        'published_at'  => 'datetime',
        'retry_count'   => 'integer',
    ];

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'agent_job_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function scopeScheduledNow($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->where('retry_count', '<', 3);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
