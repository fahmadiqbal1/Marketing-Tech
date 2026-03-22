<?php

namespace App\Services\Social;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use App\Services\Social\Platforms\InstagramService;
use App\Services\Social\Platforms\StubPlatformService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;

class SocialPlatformService
{
    /**
     * Return the appropriate platform driver.
     */
    public function driver(string $platform): SocialPlatformInterface
    {
        return match ($platform) {
            'instagram' => new InstagramService(),
            default     => new StubPlatformService($platform),
        };
    }

    /**
     * Publish with per-platform rate limiting.
     * Throws \RuntimeException on 429 — caller should re-queue with backoff.
     *
     * Rate limit: 10 posts per 60 seconds per platform+account.
     */
    public function publishWithRateLimit(SocialAccount $account, ContentCalendar $entry): array
    {
        $key = "social-post:{$account->platform}:{$account->id}";

        $executed = RateLimiter::attempt(
            $key,
            perMinute: 10,
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
     * Calculate exponential backoff delay in seconds for retry_count.
     * retry 1 → 60s, retry 2 → 120s, retry 3 → 240s
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
}
