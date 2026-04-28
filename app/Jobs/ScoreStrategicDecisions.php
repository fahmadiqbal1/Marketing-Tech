<?php

namespace App\Jobs;

use App\Services\StrategicLearningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScoreStrategicDecisions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 90;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(StrategicLearningService $learning): void
    {
        // Score unscored decisions older than 30 minutes (enough time for outcomes to arrive)
        $unscored = DB::table('strategic_decisions')
            ->whereNull('outcome_score')
            ->where('created_at', '<', now()->subMinutes(30))
            ->limit(50)
            ->pluck('id');

        $count = 0;
        foreach ($unscored as $id) {
            $learning->scoreDecision($id);
            $count++;
        }

        // Recalculate domain ROI from outcomes
        $learning->recalculateDomainRoi();

        Log::info("ScoreStrategicDecisions: {$count} decisions processed");
    }
}
