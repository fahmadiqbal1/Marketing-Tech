<?php

namespace App\Console\Commands;

use App\Services\Supervisor\SupervisorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SupervisorTickCommand extends Command
{
    protected $signature   = 'supervisor:tick';
    protected $description = 'Run one supervisor monitoring tick — detect stuck jobs, retry failures, send alerts';

    public function handle(SupervisorService $supervisor): int
    {
        try {
            $supervisor->tick();
            $this->info('Supervisor tick completed at ' . now()->toTimeString());
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            Log::error("Supervisor tick error", ['error' => $e->getMessage()]);
            $this->error("Supervisor tick failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
