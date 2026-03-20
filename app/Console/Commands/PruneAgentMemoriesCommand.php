<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prunes old agent_memories rows to prevent unbounded DB growth.
 * Schedule: daily  (see routes/console.php)
 */
class PruneAgentMemoriesCommand extends Command
{
    protected $signature   = 'agent:prune-memories {--days= : Override retention days}';
    protected $description = 'Delete agent memories older than the configured retention period.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('agent_system.memory_retention_days', 30));

        if ($days < 1) {
            $this->error('Retention days must be at least 1.');
            return self::FAILURE;
        }

        $cutoff  = now()->subDays($days);
        $deleted = DB::table('agent_memories')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} agent memory record(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
