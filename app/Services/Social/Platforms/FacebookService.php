<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use App\Services\Social\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Facebook Graph API v19.0 integration.
 * OAuth 2.0 Authorization Code flow → long-lived Page access token.
 */
class FacebookService implements SocialPlatformInterface
{
    private const GRAPH_URL = 'https://graph.facebook.com/v19.0';
    private const OAUTH_URL = 'https://www.facebook.com/v19.0/dialog/oauth';
    private const TOKEN_URL = 'https://graph.facebook.com/v19.0/oauth/access_token';

    private CredentialResolver $resolver;

    public function __construct(?CredentialResolver $resolver = null)
    {
        $this->resolver = $resolver ?? app(CredentialResolver::class);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->resolver->clientId('facebook'))
            && ! empty($this->resolver->clientSecret('facebook'));
    }

    public function validateCredentials(string $clientId, string $clientSecret): array
    {
        try {
            // App access token request validates client_id + client_secret
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
            'client_id'     => $this->resolver->clientId('facebook'),
            'redirect_uri'  => $this->resolver->redirectUri('facebook'),
            'scope'         => 'pages_manage_posts,pages_read_engagement,pages_show_list,read_insights,public_profile',
            'response_type' => 'code',
            'state'         => $state,
        ]);
        return ['url' => $url, 'state' => $state];
    }

    public function exchangeCode(string $code): array
    {
        $short = Http::get(self::TOKEN_URL, [
            'client_id'     => $this->resolver->clientId('facebook'),
            'client_secret' => $this->resolver->clientSecret('facebook'),
            'redirect_uri'  => $this->resolver->redirectUri('facebook'),
            'code'          => $code,
        ])->throw()->json();

        $long = Http::get(self::TOKEN_URL, [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->resolver->clientId('facebook'),
            'client_secret'     => $this->resolver->clientSecret('facebook'),
            'fb_exchange_token' => $short['access_token'],
        ])->throw()->json();

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
            'expires_in'         => 0,
        ];
    }

    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired() && $account->token_expires_at !== null) {
            $account = $this->refreshToken($account);
        }

        $pageId = $account->platform_user_id;
        $token  = $account->access_token;

        $hashtags = collect($entry->hashtags ?? [])->take(5)->implode(' ');
        $message  = trim(($entry->draft_content ?? '') . ($hashtags ? "\n\n{$hashtags}" : ''));

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

    public function refreshToken(SocialAccount $account): SocialAccount
    {
        $userToken = $account->metadata['user_access_token'] ?? $account->access_token;

        $response = Http::get(self::TOKEN_URL, [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->resolver->clientId('facebook'),
            'client_secret'     => $this->resolver->clientSecret('facebook'),
            'fb_exchange_token' => $userToken,
        ]);

        if ($response->failed()) {
            $account->update(['is_connected' => false, 'last_error' => 'Token refresh failed: ' . $response->status()]);
            Log::error("[Facebook] Token refresh failed for account {$account->id}: " . $response->body());
            return $account->fresh();
        }

        $data     = $response->json();
        $newToken = $data['access_token'];

        $pages = Http::get(self::GRAPH_URL . '/me/accounts', ['access_token' => $newToken]);
        $pageToken = $pages->ok() ? ($pages->json('data.0.access_token') ?? $newToken) : $newToken;

        $metadata = $account->metadata ?? [];
        $metadata['user_access_token'] = $newToken;

        $account->update([
            'access_token'     => $pageToken,
            'token_expires_at' => isset($data['expires_in']) && $data['expires_in'] > 0
                                    ? now()->addSeconds($data['expires_in'])
                                    : null,
            'metadata'         => $metadata,
            'is_connected'     => true,
            'last_error'       => null,
        ]);

        return $account->fresh();
    }
}
