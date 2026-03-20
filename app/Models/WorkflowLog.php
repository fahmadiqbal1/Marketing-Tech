<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workflow_id',
        'workflow_task_id',
        'agent_run_id',
        'level',
        'event',
        'message',
        'context',
        'source',
        'logged_at',
    ];

    protected $casts = [
        'context'   => 'array',
        'logged_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
