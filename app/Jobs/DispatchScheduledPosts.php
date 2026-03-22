<?php

namespace App\Jobs;

use App\Models\ContentCalendar;
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

class DispatchScheduledPosts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function handle(SocialPlatformService $social, IterationEngineService $iteration): void
    {
        $entries = ContentCalendar::scheduledNow()->get();

        if ($entries->isEmpty()) {
            return;
        }

        Log::info("DispatchScheduledPosts: processing {$entries->count()} entries");

        foreach ($entries as $entry) {
            try {
                $posted = false;
                $result = [];

                if ($social->autoPostEnabled()) {
                    $account = SocialAccount::connected()->forPlatform($entry->platform)->first();

                    if ($account) {
                        try {
                            $result = $social->publishWithRateLimit($account, $entry);
                            $entry->update([
                                'status'           => 'published',
                                'published_at'     => now(),
                                'external_post_id' => $result['post_id'] ?? null,
                                'last_error'       => null,
                            ]);
                            $posted = true;
                        } catch (\RuntimeException $e) {
                            // Rate limited — check if RATE_LIMITED signal and re-queue with backoff
                            if (str_starts_with($e->getMessage(), 'RATE_LIMITED:')) {
                                $delay = SocialPlatformService::backoffSeconds($entry->retry_count + 1);
                                $entry->increment('retry_count');
                                $entry->update(['last_error' => 'Rate limited — retry in ' . $delay . 's']);
                                Log::warning("Rate limited for entry {$entry->id}. Retry in {$delay}s.");
                                continue; // Scheduler will re-pick up next minute
                            }
                            throw $e;
                        }
                    }
                }

                if (! $posted) {
                    // Simulated publish (feature flag off or no connected account)
                    $result = [
                        'post_id'     => 'sim_' . \Illuminate\Support\Str::random(10),
                        'impressions' => rand(100, 5000),
                        'clicks'      => rand(5, 300),
                        'conversions' => rand(0, 20),
                        'simulated'   => true,
                    ];
                    $entry->update(['status' => 'published', 'published_at' => now()]);
                }

                // Feed IterationEngine
                if ($entry->content_variation_id) {
                    $source = ($result['simulated'] ?? true) ? 'simulated' : 'real';
                    $iteration->recordPerformance(
                        $entry->content_variation_id,
                        $result['impressions'] ?? 0,
                        $result['clicks'] ?? 0,
                        $result['conversions'] ?? 0,
                        $source
                    );
                }

                SystemEvent::create([
                    'level'   => 'info',
                    'message' => sprintf('Scheduled post published: %s → %s [%s]', $entry->platform, $entry->title, $posted ? 'real' : 'simulated'),
                ]);

            } catch (\Throwable $e) {
                $entry->increment('retry_count');
                $entry->update(['last_error' => $e->getMessage()]);

                if ($entry->retry_count >= 3) {
                    $entry->update(['status' => 'failed']);
                    SystemEvent::create(['level' => 'error', 'message' => "Scheduled post failed after 3 retries: {$entry->title} — {$e->getMessage()}"]);
                } else {
                    SystemEvent::create(['level' => 'warning', 'message' => "Scheduled post retry {$entry->retry_count}/3: {$entry->title} — {$e->getMessage()}"]);
                }

                Log::error("DispatchScheduledPosts failed for entry {$entry->id}", ['error' => $e->getMessage()]);
            }
        }
    }
}
