<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twitter / X API v2 integration.
 *
 * OAuth 2.0 Authorization Code with PKCE (user context).
 * Posting: POST /2/tweets
 * Metrics: GET /2/tweets/{id}?tweet.fields=public_metrics
 * Token refresh: POST /2/oauth2/token (refresh_token grant)
 */
class TwitterService implements SocialPlatformInterface
{
    private const BASE_URL  = 'https://api.twitter.com';
    private const OAUTH_URL = 'https://twitter.com/i/oauth2/authorize';
    private const TOKEN_URL = 'https://api.twitter.com/2/oauth2/token';

    public function isConfigured(): bool
    {
        return ! empty(config('services.twitter.client_id'))
            && ! empty(config('services.twitter.client_secret'));
    }

    public function getAuthorizationUrl(string $codeVerifier): array
    {
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state         = bin2hex(random_bytes(16));

        $url = self::OAUTH_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => config('services.twitter.client_id'),
            'redirect_uri'          => config('services.twitter.redirect_uri'),
            'scope'                 => 'tweet.read tweet.write users.read offline.access',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $state, 'code_verifier' => $codeVerifier];
    }

    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $response = Http::withBasicAuth(
            config('services.twitter.client_id'),
            config('services.twitter.client_secret')
        )->asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => config('services.twitter.redirect_uri'),
            'code_verifier' => $codeVerifier,
        ])->throw()->json();

        // expires_in is typically 7200s for user access tokens
        return $response; // access_token, refresh_token, expires_in, scope
    }

    /**
     * Post a tweet. Handles thread (content_type='thread') by splitting on \n\n.
     */
    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired()) {
            $account = $this->refreshToken($account);
        }

        $body = $entry->draft_content ?? '';

        // For threads: split on double newline, post as reply chain
        if ($entry->content_type === 'thread') {
            return $this->publishThread($account, $body, $entry->hashtags ?? []);
        }

        // Single tweet: truncate to 280 chars, append hashtags
        $hashtags = collect($entry->hashtags ?? [])->take(3)->implode(' ');
        $text     = $this->truncateTweet($body, $hashtags);

        $response = Http::withToken($account->access_token)
            ->post(self::BASE_URL . '/2/tweets', ['text' => $text])
            ->throw()
            ->json();

        $postId = $response['data']['id'];
        $authorId = $account->platform_user_id;

        Log::info("[Twitter] Published tweet id={$postId} for calendar entry {$entry->id}");

        return [
            'post_id'     => $postId,
            'url'         => "https://twitter.com/i/web/status/{$postId}",
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

    public function fetchMetrics(SocialAccount $account, string $externalPostId): array
    {
        $response = Http::withToken($account->access_token)
            ->get(self::BASE_URL . "/2/tweets/{$externalPostId}", [
                'tweet.fields' => 'public_metrics,non_public_metrics,organic_metrics',
            ]);

        if ($response->failed()) {
            Log::warning("[Twitter] Metrics fetch failed for {$externalPostId}: " . $response->body());
            return ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'engagement' => 0, 'conversions' => 0];
        }

        // public_metrics always available; non_public_metrics requires OAuth user context
        $pub = $response->json('data.public_metrics', []);
        $non = $response->json('data.non_public_metrics', []);
        $org = $response->json('data.organic_metrics', []);

        return [
            'impressions' => (int) ($org['impression_count']  ?? $non['impression_count']  ?? 0),
            'reach'       => (int) ($pub['retweet_count']      ?? 0),
            'clicks'      => (int) ($non['url_link_clicks']    ?? $org['url_link_clicks']   ?? 0),
            'engagement'  => (int) (($pub['like_count'] ?? 0) + ($pub['reply_count'] ?? 0) + ($pub['retweet_count'] ?? 0)),
            'conversions' => 0,
        ];
    }

    public function refreshToken(SocialAccount $account): SocialAccount
    {
        if (empty($account->refresh_token)) {
            $account->update(['is_connected' => false, 'last_error' => 'No refresh token stored.']);
            return $account->fresh();
        }

        $response = Http::withBasicAuth(
            config('services.twitter.client_id'),
            config('services.twitter.client_secret')
        )->asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        if ($response->failed()) {
            $account->update(['is_connected' => false, 'last_error' => 'Token refresh failed: ' . $response->status()]);
            Log::error("[Twitter] Token refresh failed for account {$account->id}: " . $response->body());
            return $account->fresh();
        }

        $data = $response->json();

        $account->update([
            'access_token'     => $data['access_token'],
            'refresh_token'    => $data['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 7200),
            'is_connected'     => true,
            'last_error'       => null,
        ]);

        return $account->fresh();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function truncateTweet(string $body, string $hashtags): string
    {
        $suffix   = $hashtags ? "\n\n{$hashtags}" : '';
        $maxBody  = 280 - strlen($suffix);
        $text     = strlen($body) > $maxBody ? substr($body, 0, $maxBody - 1) . '…' : $body;
        return $text . $suffix;
    }

    private function publishThread(SocialAccount $account, string $body, array $hashtags): array
    {
        $parts = array_filter(array_map('trim', explode("\n\n", $body)));
        if (empty($parts)) {
            $parts = [$body];
        }

        $replyToId = null;
        $firstId   = null;

        foreach ($parts as $i => $part) {
            // Only append hashtags to the last tweet in the thread
            $text = (array_key_last($parts) === $i && $hashtags)
                ? $this->truncateTweet($part, collect($hashtags)->take(3)->implode(' '))
                : substr($part, 0, 280);

            $payload = ['text' => $text];
            if ($replyToId) {
                $payload['reply'] = ['in_reply_to_tweet_id' => $replyToId];
            }

            $response = Http::withToken($account->access_token)
                ->post(self::BASE_URL . '/2/tweets', $payload)
                ->throw()
                ->json();

            $tweetId  = $response['data']['id'];
            $replyToId = $tweetId;
            if ($firstId === null) {
                $firstId = $tweetId;
            }
        }

        Log::info("[Twitter] Published thread first_id={$firstId} ({$i} tweets)");

        return [
            'post_id'     => $firstId,
            'url'         => "https://twitter.com/i/web/status/{$firstId}",
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

    public function getRecentPosts(SocialAccount $account, int $limit = 20): array
    {
        try {
            $userId = $account->platform_user_id;
            if (! $userId) {
                return [];
            }
            $resp = Http::withToken($account->access_token)
                ->get("https://api.twitter.com/2/users/{$userId}/tweets", [
                    'max_results'  => min($limit, 100),
                    'tweet.fields' => 'created_at,public_metrics,text',
                ]);
            return collect($resp->json('data', []))->map(fn($t) => [
                'id'         => $t['id'] ?? null,
                'text'       => $t['text'] ?? '',
                'created_at' => $t['created_at'] ?? null,
                'likes'      => $t['public_metrics']['like_count'] ?? 0,
                'retweets'   => $t['public_metrics']['retweet_count'] ?? 0,
                'replies'    => $t['public_metrics']['reply_count'] ?? 0,
                'impressions'=> $t['public_metrics']['impression_count'] ?? 0,
            ])->all();
        } catch (\Throwable $e) {
            Log::warning('Twitter getRecentPosts failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function testConnection(SocialAccount $account): array
    {
        try {
            $response = Http::timeout(10)
                ->withToken($account->access_token)
                ->get(self::BASE_URL . '/2/users/me', ['user.fields' => 'id,name,username']);
            if ($response->successful() && $response->json('data.id')) {
                return ['healthy' => true, 'error' => null];
            }
            return ['healthy' => false, 'error' => 'Twitter: ' . ($response->json('detail') ?? $response->status())];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }
}
