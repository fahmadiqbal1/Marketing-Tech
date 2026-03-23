<?php

namespace App\Jobs;

use App\Models\ContentCalendar;
use App\Models\ContentPerformance;
use App\Models\ContentVariation;
use App\Models\SocialAccount;
use App\Models\SystemEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RepurposeContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        // Find top-performing variations from last 30 days
        $topVariations = ContentPerformance::where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('impressions')
            ->limit(5)
            ->pluck('content_variation_id');

        if ($topVariations->isEmpty()) {
            return;
        }

        $connectedPlatforms = SocialAccount::connected()->pluck('platform')->unique()->toArray();

        if (empty($connectedPlatforms)) {
            return;
        }

        $created = 0;

        foreach ($topVariations as $variationId) {
            $cooldownKey = "repurposed:{$variationId}:week";

            // Skip if already repurposed this week
            if (Cache::has($cooldownKey)) {
                continue;
            }

            $variation = ContentVariation::find($variationId);
            if (! $variation || empty($variation->content)) {
                continue;
            }

            // Find which platforms already have a calendar entry for this variation this week
            $usedPlatforms = ContentCalendar::where('content_variation_id', $variationId)
                ->whereBetween('scheduled_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->pluck('platform')
                ->toArray();

            $targetPlatforms = array_diff($connectedPlatforms, $usedPlatforms);

            if (empty($targetPlatforms)) {
                continue;
            }

            foreach ($targetPlatforms as $platform) {
                $defaultType = match ($platform) {
                    'tiktok' => 'reel',
                    'instagram' => 'post',
                    'linkedin' => 'post',
                    'twitter' => 'thread',
                    'facebook' => 'post',
                    'youtube' => 'video',
                    default => 'post',
                };

                ContentCalendar::create([
                    'title' => 'Repurposed: '.Str::limit($variation->content, 60),
                    'platform' => $platform,
                    'content_type' => $defaultType,
                    'draft_content' => $variation->content,
                    'status' => 'draft',
                    'moderation_status' => 'pending',
                    'content_variation_id' => $variationId,
                    'scheduled_at' => now()->addDays(rand(1, 7)),
                    'metadata' => ['source' => 'auto_repurpose', 'original_variation_id' => $variationId],
                ]);

                $created++;
            }

            // Set weekly cooldown
            Cache::put($cooldownKey, true, now()->endOfWeek());

            SystemEvent::create([
                'level' => 'info',
                'message' => "RepurposeContent: created {$created} draft entries from top-performing variation {$variationId}",
            ]);
        }

        Log::info("RepurposeContent: completed, {$created} new draft entries created");
    }
}
