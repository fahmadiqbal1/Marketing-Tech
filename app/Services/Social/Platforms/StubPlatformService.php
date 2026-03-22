<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stub implementation for platforms without full API integration yet.
 * Returns realistic simulated data. Logs clearly as SIMULATED.
 * Used for: TikTok, Twitter/X, LinkedIn, Facebook (Phase 9 scope)
 * Full implementations planned for Phase 10.
 */
class StubPlatformService implements SocialPlatformInterface
{
    public function __construct(private readonly string $platform) {}

    public function isConfigured(): bool
    {
        return false;
    }

    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        $fakePostId = 'sim_' . Str::random(12);

        Log::info("[SIMULATED] {$this->platform} publish for calendar entry {$entry->id}: post_id={$fakePostId}");

        return [
            'post_id'     => $fakePostId,
            'url'         => "https://www.{$this->platform}.com/post/{$fakePostId}",
            'impressions' => rand(100, 5000),
            'clicks'      => rand(10, 400),
            'conversions' => rand(0, 30),
            'simulated'   => true,
        ];
    }

    public function fetchMetrics(SocialAccount $account, string $externalPostId): array
    {
        Log::info("[SIMULATED] {$this->platform} fetchMetrics for post {$externalPostId}");

        $impressions = rand(200, 8000);
        $clicks      = (int) ($impressions * (rand(15, 80) / 1000));
        $conversions = (int) ($clicks * (rand(5, 30) / 100));

        return [
            'impressions' => $impressions,
            'reach'       => (int) ($impressions * 0.85),
            'clicks'      => $clicks,
            'engagement'  => (int) ($impressions * (rand(20, 120) / 1000)),
            'conversions' => $conversions,
        ];
    }

    public function refreshToken(SocialAccount $account): SocialAccount
    {
        Log::info("[SIMULATED] {$this->platform} token refresh for account {$account->id} — no-op");

        return $account;
    }
}
