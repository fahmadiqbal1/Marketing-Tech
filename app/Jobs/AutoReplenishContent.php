<?php

namespace App\Jobs;

use App\Models\AgentJob;
use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Models\SystemEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoReplenishContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries   = 1;
    public int    $timeout = 60;
    public $queue   = 'low';

    public function handle(): void
    {
        $connectedPlatforms = SocialAccount::connected()->pluck('platform')->unique();

        if ($connectedPlatforms->isEmpty()) {
            return;
        }

        // Safety: don't dispatch more agent jobs if queue is busy
        $runningJobs = AgentJob::whereIn('status', ['pending', 'running'])->count();
        if ($runningJobs >= 5) {
            Log::info('AutoReplenishContent: skipped — too many running agent jobs', ['count' => $runningJobs]);
            return;
        }

        foreach ($connectedPlatforms as $platform) {
            $cooldownKey = "auto-replenish:{$platform}:last_run";

            // 24h cooldown per platform
            if (Cache::has($cooldownKey)) {
                continue;
            }

            // Count calendar entries for next 7 days
            $upcomingCount = ContentCalendar::forPlatform($platform)
                ->whereIn('status', ['draft', 'scheduled'])
                ->where('scheduled_at', '>', now())
                ->where('scheduled_at', '<=', now()->addDays(7))
                ->count();

            if ($upcomingCount >= 3) {
                continue;
            }

            // Log action BEFORE dispatching (anti-spam audit trail)
            SystemEvent::create([
                'level'   => 'info',
                'message' => "AutoReplenish: dispatching ContentAgent for {$platform} (only {$upcomingCount} entries in next 7 days)",
            ]);

            // Set cooldown BEFORE dispatching to prevent duplicate dispatches
            Cache::put($cooldownKey, true, now()->addHours(24));

            // Dispatch ContentAgent to generate social content
            AgentJob::create([
                'agent_type'        => 'content',
                'agent_class'       => \App\Agents\ContentAgent::class,
                'task_type'         => 'social',
                'instruction'       => "Create 3 engaging {$platform} posts for the content calendar. Use the create_content_calendar tool. Focus on educational and entertaining content pillars.",
                'short_description' => "Auto-replenish {$platform} content calendar",
                'status'            => 'pending',
            ]);

            Log::info("AutoReplenishContent: dispatched ContentAgent for {$platform}");
        }
    }
}
