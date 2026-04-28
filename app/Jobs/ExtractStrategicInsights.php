<?php

namespace App\Jobs;

use App\Services\InsightExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractStrategicInsights implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(InsightExtractionService $service): void
    {
        $count = $service->extract(days: 30);
        Log::info("ExtractStrategicInsights: {$count} insights extracted/refreshed");
    }
}
