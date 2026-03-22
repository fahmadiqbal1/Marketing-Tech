<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Facebook Graph API v19.0 integration.
 *
 * OAuth 2.0 Authorization Code flow → long-lived Page access token.
 * Posting: POST /{page-id}/feed (text/link) or /{page-id}/photos (image)
 * Metrics: GET /{post-id}/insights — impressions, reach, clicks, reactions
 * Token refresh: GET /oauth/access_token?grant_type=fb_exchange_token (long-lived, 60d)
 *
 * Note: We store the Page access token (not user token) in access_token column.
 *       platform_user_id holds the Page ID.
 *       metadata['page_name'] and metadata['page_id'] can be stored at connect time.
 */
class FacebookService implements SocialPlatformInterface
{
    private const GRAPH_URL = 'https://graph.facebook.com/v19.0';
    private const OAUTH_URL = 'https://www.facebook.com/v19.0/dialog/oauth';
    private const TOKEN_URL = 'https://graph.facebook.com/v19.0/oauth/access_token';

    public function isConfigured(): bool
    {
        return ! empty(config('services.facebook.client_id'))
            && ! empty(config('services.facebook.client_secret'));
    }

    public function getAuthorizationUrl(): string
    {
        return self::OAUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.facebook.client_id'),
            'redirect_uri'  => config('services.facebook.redirect_uri'),
            'scope'         => 'pages_manage_posts,pages_read_engagement,pages_show_list,read_insights,public_profile',
            'response_type' => 'code',
            'state'         => bin2hex(random_bytes(16)),
        ]);
    }

    /**
     * Exchange code → short-lived user token → long-lived user token → page token.
     * Returns the page access token for the first managed page.
     */
    public function exchangeCode(string $code): array
    {
        // Step 1: short-lived user token
        $short = Http::get(self::TOKEN_URL, [
            'client_id'     => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri'  => config('services.facebook.redirect_uri'),
            'code'          => $code,
        ])->throw()->json();

        // Step 2: exchange for long-lived user token (60 days)
        $long = Http::get(self::TOKEN_URL, [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $short['access_token'],
        ])->throw()->json();

        // Step 3: fetch managed pages and return first page token
        $pages = Http::get(self::GRAPH_URL . '/me/accounts', [
            'access_token' => $long['access_token'],
        ])->throw()->json();

        $pageData = $pages['data'][0] ?? null;

        return [
            'user_access_token'  => $long['access_token'],
            'user_expires_in'    => $long['expires_in'] ?? 5184000,
            'page_access_token'  => $pageData['access_token'] ?? $long['access_token'],
            'page_id'            => $pageData['id'] ?? null,
            'page_name'          => $pageData['name'] ?? null,
            // Page tokens are long-lived (never expire if re-authorized)
            'expires_in'         => 0,
        ];
    }

    /**
     * Publish to a Facebook Page.
     * Routes to /{page-id}/photos for image posts, /{page-id}/feed for text/link.
     */
    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired() && $account->token_expires_at !== null) {
            $account = $this->refreshToken($account);
        }

        $pageId = $account->platform_user_id;
        $token  = $account->access_token;

        $hashtags = collect($entry->hashtags ?? [])->take(5)->implode(' ');
        $message  = trim(($entry->draft_content ?? '') . ($hashtags ? "\n\n{$hashtags}" : ''));

        // Photo post
        if (! empty($entry->metadata['media_url'])) {
            $response = Http::post(self::GRAPH_URL . "/{$pageId}/photos", [
                'url'          => $entry->metadata['media_url'],
                'caption'      => $message,
                'access_token' => $token,
            ])->throw()->json();

            $postId = $response['post_id'] ?? $response['id'];
            Log::info("[Facebook] Published photo post_id={$postId} for calendar entry {$entry->id}");

            return [
                'post_id'     => $postId,
                'url'         => "https://www.facebook.com/{$postId}",
                'impressions' => 0,
                'clicks'      => 0,
                'conversions' => 0,
                'simulated'   => false,
            ];
        }

        // Text / link post
        $payload = [
            'message'      => $message,
            'access_token' => $token,
        ];

        if (! empty($entry->metadata['link_url'])) {
            $payload['link'] = $entry->metadata['link_url'];
        }

        $response = Http::post(self::GRAPH_URL . "/{$pageId}/feed", $payload)
            ->throw()
            ->json();

        $postId = $response['id'];
        Log::info("[Facebook] Published feed post_id={$postId} for calendar entry {$entry->id}");

        return [
            'post_id'     => $postId,
            'url'         => "https://www.facebook.com/{$postId}",
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

    /**
     * Fetch post-level insights.
     * post_impressions, post_reach, post_clicks, post_reactions_by_type_total
     */
    public function fetchMetrics(SocialAccount $account, string $externalPostId): array
    {
        $response = Http::get(self::GRAPH_URL . "/{$externalPostId}/insights", [
            'metric'       => 'post_impressions,post_reach,post_clicks,post_engaged_users',
            'access_token' => $account->access_token,
        ]);

        if ($response->failed()) {
            Log::warning("[Facebook] Metrics fetch failed for {$externalPostId}: " . $response->body());
            return ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'engagement' => 0, 'conversions' => 0];
        }

        $data = collect($response->json('data', []));

        return [
            'impressions' => (int) ($data->firstWhere('name', 'post_impressions')['values'][0]['value']      ?? 0),
            'reach'       => (int) ($data->firstWhere('name', 'post_reach')['values'][0]['value']            ?? 0),
            'clicks'      => (int) ($data->firstWhere('name', 'post_clicks')['values'][0]['value']           ?? 0),
            'engagement'  => (int) ($data->firstWhere('name', 'post_engaged_users')['values'][0]['value']    ?? 0),
            'conversions' => 0,
        ];
    }

    /**
     * Refresh a long-lived user token via fb_exchange_token.
     * Page access tokens linked to a long-lived user token do not expire —
     * only the user token itself needs refreshing.
     */
    public function refreshToken(SocialAccount $account): SocialAccount
    {
        $userToken = $account->metadata['user_access_token'] ?? $account->access_token;

        $response = Http::get(self::TOKEN_URL, [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $userToken,
        ]);

        if ($response->failed()) {
            $account->update(['is_connected' => false, 'last_error' => 'Token refresh failed: ' . $response->status()]);
            Log::error("[Facebook] Token refresh failed for account {$account->id}: " . $response->body());
            return $account->fresh();
        }

        $data     = $response->json();
        $newToken = $data['access_token'];

        // Re-fetch the page token with the refreshed user token
        $pages = Http::get(self::GRAPH_URL . '/me/accounts', ['access_token' => $newToken]);
        $pageToken = $pages->ok() ? ($pages->json('data.0.access_token') ?? $newToken) : $newToken;

        $metadata = $account->metadata ?? [];
        $metadata['user_access_token'] = $newToken;

        $account->update([
            'access_token'     => $pageToken,
            'token_expires_at' => isset($data['expires_in']) && $data['expires_in'] > 0
                                    ? now()->addSeconds($data['expires_in'])
                                    : null, // page tokens don't expire
            'metadata'         => $metadata,
            'is_connected'     => true,
            'last_error'       => null,
        ]);

        return $account->fresh();
    }
}
