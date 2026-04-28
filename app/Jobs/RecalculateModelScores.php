<?php

namespace App\Jobs;

use App\Services\AI\BanditModelSelector;
use App\Services\BudgetAllocator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecalculateModelScores implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        BanditModelSelector::recalculateScores();
        BudgetAllocator::rebalance();

        Log::info('RecalculateModelScores: UCB1 scores and budgets recalculated');
    }
}
