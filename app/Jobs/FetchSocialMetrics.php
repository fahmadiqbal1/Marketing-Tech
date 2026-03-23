<?php

namespace App\Jobs;

use App\Models\ContentCalendar;
use App\Models\ContentPerformance;
use App\Models\SocialAccount;
use App\Models\SystemEvent;
use App\Services\IterationEngineService;
use App\Services\Social\SocialPlatformService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchSocialMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(SocialPlatformService $social, IterationEngineService $iteration): void
    {
        // Published entries with an external_post_id, published in the last 30 days
        $entries = ContentCalendar::where('status', 'published')
            ->whereNotNull('external_post_id')
            ->where('published_at', '>=', now()->subDays(30))
            ->get();

        if ($entries->isEmpty()) {
            return;
        }

        Log::info("FetchSocialMetrics: fetching metrics for {$entries->count()} posts");

        $fetched = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            try {
                $account = SocialAccount::connected()->forPlatform($entry->platform)->first();

                if (! $account) {
                    continue;
                }

                $metrics = $social->driver($entry->platform)->fetchMetrics($account, $entry->external_post_id);

                // Store or update content_performance record
                ContentPerformance::updateOrCreate(
                    ['content_variation_id' => $entry->content_variation_id ?? $entry->id],
                    [
                        'impressions' => $metrics['impressions'],
                        'clicks' => $metrics['clicks'],
                        'conversions' => $metrics['conversions'] ?? 0,
                        'metadata' => array_merge($metrics, ['platform' => $entry->platform, 'post_id' => $entry->external_post_id]),
                    ]
                );

                // Feed IterationEngine with real metrics
                if ($entry->content_variation_id) {
                    $iteration->recordPerformance(
                        $entry->content_variation_id,
                        $metrics['impressions'],
                        $metrics['clicks'],
                        $metrics['conversions'] ?? 0,
                        'real'
                    );
                }

                // Update account engagement rate (rolling average)
                if ($metrics['impressions'] > 0) {
                    $engagementRate = ($metrics['engagement'] ?? $metrics['clicks']) / $metrics['impressions'];
                    $account->update([
                        'avg_engagement_rate' => round(($account->avg_engagement_rate + $engagementRate) / 2, 4),
                        'last_synced_at' => now(),
                    ]);
                }

                $fetched++;

            } catch (\Throwable $e) {
                $failed++;
                Log::warning("FetchSocialMetrics: failed for entry {$entry->id}", ['error' => $e->getMessage()]);
            }
        }

        if ($fetched > 0 || $failed > 0) {
            SystemEvent::create([
                'level' => $failed > 0 ? 'warning' : 'info',
                'message' => "FetchSocialMetrics: fetched={$fetched}, failed={$failed}",
            ]);
        }
    }
}
