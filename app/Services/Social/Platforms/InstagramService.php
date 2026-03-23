<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use App\Services\Social\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramService implements SocialPlatformInterface
{
    private const GRAPH_URL = 'https://graph.instagram.com/v19.0';
    private const OAUTH_URL = 'https://api.instagram.com/oauth/authorize';
    private const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';
    private const LONG_TOKEN_URL = 'https://graph.instagram.com/access_token';
    private const REFRESH_URL  = 'https://graph.instagram.com/refresh_access_token';

    private CredentialResolver $resolver;

    public function __construct(?CredentialResolver $resolver = null)
    {
        $this->resolver = $resolver ?? app(CredentialResolver::class);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->resolver->clientId('instagram'))
            && ! empty($this->resolver->clientSecret('instagram'));
    }

    public function validateCredentials(string $clientId, string $clientSecret): array
    {
        try {
            // Facebook/Instagram share the same Graph API app credentials.
            // App access token request validates client_id + client_secret.
            $response = Http::get('https://graph.facebook.com/oauth/access_token', [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'grant_type'    => 'client_credentials',
            ]);

            if ($response->failed()) {
                $error = $response->json('error.message', 'Invalid credentials');
                return ['ok' => false, 'error' => $error, 'warning' => null];
            }

            return ['ok' => true, 'error' => null, 'warning' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Connection failed: ' . $e->getMessage(), 'warning' => null];
        }
    }

    public function getAuthorizationUrl(): array
    {
        $state = bin2hex(random_bytes(16));
        $url   = self::OAUTH_URL . '?' . http_build_query([
            'client_id'     => $this->resolver->clientId('instagram'),
            'redirect_uri'  => $this->resolver->redirectUri('instagram'),
            'scope'         => 'instagram_basic,instagram_content_publish,instagram_manage_insights',
            'response_type' => 'code',
            'state'         => $state,
        ]);

        return ['url' => $url, 'state' => $state];
    }

    public function exchangeCode(string $code): array
    {
        // Step 1: short-lived token
        $short = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => $this->resolver->clientId('instagram'),
            'client_secret' => $this->resolver->clientSecret('instagram'),
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->resolver->redirectUri('instagram'),
            'code'          => $code,
        ])->throw()->json();

        // Step 2: exchange for long-lived token (60d)
        $long = Http::get(self::LONG_TOKEN_URL, [
            'grant_type'        => 'ig_exchange_token',
            'client_secret'     => $this->resolver->clientSecret('instagram'),
            'access_token'      => $short['access_token'],
        ])->throw()->json();

        return $long; // keys: access_token, token_type, expires_in
    }

    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired()) {
            $account = $this->refreshToken($account);
        }

        $token  = $account->access_token;
        $userId = $account->platform_user_id;

        $mediaType = match ($entry->content_type) {
            'reel'     => 'REELS',
            'carousel' => 'CAROUSEL',
            default    => 'IMAGE',
        };

        $containerPayload = [
            'caption'      => $entry->draft_content,
            'media_type'   => $mediaType,
            'access_token' => $token,
        ];

        if (! empty($entry->metadata['media_url'])) {
            $key = $mediaType === 'REELS' ? 'video_url' : 'image_url';
            $containerPayload[$key] = $entry->metadata['media_url'];
        }

        $container = Http::post(self::GRAPH_URL . "/{$userId}/media", $containerPayload)
            ->throw()->json();

        $creationId = $container['id'];

        $published = Http::post(self::GRAPH_URL . "/{$userId}/media_publish", [
            'creation_id'  => $creationId,
            'access_token' => $token,
        ])->throw()->json();

        $postId = $published['id'];

        Log::info("Instagram published: post_id={$postId} for calendar entry {$entry->id}");

        return [
            'post_id'     => $postId,
            'url'         => "https://www.instagram.com/p/{$postId}/",
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

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
            'conversions' => 0,
        ];
    }

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
}
