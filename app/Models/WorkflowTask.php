<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'workflow_id',
        'name',
        'type',              // agent_run | skill_exec | media_process
        'status',
        'sequence',
        'agent_type',
        'skill_name',
        'input',
        'output',
        'metadata',
        'error_message',
        'retry_count',
        'max_retries',
        'timeout_seconds',
        'depends_on_task_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'input'        => 'array',
        'output'       => 'array',
        'metadata'     => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function dependency(): ?WorkflowTask
    {
        return $this->depends_on_task_id
            ? self::find($this->depends_on_task_id)
            : null;
    }
}
