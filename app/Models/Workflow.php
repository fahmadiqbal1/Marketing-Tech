<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    use HasUuids, SoftDeletes;

    // ── Status constants ──────────────────────────────────────
    const STATUS_INTAKE            = 'intake';
    const STATUS_CONTEXT_RETRIEVAL = 'context_retrieval';
    const STATUS_PLANNING          = 'planning';
    const STATUS_TASK_EXECUTION    = 'task_execution';
    const STATUS_REVIEW            = 'review';
    const STATUS_OWNER_APPROVAL    = 'owner_approval';
    const STATUS_EXECUTION         = 'execution';
    const STATUS_OBSERVATION       = 'observation';
    const STATUS_LEARNING          = 'learning';
    const STATUS_COMPLETED         = 'completed';
    const STATUS_FAILED            = 'failed';
    const STATUS_CANCELLED         = 'cancelled';

    const VALID_TRANSITIONS = [
        'intake'            => ['context_retrieval', 'failed', 'cancelled'],
        'context_retrieval' => ['planning', 'failed'],
        'planning'          => ['task_execution', 'owner_approval', 'failed'],
        'task_execution'    => ['review', 'failed'],
        'review'            => ['owner_approval', 'execution', 'failed'],
        'owner_approval'    => ['execution', 'failed', 'cancelled'],
        'execution'         => ['observation', 'failed'],
        'observation'       => ['learning', 'completed'],
        'learning'          => ['completed'],
        'completed'         => [],
        'failed'            => ['intake'],
        'cancelled'         => [],
    ];

    const TERMINAL_STATES = ['completed', 'failed', 'cancelled'];

    protected $fillable = [
        'name', 'type', 'status', 'description',
        'input_payload', 'context', 'plan', 'output',
        'metadata', 'error_message',
        'retry_count', 'max_retries',
        'chat_id', 'user_id',
        'requires_approval', 'approval_granted',
        'approved_at', 'approved_by',
        'scheduled_at', 'started_at', 'completed_at',
        'current_task_id', 'priority',
        // legacy — kept for backwards compat during migration
        'instruction', 'result',
    ];

    protected $casts = [
        'input_payload'     => 'array',
        'context'           => 'array',
        'plan'              => 'array',
        'output'            => 'array',
        'metadata'          => 'array',
        'requires_approval' => 'boolean',
        'approval_granted'  => 'boolean',
        'approved_at'       => 'datetime',
        'scheduled_at'      => 'datetime',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
    ];

    // ── State machine helpers ─────────────────────────────────

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::VALID_TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus, ?string $error = null): bool
    {
        if (! $this->canTransitionTo($newStatus)) {
            \Illuminate\Support\Facades\Log::warning("Invalid workflow transition", [
                'id'   => $this->id,
                'from' => $this->status,
                'to'   => $newStatus,
            ]);
            return false;
        }

        $update = ['status' => $newStatus];

        if ($newStatus === self::STATUS_FAILED && $error) {
            $update['error_message'] = substr($error, 0, 1024);
        }

        if (in_array($newStatus, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            $update['completed_at'] = now();
        }

        if (in_array($newStatus, [self::STATUS_TASK_EXECUTION, self::STATUS_EXECUTION], true)
            && ! $this->started_at) {
            $update['started_at'] = now();
        }

        $this->update($update);
        return true;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATES, true);
    }

    // ── Logging helper ────────────────────────────────────────

    public function log(string $level, string $event, string $message, array $context = []): void
    {
        try {
            $this->logs()->create([
                'level'   => $level,
                'event'   => $event,
                'message' => $message,
                'context' => $context,
                'source'  => 'workflow',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Could not write workflow log: " . $e->getMessage());
        }
    }

    // ── Convenience accessors ─────────────────────────────────

    /**
     * Return input_payload['instruction'] falling back to name.
     */
    public function getInstructionAttribute(): string
    {
        return $this->input_payload['instruction']
            ?? $this->attributes['instruction']
            ?? $this->name;
    }

    // ── Relationships ─────────────────────────────────────────

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class)->orderBy('sequence');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class)->orderBy('logged_at');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function agentJobs(): HasMany
    {
        return $this->hasMany(AgentJob::class);
    }
}
