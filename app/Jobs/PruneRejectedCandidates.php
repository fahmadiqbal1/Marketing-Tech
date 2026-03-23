<?php

namespace App\Jobs;

use App\Models\Candidate;
use App\Models\SystemEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PruneRejectedCandidates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        try {
            $cutoff = now()->subDays(30);

            $count = Candidate::where('pipeline_stage', 'rejected')
                ->where('stage_updated_at', '<', $cutoff)
                ->count();

            if ($count === 0) {
                return;
            }

            Candidate::where('pipeline_stage', 'rejected')
                ->where('stage_updated_at', '<', $cutoff)
                ->forceDelete();

            SystemEvent::create([
                'event_type'  => 'candidates_pruned',
                'severity'    => 'info',
                'source'      => 'prune_rejected_candidates',
                'message'     => "Pruned {$count} rejected candidate(s) older than 30 days",
                'payload'     => ['count' => $count, 'cutoff' => $cutoff->toDateString()],
                'occurred_at' => now(),
            ]);

            Log::info("PruneRejectedCandidates: removed {$count} records");
        } catch (\Throwable $e) {
            Log::error('PruneRejectedCandidates failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
