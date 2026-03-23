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
     * Validate credentials by making a real API call.
     * Returns ['ok' => bool, 'error' => ?string, 'warning' => ?string].
     */
    public function validateCredentials(string $clientId, string $clientSecret): array;
}
