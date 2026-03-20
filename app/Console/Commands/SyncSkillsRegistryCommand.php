<?php

namespace App\Console\Commands;

use App\Services\Skills\SkillExecutorService;
use Illuminate\Console\Command;

class SyncSkillsRegistryCommand extends Command
{
    protected $signature   = 'skills:sync';
    protected $description = 'Sync skill class definitions into the skills_registry database table';

    public function handle(SkillExecutorService $executor): int
    {
        $this->info('Syncing skills registry...');
        $executor->syncRegistry();
        $this->info('Skills registry synced successfully.');
        return Command::SUCCESS;
    }
}
