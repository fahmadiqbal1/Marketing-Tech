<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LinkedIn Marketing API v2 integration.
 *
 * OAuth 2.0 Authorization Code flow.
 * Posting: POST /v2/ugcPosts (text + optional image)
 * Metrics: GET /v2/organizationalEntityShareStatistics or socialActions
 * Token refresh: POST /v2/accessToken (refresh_token grant — requires r_liteprofile + offline_access)
 *
 * Note: LinkedIn access tokens last 60 days. Refresh tokens last 365 days.
 * Organization (Company Page) tokens require w_organization_social scope.
 */
class LinkedInService implements SocialPlatformInterface
{
    private const BASE_URL  = 'https://api.linkedin.com/v2';
    private const OAUTH_URL = 'https://www.linkedin.com/oauth/v2/authorization';
    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    public function isConfigured(): bool
    {
        return ! empty(config('services.linkedin.client_id'))
            && ! empty(config('services.linkedin.client_secret'));
    }

    public function getAuthorizationUrl(): array
    {
        $state = bin2hex(random_bytes(16));
        $url   = self::OAUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => config('services.linkedin.client_id'),
            'redirect_uri'  => config('services.linkedin.redirect_uri'),
            'scope'         => 'r_liteprofile r_emailaddress w_member_social w_organization_social rw_organization_admin offline_access',
            'state'         => $state,
        ]);
        return ['url' => $url, 'state' => $state];
    }

    public function exchangeCode(string $code): array
    {
        return Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => config('services.linkedin.redirect_uri'),
            'client_id'     => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ])->throw()->json(); // access_token, refresh_token, expires_in, refresh_token_expires_in
    }

    /**
     * Publish a post via ugcPosts endpoint.
     * Supports: post (text), article (with URL), carousel (image list in metadata).
     */
    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired()) {
            $account = $this->refreshToken($account);
        }

        // Resolve author URN — person or organization
        $authorUrn = $account->metadata['organization_urn']
            ?? "urn:li:person:{$account->platform_user_id}";

        $hashtags = collect($entry->hashtags ?? [])->take(5)
            ->map(fn ($t) => ltrim($t, '#'))
            ->map(fn ($t) => "#{$t}")
            ->implode(' ');

        $text = trim(($entry->draft_content ?? '') . ($hashtags ? "\n\n{$hashtags}" : ''));

        // Build ugcPost payload
        $payload = [
            'author'          => $authorUrn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $text],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        // If media URL provided, attach as image
        if (! empty($entry->metadata['media_url'])) {
            $payload['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'IMAGE';
            $payload['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [[
                'status'      => 'READY',
                'description' => ['text' => substr($entry->draft_content ?? '', 0, 200)],
                'media'       => $entry->metadata['media_url'],
                'title'       => ['text' => $entry->title ?? 'Post'],
            ]];
        }

        // Article / link post
        if (! empty($entry->metadata['article_url'])) {
            $payload['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'ARTICLE';
            $payload['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [[
                'status'      => 'READY',
                'originalUrl' => $entry->metadata['article_url'],
                'title'       => ['text' => $entry->title ?? ''],
            ]];
        }

        $response = Http::withToken($account->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post(self::BASE_URL . '/ugcPosts', $payload)
            ->throw()
            ->json();

        // LinkedIn returns the post URN in 'id' field: urn:li:ugcPost:123456
        $postId = $response['id'] ?? '';
        $numericId = last(explode(':', $postId));

        Log::info("[LinkedIn] Published post id={$postId} for calendar entry {$entry->id}");

        return [
            'post_id'     => $postId,
            'url'         => "https://www.linkedin.com/feed/update/{$postId}/",
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

    /**
     * Fetch share statistics for a published post.
     */
    public function fetchMetrics(SocialAccount $account, string $externalPostId): array
    {
        // externalPostId is the full URN e.g. urn:li:ugcPost:7123456789
        $response = Http::withToken($account->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->get(self::BASE_URL . '/socialActions/' . urlencode($externalPostId));

        if ($response->failed()) {
            // Fall back to ugcPost statistics endpoint
            $stats = Http::withToken($account->access_token)
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->get(self::BASE_URL . '/organizationalEntityShareStatistics', [
                    'q'       => 'organizationalEntity',
                    'shares[0]' => $externalPostId,
                ]);

            if ($stats->failed()) {
                Log::warning("[LinkedIn] Metrics fetch failed for {$externalPostId}: " . $response->body());
                return ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'engagement' => 0, 'conversions' => 0];
            }

            $s = $stats->json('elements.0.totalShareStatistics', []);
            return [
                'impressions' => (int) ($s['impressionCount']      ?? 0),
                'reach'       => (int) ($s['uniqueImpressionsCount'] ?? 0),
                'clicks'      => (int) ($s['clickCount']             ?? 0),
                'engagement'  => (int) ($s['likeCount'] ?? 0) + (int) ($s['commentCount'] ?? 0) + (int) ($s['shareCount'] ?? 0),
                'conversions' => 0,
            ];
        }

        $data = $response->json();
        return [
            'impressions' => 0, // socialActions doesn't return impression count directly
            'reach'       => 0,
            'clicks'      => (int) ($data['socialActivityCounts']['numLikes'] ?? 0),
            'engagement'  => (int) ($data['socialActivityCounts']['numComments'] ?? 0)
                           + (int) ($data['socialActivityCounts']['numLikes'] ?? 0),
            'conversions' => 0,
        ];
    }

    public function refreshToken(SocialAccount $account): SocialAccount
    {
        if (empty($account->refresh_token)) {
            $account->update(['is_connected' => false, 'last_error' => 'No refresh token stored.']);
            return $account->fresh();
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'client_id'     => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);

        if ($response->failed()) {
            $account->update(['is_connected' => false, 'last_error' => 'Token refresh failed: ' . $response->status()]);
            Log::error("[LinkedIn] Token refresh failed for account {$account->id}: " . $response->body());
            return $account->fresh();
        }

        $data = $response->json();
        $account->update([
            'access_token'     => $data['access_token'],
            'refresh_token'    => $data['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 5184000), // 60 days default
            'is_connected'     => true,
            'last_error'       => null,
        ]);

        return $account->fresh();
    }
}
