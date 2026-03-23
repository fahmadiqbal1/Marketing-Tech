<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use App\Services\Social\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twitter / X API v2 integration.
 * OAuth 2.0 Authorization Code with PKCE (user context).
 */
class TwitterService implements SocialPlatformInterface
{
    private const BASE_URL  = 'https://api.twitter.com';
    private const OAUTH_URL = 'https://twitter.com/i/oauth2/authorize';
    private const TOKEN_URL = 'https://api.twitter.com/2/oauth2/token';

    private CredentialResolver $resolver;

    public function __construct(?CredentialResolver $resolver = null)
    {
        $this->resolver = $resolver ?? app(CredentialResolver::class);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->resolver->clientId('twitter'))
            && ! empty($this->resolver->clientSecret('twitter'));
    }

    public function validateCredentials(string $clientId, string $clientSecret): array
    {
        try {
            // Twitter app-only OAuth 2.0: client_credentials grant with Basic auth
            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);

            if ($response->successful() && $response->json('access_token')) {
                return ['ok' => true, 'error' => null, 'warning' => null];
            }

            $error = $response->json('error_description', $response->json('error', 'Invalid credentials'));
            return ['ok' => false, 'error' => $error, 'warning' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Connection failed: ' . $e->getMessage(), 'warning' => null];
        }
    }

    public function getAuthorizationUrl(string $codeVerifier): array
    {
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state         = bin2hex(random_bytes(16));

        $url = self::OAUTH_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->resolver->clientId('twitter'),
            'redirect_uri'          => $this->resolver->redirectUri('twitter'),
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
            $this->resolver->clientId('twitter'),
            $this->resolver->clientSecret('twitter')
        )->asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->resolver->redirectUri('twitter'),
            'code_verifier' => $codeVerifier,
        ])->throw()->json();

        return $response;
    }

    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired()) {
            $account = $this->refreshToken($account);
        }

        $body = $entry->draft_content ?? '';

        if ($entry->content_type === 'thread') {
            return $this->publishThread($account, $body, $entry->hashtags ?? []);
        }

        $hashtags = collect($entry->hashtags ?? [])->take(3)->implode(' ');
        $text     = $this->truncateTweet($body, $hashtags);

        $response = Http::withToken($account->access_token)
            ->post(self::BASE_URL . '/2/tweets', ['text' => $text])
            ->throw()
            ->json();

        $postId = $response['data']['id'];

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
            $this->resolver->clientId('twitter'),
            $this->resolver->clientSecret('twitter')
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

    private function truncateTweet(string $body, string $hashtags): string
    {
        $suffix   = $hashtags ? "\n\n{$hashtags}" : '';
        $maxBody  = 280 - mb_strlen($suffix, 'UTF-8');
        $text     = mb_strlen($body, 'UTF-8') > $maxBody ? mb_substr($body, 0, $maxBody - 1, 'UTF-8') . '…' : $body;
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
            $text = (array_key_last($parts) === $i && $hashtags)
                ? $this->truncateTweet($part, collect($hashtags)->take(3)->implode(' '))
                : mb_substr($part, 0, 280, 'UTF-8');

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
}
