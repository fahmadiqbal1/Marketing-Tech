<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Artifact extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'workflow_id', 'agent_run_id', 'parent_artifact_id',
        'type', 'name', 'content',
        'storage_key', 'storage_bucket', 'mime_type', 'file_size_bytes',
        'metadata', 'approved', 'approved_at', 'approved_by',
        'version', 'is_final',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'approved'    => 'boolean',
        'is_final'    => 'boolean',
        'approved_at' => 'datetime',
        'file_size_bytes' => 'integer',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(Artifact::class, 'parent_artifact_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Artifact::class, 'parent_artifact_id');
    }

    public function getTemporaryUrlAttribute(): ?string
    {
        if (! $this->storage_key) {
            return null;
        }
        try {
            return Storage::disk('minio')->temporaryUrl($this->storage_key, now()->addHours(2));
        } catch (\Throwable) {
            return null;
        }
    }
}
