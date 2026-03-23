<?php

namespace App\Jobs;

use App\Models\Candidate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PruneRejectedCandidates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cutoff = Carbon::now()->subDays(30);
        $count = Candidate::where('pipeline_stage', 'rejected')
            ->where('updated_at', '<', $cutoff)
            ->delete();
        Log::info("Pruned $count rejected candidates older than 30 days");
    }
}
