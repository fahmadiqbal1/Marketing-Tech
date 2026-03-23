<?php

namespace App\Jobs;

use App\Agents\ContentAgent;
use App\Models\AgentJob;
use App\Models\ContentCalendar;
use App\Models\KnowledgeBase;
use App\Models\SocialAccount;
use App\Models\SystemEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTrends implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    // Confidence threshold: pattern must appear in ≥3 knowledge base entries
    private const MIN_CONFIDENCE_COUNT = 3;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $connectedPlatforms = SocialAccount::connected()->pluck('platform')->unique();

        foreach ($connectedPlatforms as $platform) {
            $this->analysePlatform($platform);
        }
    }

    private function analysePlatform(string $platform): void
    {
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        // Query recent knowledge base entries for this platform
        $entries = KnowledgeBase::whereNull('deleted_at')
            ->where(function ($q) use ($platform, $like) {
                $q->where('content', $like, "%{$platform}%")
                    ->orWhere('tags', $like, "%{$platform}%");
            })
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()
            ->limit(50)
            ->get(['title', 'content', 'category']);

        if ($entries->count() < self::MIN_CONFIDENCE_COUNT) {
            return;
        }

        // Extract simple keyword patterns (no external API — analytical only)
        $wordFrequency = [];
        foreach ($entries as $entry) {
            $words = str_word_count(strtolower($entry->content.' '.$entry->title), 1);
            $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'be', 'have', 'has', 'it', 'this', 'that'];
            foreach ($words as $word) {
                if (strlen($word) > 4 && ! in_array($word, $stopWords)) {
                    $wordFrequency[$word] = ($wordFrequency[$word] ?? 0) + 1;
                }
            }
        }

        // Sort by frequency and take top topic
        arsort($wordFrequency);
        $topTopics = array_slice(array_keys($wordFrequency), 0, 3);

        foreach ($topTopics as $topic) {
            $frequency = $wordFrequency[$topic] ?? 0;

            // Only act if pattern appears with high confidence (≥ MIN_CONFIDENCE_COUNT entries)
            if ($frequency < self::MIN_CONFIDENCE_COUNT) {
                continue;
            }

            $topicHash = md5("{$platform}:{$topic}");
            $cooldownKey = "auto-trend-action:{$platform}:{$topicHash}:last_run";

            // LOG BEFORE ACTING — anti-spam audit trail
            SystemEvent::create([
                'level' => 'info',
                'message' => "ProcessTrends [{$platform}]: detected pattern '{$topic}' (frequency={$frequency}). ".
                    (Cache::has($cooldownKey) ? 'Cooldown active — skipping.' : 'Would create draft calendar entry.'),
            ]);

            if (Cache::has($cooldownKey)) {
                continue;
            }

            // Dispatch ContentAgent with task_type=social
            $runningJobs = AgentJob::whereIn('status', ['pending', 'running'])->count();
            if ($runningJobs < 5) {
                AgentJob::create([
                    'agent_type' => 'content',
                    'agent_class' => ContentAgent::class,
                    'task_type' => 'social',
                    'instruction' => "Create a {$platform} post about '{$topic}' based on trending patterns in our knowledge base. Use trend_analysis to verify, then create_content_calendar to save as a draft.",
                    'short_description' => "Trend-driven content: {$topic} on {$platform}",
                    'status' => 'pending',
                ]);
            }

            // All ProcessTrends calendar entries require human approval
            ContentCalendar::create([
                'title' => "Trend: {$topic} on {$platform}",
                'platform' => $platform,
                'content_type' => 'post',
                'draft_content' => null,
                'status' => 'draft',
                'moderation_status' => 'pending', // ALWAYS pending — never auto-publish trends
                'metadata' => ['source' => 'process_trends', 'topic' => $topic, 'frequency' => $frequency],
            ]);

            // 12h cooldown per platform+topic
            Cache::put($cooldownKey, true, now()->addHours(12));

            Log::info("ProcessTrends: created pending approval entry for {$platform}/{$topic}");
        }
    }
}
