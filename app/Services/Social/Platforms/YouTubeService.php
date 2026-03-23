<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use App\Services\Social\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Data API v3 integration.
 * OAuth 2.0 Authorization Code flow (Google OAuth2).
 */
class YouTubeService implements SocialPlatformInterface
{
    private const API_URL      = 'https://www.googleapis.com/youtube/v3';
    private const UPLOAD_URL   = 'https://www.googleapis.com/upload/youtube/v3/videos';
    private const OAUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';

    private CredentialResolver $resolver;

    public function __construct(?CredentialResolver $resolver = null)
    {
        $this->resolver = $resolver ?? app(CredentialResolver::class);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->resolver->clientId('youtube'))
            && ! empty($this->resolver->clientSecret('youtube'));
    }

    public function validateCredentials(string $clientId, string $clientSecret): array
    {
        try {
            // Dummy code exchange: Google returns invalid_client for bad creds,
            // invalid_grant for bad code (meaning creds are OK).
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type'    => 'authorization_code',
                'code'          => 'credential_validation_probe',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $this->resolver->redirectUri('youtube'),
            ]);

            $error = $response->json('error', '');

            if ($error === 'invalid_client') {
                $desc = $response->json('error_description', 'Invalid client credentials');
                return ['ok' => false, 'error' => $desc, 'warning' => null];
            }

            // invalid_grant or redirect_uri_mismatch means creds are valid
            return ['ok' => true, 'error' => null, 'warning' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Connection failed: ' . $e->getMessage(), 'warning' => null];
        }
    }

    public function getAuthorizationUrl(): array
    {
        $state = bin2hex(random_bytes(16));
        $url   = self::OAUTH_URL . '?' . http_build_query([
            'client_id'    => $this->resolver->clientId('youtube'),
            'redirect_uri' => $this->resolver->redirectUri('youtube'),
            'response_type'=> 'code',
            'scope'        => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
            'access_type'  => 'offline',
            'prompt'       => 'consent',
            'state'        => $state,
        ]);
        return ['url' => $url, 'state' => $state];
    }

    public function exchangeCode(string $code): array
    {
        return Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $this->resolver->clientId('youtube'),
            'client_secret' => $this->resolver->clientSecret('youtube'),
            'redirect_uri'  => $this->resolver->redirectUri('youtube'),
            'grant_type'    => 'authorization_code',
        ])->throw()->json();
    }

    public function publish(SocialAccount $account, ContentCalendar $entry): array
    {
        if ($account->isTokenExpired()) {
            $account = $this->refreshToken($account);
        }

        $mediaUrl = $entry->metadata['media_url'] ?? null;
        if (! $mediaUrl) {
            throw new \RuntimeException('[YouTube] Publish requires metadata.media_url (public video URL).');
        }

        $mediaUrl = \App\Services\Social\SocialPlatformService::ensurePublicUrl($mediaUrl);

        $isShort = $entry->content_type === 'short' || ($entry->metadata['is_short'] ?? false);

        if ($isShort) {
            $duration = (int) ($entry->metadata['duration_seconds'] ?? 0);
            if ($duration > 60) {
                Log::warning("[YouTube] Shorts duration {$duration}s exceeds 60s limit for entry {$entry->id}");
                \App\Models\SystemEvent::create([
                    'level'   => 'warning',
                    'message' => "[YouTube] Shorts post \"{$entry->title}\" has duration {$duration}s (>60s) — may not qualify as a Short.",
                ]);
            }
        }

        $title        = $this->buildTitle($entry, $isShort);
        $description  = $this->buildDescription($entry, $isShort);
        $tags         = array_map(fn ($t) => ltrim($t, '#'), $entry->hashtags ?? []);
        $categoryId   = $entry->metadata['youtube_category_id'] ?? '22';

        $videoMeta = [
            'snippet' => [
                'title'       => mb_substr($title, 0, 100, 'UTF-8'),
                'description' => mb_substr($description, 0, 5000, 'UTF-8'),
                'tags'        => array_slice($tags, 0, 500),
                'categoryId'  => $categoryId,
            ],
            'status' => [
                'privacyStatus'       => $entry->metadata['privacy'] ?? 'public',
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        $uploadUri = $entry->metadata['youtube_upload_uri'] ?? null;

        if (! $uploadUri) {
            $initResponse = Http::withToken($account->access_token)
                ->withHeaders([
                    'X-Upload-Content-Type'   => 'video/*',
                    'X-Upload-Content-Length' => '0',
                    'Content-Type'            => 'application/json; charset=UTF-8',
                ])
                ->post(self::UPLOAD_URL . '?uploadType=resumable&part=snippet,status', $videoMeta)
                ->throw();

            $uploadUri = $initResponse->header('Location');
            if (! $uploadUri) {
                throw new \RuntimeException('[YouTube] No upload URI returned from resumable upload init.');
            }

            $meta = $entry->metadata ?? [];
            $meta['youtube_upload_uri'] = $uploadUri;
            $entry->update(['metadata' => $meta]);
        } else {
            Log::info("[YouTube] Resuming upload from stored URI for entry {$entry->id}");
        }

        $videoStream = Http::get($mediaUrl)->throw();
        $videoBytes  = $videoStream->body();
        $videoSize   = strlen($videoBytes);

        $uploadResponse = Http::withToken($account->access_token)
            ->withHeaders([
                'Content-Type'   => 'video/*',
                'Content-Length' => $videoSize,
            ])
            ->withBody($videoBytes, 'video/*')
            ->put($uploadUri)
            ->throw()
            ->json();

        $videoId = $uploadResponse['id'];

        $meta = $entry->metadata ?? [];
        unset($meta['youtube_upload_uri']);
        $entry->update(['metadata' => $meta]);

        Log::info("[YouTube] Published video id={$videoId} for calendar entry {$entry->id}" . ($isShort ? ' [Short]' : ''));

        return [
            'post_id'     => $videoId,
            'url'         => "https://www.youtube.com/watch?v={$videoId}",
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'simulated'   => false,
        ];
    }

    public function fetchMetrics(SocialAccount $account, string $externalPostId): array
    {
        $response = Http::withToken($account->access_token)
            ->get(self::API_URL . '/videos', [
                'part' => 'statistics,contentDetails',
                'id'   => $externalPostId,
            ]);

        if ($response->failed()) {
            Log::warning("[YouTube] Metrics fetch failed for {$externalPostId}: " . $response->body());
            return ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'engagement' => 0, 'conversions' => 0];
        }

        $stats   = $response->json('items.0.statistics', []);
        $details = $response->json('items.0.contentDetails', []);

        $durationSec = 0;
        if (! empty($details['duration'])) {
            preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $details['duration'], $m);
            $durationSec = ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
        }

        return [
            'impressions'      => (int) ($stats['viewCount']    ?? 0),
            'reach'            => (int) ($stats['viewCount']    ?? 0),
            'clicks'           => (int) ($stats['favoriteCount'] ?? 0),
            'engagement'       => (int) ($stats['likeCount']    ?? 0)
                                + (int) ($stats['commentCount'] ?? 0),
            'conversions'      => 0,
            'duration_seconds' => $durationSec,
        ];
    }

    public function refreshToken(SocialAccount $account): SocialAccount
    {
        if (empty($account->refresh_token)) {
            $account->update(['is_connected' => false, 'last_error' => 'No refresh token — re-authorise required.']);
            return $account->fresh();
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => $this->resolver->clientId('youtube'),
            'client_secret' => $this->resolver->clientSecret('youtube'),
            'refresh_token' => $account->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->failed()) {
            $account->update(['is_connected' => false, 'last_error' => 'Token refresh failed: ' . $response->status()]);
            Log::error("[YouTube] Token refresh failed for account {$account->id}: " . $response->body());
            return $account->fresh();
        }

        $data = $response->json();

        $account->update([
            'access_token'     => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            'is_connected'     => true,
            'last_error'       => null,
        ]);

        return $account->fresh();
    }

    private function buildTitle(ContentCalendar $entry, bool $isShort): string
    {
        $base = $entry->title ?? mb_substr($entry->draft_content ?? '', 0, 80, 'UTF-8');
        return $isShort ? trim($base . ' #Shorts') : $base;
    }

    private function buildDescription(ContentCalendar $entry, bool $isShort): string
    {
        $body     = $entry->draft_content ?? '';
        $hashtags = collect($entry->hashtags ?? [])->map(fn ($t) => ltrim($t, '#') )->map(fn ($t) => "#{$t}")->implode(' ');
        $suffix   = $isShort ? "\n\n#Shorts" : '';
        return trim($body . ($hashtags ? "\n\n{$hashtags}" : '') . $suffix);
    }
}
