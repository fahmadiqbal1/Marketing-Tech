<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramService implements SocialPlatformInterface
{
    private const GRAPH_URL = 'https://graph.instagram.com/v19.0';
    private const OAUTH_URL = 'https://api.instagram.com/oauth/authorize';
    private const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';
    private const LONG_TOKEN_URL = 'https://graph.instagram.com/access_token';
    private const REFRESH_URL  = 'https://graph.instagram.com/refresh_access_token';

    public function isConfigured(): bool
    {
        return ! empty(config('services.instagram.client_id'))
            && ! empty(config('services.instagram.client_secret'));
    }

    /**
     * Build the OAuth redirect URL for Instagram authorization.
     */
    public function getAuthorizationUrl(): array
    {
        $state = bin2hex(random_bytes(16));
        $url   = self::OAUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.instagram.client_id'),
            'redirect_uri'  => config('services.instagram.redirect_uri'),
            'scope'         => 'instagram_basic,instagram_content_publish,instagram_manage_insights',
            'response_type' => 'code',
            'state'         => $state,
        ]);

        return ['url' => $url, 'state' => $state];
    }

    /**
     * Exchange an auth code for a long-lived access token.
     * Returns array: access_token, token_type, expires_in (seconds)
     */
    public function exchangeCode(string $code): array
    {
        // Step 1: short-lived token
        $short = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('services.instagram.client_id'),
            'client_secret' => config('services.instagram.client_secret'),
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => config('services.instagram.redirect_uri'),
            'code'          => $code,
        ])->throw()->json();

        // Step 2: exchange for long-lived token (60d)
        $long = Http::get(self::LONG_TOKEN_URL, [
            'grant_type'        => 'ig_exchange_token',
            'client_secret'     => config('services.instagram.client_secret'),
            'access_token'      => $short['access_token'],
        ])->throw()->json();

        return $long; // keys: access_token, token_type, expires_in
    }

    /**
     * Publish a content calendar entry as an Instagram media post.
     * Instagram requires a two-step flow: create container → publish.
     */
    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired()) {
            $account = $this->refreshToken($account);
        }

        $token  = $account->access_token;
        $userId = $account->platform_user_id;

        // Determine media type for Instagram API
        $mediaType = match ($entry->content_type) {
            'reel'     => 'REELS',
            'carousel' => 'CAROUSEL',
            default    => 'IMAGE',
        };

        // Step 1: Create media container
        $containerPayload = [
            'caption'      => $entry->draft_content,
            'media_type'   => $mediaType,
            'access_token' => $token,
        ];

        // Pull image/video URL from metadata if provided
        if (! empty($entry->metadata['media_url'])) {
            $key = $mediaType === 'REELS' ? 'video_url' : 'image_url';
            $containerPayload[$key] = $entry->metadata['media_url'];
        }

        $container = Http::post(self::GRAPH_URL . "/{$userId}/media", $containerPayload)
            ->throw()->json();

        $creationId = $container['id'];

        // Step 2: Publish the container
        $published = Http::post(self::GRAPH_URL . "/{$userId}/media_publish", [
            'creation_id'  => $creationId,
            'access_token' => $token,
        ])->throw()->json();

        $postId = $published['id'];

        Log::info("Instagram published: post_id={$postId} for calendar entry {$entry->id}");

        return [
            'post_id'     => $postId,
            'url'         => "https://www.instagram.com/p/{$postId}/",
            'impressions' => 0, // populated by FetchSocialMetrics job later
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

    /**
     * Fetch insights for a published post.
     */
    public function fetchMetrics(SocialAccount $account, string $externalPostId): array
    {
        $response = Http::get(self::GRAPH_URL . "/{$externalPostId}/insights", [
            'metric'       => 'impressions,reach,total_interactions,saved',
            'access_token' => $account->access_token,
        ]);

        if ($response->failed()) {
            Log::warning("Instagram metrics fetch failed for post {$externalPostId}: " . $response->body());
            return ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'engagement' => 0, 'conversions' => 0];
        }

        $data = collect($response->json('data', []));

        return [
            'impressions' => (int) ($data->firstWhere('name', 'impressions')['values'][0]['value'] ?? 0),
            'reach'       => (int) ($data->firstWhere('name', 'reach')['values'][0]['value'] ?? 0),
            'clicks'      => (int) ($data->firstWhere('name', 'total_interactions')['values'][0]['value'] ?? 0),
            'engagement'  => (int) ($data->firstWhere('name', 'saved')['values'][0]['value'] ?? 0),
            'conversions' => 0, // Instagram API doesn't provide conversions natively
        ];
    }

    /**
     * Refresh a long-lived Instagram access token (valid up to 60 days).
     */
    public function refreshToken(SocialAccount $account): SocialAccount
    {
        $response = Http::get(self::REFRESH_URL, [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $account->access_token,
        ]);

        if ($response->failed()) {
            $account->update([
                'is_connected' => false,
                'last_error'   => 'Token refresh failed: ' . $response->status(),
            ]);
            Log::error("Instagram token refresh failed for account {$account->id}: " . $response->body());
            return $account->fresh();
        }

        $data = $response->json();

        $account->update([
            'access_token'     => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in']),
            'is_connected'     => true,
            'last_error'       => null,
        ]);

        return $account->fresh();
    }

    public function getRecentPosts(SocialAccount $account, int $limit = 20): array
    {
        try {
            $resp = Http::get(self::GRAPH_URL . '/me/media', [
                'fields'       => 'id,caption,like_count,comments_count,timestamp,media_type',
                'limit'        => $limit,
                'access_token' => $account->access_token,
            ]);
            return collect($resp->json('data', []))->map(fn($p) => [
                'id'         => $p['id'] ?? null,
                'text'       => $p['caption'] ?? '',
                'created_at' => $p['timestamp'] ?? null,
                'likes'      => $p['like_count'] ?? 0,
                'comments'   => $p['comments_count'] ?? 0,
                'type'       => $p['media_type'] ?? 'IMAGE',
            ])->all();
        } catch (\Throwable $e) {
            Log::warning('Instagram getRecentPosts failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function testConnection(SocialAccount $account): array
    {
        try {
            $response = Http::timeout(10)
                ->get('https://graph.instagram.com/me', [
                    'fields'       => 'id,username',
                    'access_token' => $account->access_token,
                ]);
            if ($response->successful() && $response->json('id')) {
                return ['healthy' => true, 'error' => null];
            }
            return ['healthy' => false, 'error' => 'Instagram: ' . ($response->json('error.message') ?? $response->status())];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }
}
