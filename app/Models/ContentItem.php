<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
class ContentItem extends Model {
    use HasUuids, SoftDeletes;
    protected $fillable = [
        'title', 'body', 'type', 'platform', 'status', 'tags',
        'scheduled_at', 'word_count', 'agent_run_id',
        'char_count', 'seo_analysis', 'performance_metrics',
        'published_at', 'agent_job_id',
    ];
    protected $casts = [
        'tags'                => 'array',
        'scheduled_at'        => 'datetime',
        'published_at'        => 'datetime',
        'seo_analysis'        => 'array',
        'performance_metrics' => 'array',
        'char_count'          => 'integer',
    ];
}
