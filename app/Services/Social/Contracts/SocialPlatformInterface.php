<?php

namespace App\Services\Social\Contracts;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;

interface SocialPlatformInterface
{
    /**
     * Publish a content calendar entry to the platform.
     * Returns array with keys: post_id, url, impressions, clicks, conversions, simulated (bool)
     */
    public function publish(SocialAccount $account, ContentCalendar $entry): array;

    /**
     * Fetch real metrics for a published post by its external_post_id.
     * Returns array with keys: impressions, reach, clicks, engagement, conversions
     */
    public function fetchMetrics(SocialAccount $account, string $externalPostId): array;

    /**
     * Refresh the OAuth access token for the given account.
     * Updates and returns the account with new token data.
     */
    public function refreshToken(SocialAccount $account): SocialAccount;

    /**
     * Whether this platform has real API credentials configured.
     * Returns false for stub implementations.
     */
    public function isConfigured(): bool;

    /**
     * Fetch the most recent posts for the given account.
     * Returns an array of posts; each post is a flat associative array with
     * at least: id, text/caption, created_at, and any available engagement counts.
     * Returns empty array on error — callers must not throw.
     */
    public function getRecentPosts(SocialAccount $account, int $limit = 20): array;

    /**
     * Test whether the stored access token is still valid.
     * Makes a lightweight read-only API call.
     * Returns ['healthy' => bool, 'error' => ?string].
     * Must never throw — catch all Throwable internally.
     */
    public function testConnection(SocialAccount $account): array;
}
