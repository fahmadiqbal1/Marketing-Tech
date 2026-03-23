<?php

namespace App\Jobs;

use App\Models\ContentCalendar;
use App\Models\SystemEvent;
use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Poll TikTok's publish status endpoint after an async video/photo upload.
 *
 * TikTok's Content Posting API v2 is asynchronous — the init call returns a
 * publish_id, not a video ID. This job polls until TikTok reports success or
 * failure, updating the ContentCalendar entry accordingly.
 *
 * Dispatch via: PollTikTokPublishStatus::dispatch($publishId, $entryId)->delay(30)
 */
class PollTikTokPublishStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1; // Each dispatch is one attempt; we re-dispatch manually
    public int $timeout = 30;

    private const STATUS_URL  = 'https://open.tiktok.com/v2/post/publish/status/fetch/';
    private const MAX_POLLS   = 5;

    public function __construct(
        private readonly string $publishId,
        private readonly string $calendarEntryId,
        private readonly int    $attempt = 0,
    ) {}

    public function handle(): void
    {
        $entry = ContentCalendar::find($this->calendarEntryId);

        if (! $entry) {
            Log::warning("[TikTok] PollPublishStatus: entry {$this->calendarEntryId} not found — aborting.");
            return;
        }

        // Find connected TikTok account for this entry's platform user
        $account = SocialAccount::connected()->forPlatform('tiktok')->first();

        if (! $account) {
            Log::warning("[TikTok] PollPublishStatus: no connected TikTok account — aborting poll for entry {$this->calendarEntryId}.");
            return;
        }

        $response = Http::withToken($account->access_token)
            ->post(self::STATUS_URL, ['publish_id' => $this->publishId]);

        if ($response->failed()) {
            Log::warning("[TikTok] PollPublishStatus: API error for publish_id={$this->publishId}: " . $response->body());
            $this->scheduleRetryOrAbandon($entry);
            return;
        }

        $status  = $response->json('data.status');
        $videoId = $response->json('data.publicaly_available_post_id.0') // official field
            ?? $response->json('data.video_id')                           // alternate field
            ?? null;

        Log::info("[TikTok] PollPublishStatus: publish_id={$this->publishId} status={$status} attempt={$this->attempt}");

        match ($status) {
            'PUBLISH_COMPLETE' => $this->handleComplete($entry, $videoId),
            'FAILED'           => $this->handleFailed($entry, $response->json('data.fail_reason')),
            // PROCESSING_UPLOAD, PROCESSING_DOWNLOAD, SENDING_TO_USER_INBOX — still in progress
            default            => $this->scheduleRetryOrAbandon($entry),
        };
    }

    private function handleComplete(ContentCalendar $entry, ?string $videoId): void
    {
        $entry->update([
            'external_post_id' => $videoId ?? $this->publishId,
            'last_error'       => null,
        ]);

        SystemEvent::create([
            'level'   => 'info',
            'message' => "[TikTok] Video publish confirmed: publish_id={$this->publishId}" . ($videoId ? " video_id={$videoId}" : '') . " for entry \"{$entry->title}\"",
        ]);
    }

    private function handleFailed(ContentCalendar $entry, ?string $reason): void
    {
        $entry->update([
            'status'     => 'failed',
            'last_error' => "TikTok publish failed: " . ($reason ?? 'unknown reason'),
        ]);

        SystemEvent::create([
            'level'   => 'error',
            'message' => "[TikTok] Video publish FAILED: publish_id={$this->publishId} reason={$reason} for entry \"{$entry->title}\"",
        ]);
    }

    private function scheduleRetryOrAbandon(ContentCalendar $entry): void
    {
        if ($this->attempt >= self::MAX_POLLS - 1) {
            // Leave entry as published — TikTok may still finish processing
            Log::warning("[TikTok] PollPublishStatus: max polls reached for publish_id={$this->publishId}. Leaving entry as-is.");
            SystemEvent::create([
                'level'   => 'warning',
                'message' => "[TikTok] Publish status unconfirmed after " . self::MAX_POLLS . " polls for \"{$entry->title}\" — TikTok may still be processing.",
            ]);
            return;
        }

        // Re-dispatch with 30s delay, incrementing attempt counter
        self::dispatch($this->publishId, $this->calendarEntryId, $this->attempt + 1)
            ->delay(now()->addSeconds(30))
            ->onQueue('social');
    }
}
