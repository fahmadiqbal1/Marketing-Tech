<?php

namespace App\Services\Social;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use App\Services\Social\Platforms\FacebookService;
use App\Services\Social\Platforms\InstagramService;
use App\Services\Social\Platforms\LinkedInService;
use App\Services\Social\Platforms\TikTokService;
use App\Services\Social\Platforms\TwitterService;
use App\Services\Social\Platforms\YouTubeService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;

class SocialPlatformService
{
    private CredentialResolver $resolver;

    public function __construct(?CredentialResolver $resolver = null)
    {
        $this->resolver = $resolver ?? app(CredentialResolver::class);
    }

    /**
     * Return the real platform driver for a given platform slug.
     * All platforms have full API implementations — no stubs.
     */
    public function driver(string $platform): SocialPlatformInterface
    {
        return match ($platform) {
            'instagram' => new InstagramService($this->resolver),
            'twitter'   => new TwitterService($this->resolver),
            'linkedin'  => new LinkedInService($this->resolver),
            'facebook'  => new FacebookService($this->resolver),
            'tiktok'    => new TikTokService($this->resolver),
            'youtube'   => new YouTubeService($this->resolver),
            default     => throw new \InvalidArgumentException("Unsupported social platform: {$platform}"),
        };
    }

    /**
     * Publish with per-platform rate limiting.
     * Throws \RuntimeException("RATE_LIMITED:{seconds}") on 429 — caller re-queues with backoff.
     *
     * Rate limits (conservative defaults, adjust per platform ToS):
     *  - Instagram: 25 posts/day per user (we limit 10/min locally)
     *  - Twitter:   300 tweets/3h per app (we limit 10/min locally)
     *  - LinkedIn:  150 requests/day per member (we limit 5/min locally)
     *  - Facebook:  200 calls/hour per user token (we limit 10/min locally)
     *  - TikTok:    varies by tier (we limit 5/min locally)
     *  - YouTube:   10,000 units/day quota (we limit 2/min locally — upload = 1600 units)
     */
    public function publishWithRateLimit(SocialAccount $account, ContentCalendar $entry): array
    {
        // Dry-run mode: log intent, return mock result, never call real API
        if (config('services.social.dry_run', false)) {
            $message = "[DRY_RUN] Would publish entry {$entry->id} ({$account->platform}): \"{$entry->title}\"";
            Log::info($message);
            \App\Models\SystemEvent::create(['level' => 'info', 'message' => $message]);
            return [
                'post_id'     => 'dry_run_' . \Illuminate\Support\Str::random(8),
                'url'         => '#dry-run',
                'impressions' => 0,
                'clicks'      => 0,
                'conversions' => 0,
                'simulated'   => true,
            ];
        }

        $key      = "social-post:{$account->platform}:{$account->id}";
        $perMin   = $this->rateLimit($account->platform);

        $executed = RateLimiter::attempt(
            $key,
            perMinute: $perMin,
            callback: function () use ($account, $entry) {
                return $this->driver($account->platform)->publish($account, $entry);
            },
            decaySeconds: 60
        );

        if ($executed === false) {
            $seconds = RateLimiter::availableIn($key);
            Log::warning("Rate limit hit for {$account->platform} account {$account->id}. Retry in {$seconds}s.");
            throw new \RuntimeException("RATE_LIMITED:{$seconds}");
        }

        return $executed;
    }

    /**
     * Per-platform conservative rate limit (posts per minute).
     */
    public function rateLimit(string $platform): int
    {
        return match ($platform) {
            'youtube'  => 2,
            'tiktok'   => 5,
            'linkedin' => 5,
            default    => 10,
        };
    }

    /**
     * Exponential backoff delay: retry 1→60s, 2→120s, 3→240s.
     */
    public static function backoffSeconds(int $retryCount): int
    {
        return 60 * (2 ** max(0, $retryCount - 1));
    }

    /**
     * Whether auto-posting is enabled via feature flag.
     */
    public function autoPostEnabled(): bool
    {
        return (bool) config('services.social.auto_post_enabled', false);
    }

    /**
     * List all supported platforms.
     */
    public static function platforms(): array
    {
        return ['instagram', 'twitter', 'linkedin', 'facebook', 'tiktok', 'youtube'];
    }

    /**
     * Ensure a media path is a publicly accessible HTTPS URL.
     *
     * If the path is already an http(s) URL it is returned as-is.
     * If it is a local storage path, the file is uploaded to the configured
     * default disk and a 2-hour temporary/signed URL is returned.
     *
     * @throws \RuntimeException if the local file does not exist.
     */
    public static function ensurePublicUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Normalise storage/ prefix
        $storagePath = ltrim(preg_replace('#^storage/#', '', $path), '/');

        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default', 'local'));

        if (! $disk->exists($storagePath)) {
            throw new \RuntimeException("ensurePublicUrl: local file not found: {$storagePath}");
        }

        // S3-compatible disks support temporary URLs; local disk returns a plain URL
        try {
            return $disk->temporaryUrl($storagePath, now()->addHours(2));
        } catch (\RuntimeException) {
            // Local disk doesn't support temporaryUrl — return public URL instead
            return $disk->url($storagePath);
        }
    }
}
