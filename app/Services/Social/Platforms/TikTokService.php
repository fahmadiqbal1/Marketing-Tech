<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use App\Services\Social\CredentialResolver;
use App\Jobs\PollTikTokPublishStatus;
use App\Services\Social\SocialPlatformService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TikTok for Developers — Content Posting API v2.
 * OAuth 2.0 Authorization Code flow (PKCE).
 */
class TikTokService implements SocialPlatformInterface
{
    private const BASE_URL  = 'https://open.tiktok.com';
    private const OAUTH_URL = 'https://www.tiktok.com/v2/auth/authorize/';
    private const TOKEN_URL = 'https://open.tiktok.com/v2/oauth/token/';

    private CredentialResolver $resolver;

    public function __construct(?CredentialResolver $resolver = null)
    {
        $this->resolver = $resolver ?? app(CredentialResolver::class);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->resolver->clientId('tiktok'))
            && ! empty($this->resolver->clientSecret('tiktok'));
    }

    public function validateCredentials(string $clientId, string $clientSecret): array
    {
        try {
            // Attempt token exchange with dummy code. TikTok returns invalid_client
            // for bad credentials vs invalid_grant for bad code (meaning creds are OK).
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_key'    => $clientId,
                'client_secret' => $clientSecret,
                'code'          => 'credential_validation_probe',
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->resolver->redirectUri('tiktok'),
            ]);

            $error = $response->json('error', $response->json('data.error', ''));

            if ($error === 'invalid_client') {
                $desc = $response->json('error_description', $response->json('data.description', 'Invalid client credentials'));
                return ['ok' => false, 'error' => $desc, 'warning' => null];
            }

            // Any other error (invalid_grant, invalid_request) means creds are accepted
            return ['ok' => true, 'error' => null, 'warning' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Connection failed: ' . $e->getMessage(), 'warning' => null];
        }
    }

    public function getAuthorizationUrl(string $codeVerifier): array
    {
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $csrfState     = bin2hex(random_bytes(16));

        $url = self::OAUTH_URL . '?' . http_build_query([
            'client_key'            => $this->resolver->clientId('tiktok'),
            'response_type'         => 'code',
            'scope'                 => 'user.info.basic,video.upload,video.publish,video.list',
            'redirect_uri'          => $this->resolver->redirectUri('tiktok'),
            'state'                 => $csrfState,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $csrfState, 'code_verifier' => $codeVerifier];
    }

    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return Http::asForm()->post(self::TOKEN_URL, [
            'client_key'    => $this->resolver->clientId('tiktok'),
            'client_secret' => $this->resolver->clientSecret('tiktok'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->resolver->redirectUri('tiktok'),
            'code_verifier' => $codeVerifier,
        ])->throw()->json('data');
    }

    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired()) {
            $account = $this->refreshToken($account);
        }

        $isVideo = in_array($entry->content_type, ['reel', 'video'], true);

        return $isVideo
            ? $this->publishVideo($account, $entry)
            : $this->publishPhoto($account, $entry);
    }

    public function fetchMetrics(SocialAccount $account, string $externalPostId): array
    {
        $response = Http::withToken($account->access_token)
            ->post(self::BASE_URL . '/v2/video/list/', [
                'fields' => 'id,view_count,like_count,comment_count,share_count,play_url',
            ]);

        if ($response->failed()) {
            Log::warning("[TikTok] Metrics fetch failed for {$externalPostId}: " . $response->body());
            return ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'engagement' => 0, 'conversions' => 0];
        }

        $videos   = $response->json('data.videos', []);
        $video    = collect($videos)->firstWhere('id', $externalPostId);

        if (! $video) {
            Log::warning("[TikTok] Video {$externalPostId} not found in creator list.");
            return ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'engagement' => 0, 'conversions' => 0];
        }

        return [
            'impressions' => (int) ($video['view_count']    ?? 0),
            'reach'       => (int) ($video['view_count']    ?? 0),
            'clicks'      => (int) ($video['share_count']   ?? 0),
            'engagement'  => (int) ($video['like_count']    ?? 0)
                           + (int) ($video['comment_count'] ?? 0)
                           + (int) ($video['share_count']   ?? 0),
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
            'client_key'    => $this->resolver->clientId('tiktok'),
            'client_secret' => $this->resolver->clientSecret('tiktok'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        if ($response->failed()) {
            $account->update(['is_connected' => false, 'last_error' => 'Token refresh failed: ' . $response->status()]);
            Log::error("[TikTok] Token refresh failed for account {$account->id}: " . $response->body());
            return $account->fresh();
        }

        $data = $response->json('data', $response->json());

        $account->update([
            'access_token'     => $data['access_token'],
            'refresh_token'    => $data['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
            'is_connected'     => true,
            'last_error'       => null,
        ]);

        return $account->fresh();
    }

    private function publishVideo(SocialAccount $account, ContentCalendar $entry): array
    {
        $caption   = $this->buildCaption($entry);
        $mediaUrl  = $entry->metadata['media_url'] ?? null;

        if (! $mediaUrl) {
            throw new \RuntimeException('[TikTok] Video publish requires metadata.media_url');
        }

        $mediaUrl = SocialPlatformService::ensurePublicUrl($mediaUrl);

        $payload = [
            'post_info' => [
                'title'         => $caption,
                'privacy_level' => 'SELF_ONLY',
                'disable_duet'  => false,
                'disable_stitch' => false,
                'disable_comment' => false,
            ],
            'source_info' => [
                'source'        => 'PULL_FROM_URL',
                'video_url'     => $mediaUrl,
            ],
        ];

        $init = Http::withToken($account->access_token)
            ->post(self::BASE_URL . '/v2/post/publish/video/init/', $payload)
            ->throw()
            ->json();

        $publishId = $init['data']['publish_id'] ?? null;

        Log::info("[TikTok] Video publish initiated. publish_id={$publishId} for calendar entry {$entry->id}");

        if ($publishId) {
            PollTikTokPublishStatus::dispatch($publishId, $entry->id)
                ->delay(now()->addSeconds(30))
                ->onQueue('social');
        }

        return [
            'post_id'     => $publishId ?? 'pending',
            'url'         => 'https://www.tiktok.com/@' . ($account->handle ?? 'me'),
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

    private function publishPhoto(SocialAccount $account, ContentCalendar $entry): array
    {
        $caption    = $this->buildCaption($entry);
        $mediaUrls  = $entry->metadata['media_urls'] ?? [];

        if (empty($mediaUrls) && ! empty($entry->metadata['media_url'])) {
            $mediaUrls = [$entry->metadata['media_url']];
        }

        $mediaUrls = array_map([SocialPlatformService::class, 'ensurePublicUrl'], $mediaUrls);

        if (empty($mediaUrls)) {
            throw new \RuntimeException('[TikTok] Photo post requires metadata.media_urls[]');
        }

        $payload = [
            'post_info' => [
                'title'         => $caption,
                'privacy_level' => 'SELF_ONLY',
            ],
            'source_info' => [
                'source'      => 'PULL_FROM_URL',
                'photo_cover_index' => 1,
                'photo_images' => $mediaUrls,
            ],
            'post_mode'   => 'DIRECT_POST',
            'media_type'  => 'PHOTO',
        ];

        $response = Http::withToken($account->access_token)
            ->post(self::BASE_URL . '/v2/post/publish/content/init/', $payload)
            ->throw()
            ->json();

        $publishId = $response['data']['publish_id'] ?? null;

        Log::info("[TikTok] Photo post initiated. publish_id={$publishId} for calendar entry {$entry->id}");

        if ($publishId) {
            PollTikTokPublishStatus::dispatch($publishId, $entry->id)
                ->delay(now()->addSeconds(30))
                ->onQueue('social');
        }

        return [
            'post_id'     => $publishId ?? 'pending',
            'url'         => 'https://www.tiktok.com/@' . ($account->handle ?? 'me'),
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

    private function buildCaption(ContentCalendar $entry): string
    {
        $hashtags = collect($entry->hashtags ?? [])->take(5)->implode(' ');
        return trim(($entry->draft_content ?? '') . ($hashtags ? "\n\n{$hashtags}" : ''));
    }
}
