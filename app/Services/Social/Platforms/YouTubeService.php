<?php

namespace App\Services\Social\Platforms;

use App\Models\ContentCalendar;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPlatformInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Data API v3 integration.
 *
 * OAuth 2.0 Authorization Code flow (Google OAuth2).
 * Posting: Resumable upload to /upload/youtube/v3/videos (multipart or resumable)
 *          For URL-based videos: initiate resumable session, stream from source URL.
 * Metrics: GET /youtube/v3/videos?part=statistics — views, likes, comments, favorites
 * Token refresh: POST /token (refresh_token grant) — Google tokens expire in 1h
 *
 * Note: YouTube requires video file uploads — direct URL-to-YouTube ingestion is
 *       done via resumable upload URI. metadata.media_url must be a public video URL.
 *       For Shorts: video must be ≤60s and vertical (9:16). We set #Shorts in description.
 */
class YouTubeService implements SocialPlatformInterface
{
    private const API_URL      = 'https://www.googleapis.com/youtube/v3';
    private const UPLOAD_URL   = 'https://www.googleapis.com/upload/youtube/v3/videos';
    private const OAUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';

    public function isConfigured(): bool
    {
        return ! empty(config('services.youtube.client_id'))
            && ! empty(config('services.youtube.client_secret'));
    }

    public function getAuthorizationUrl(): array
    {
        $state = bin2hex(random_bytes(16));
        $url   = self::OAUTH_URL . '?' . http_build_query([
            'client_id'    => config('services.youtube.client_id'),
            'redirect_uri' => config('services.youtube.redirect_uri'),
            'response_type'=> 'code',
            'scope'        => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
            'access_type'  => 'offline',
            'prompt'       => 'consent', // always prompt to get refresh_token
            'state'        => $state,
        ]);
        return ['url' => $url, 'state' => $state];
    }

    public function exchangeCode(string $code): array
    {
        return Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'redirect_uri'  => config('services.youtube.redirect_uri'),
            'grant_type'    => 'authorization_code',
        ])->throw()->json(); // access_token, refresh_token, expires_in, token_type
    }

    /**
     * Upload a video to YouTube.
     *
     * Supports two modes:
     *  1. metadata.media_url is a public URL → stream-copy via resumable upload
     *  2. content_type = 'short' → adds #Shorts to title/description
     *
     * The resumable upload flow:
     *  a) POST to /upload/youtube/v3/videos?uploadType=resumable → get upload URI
     *  b) PUT video bytes to the upload URI
     */
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

        $isShort      = $entry->content_type === 'short' || ($entry->metadata['is_short'] ?? false);

        // Shorts validation: warn if duration metadata suggests video exceeds 60s
        if ($isShort) {
            $duration = (int) ($entry->metadata['duration_seconds'] ?? 0);
            if ($duration > 60) {
                Log::warning("[YouTube] Shorts duration {$duration}s exceeds 60s limit for entry {$entry->id} — YouTube may not treat this as a Short.");
                \App\Models\SystemEvent::create([
                    'level'   => 'warning',
                    'message' => "[YouTube] Shorts post \"{$entry->title}\" has duration {$duration}s (>60s) — may not qualify as a Short.",
                ]);
            }
        }

        $title        = $this->buildTitle($entry, $isShort);
        $description  = $this->buildDescription($entry, $isShort);
        $tags         = array_map(fn ($t) => ltrim($t, '#'), $entry->hashtags ?? []);
        $categoryId   = $entry->metadata['youtube_category_id'] ?? '22'; // 22 = People & Blogs

        $videoMeta = [
            'snippet' => [
                'title'       => substr($title, 0, 100),
                'description' => substr($description, 0, 5000),
                'tags'        => array_slice($tags, 0, 500),
                'categoryId'  => $categoryId,
            ],
            'status' => [
                'privacyStatus'       => $entry->metadata['privacy'] ?? 'public',
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        // Step 1: Initiate resumable upload session (skip if we have a stored URI from a failed retry)
        $uploadUri = $entry->metadata['youtube_upload_uri'] ?? null;

        if (! $uploadUri) {
            $initResponse = Http::withToken($account->access_token)
                ->withHeaders([
                    'X-Upload-Content-Type'   => 'video/*',
                    'X-Upload-Content-Length' => '0', // unknown size for URL source
                    'Content-Type'            => 'application/json; charset=UTF-8',
                ])
                ->post(self::UPLOAD_URL . '?uploadType=resumable&part=snippet,status', $videoMeta)
                ->throw();

            $uploadUri = $initResponse->header('Location');
            if (! $uploadUri) {
                throw new \RuntimeException('[YouTube] No upload URI returned from resumable upload init.');
            }

            // Persist upload URI immediately so a retry can resume without re-initing
            $meta = $entry->metadata ?? [];
            $meta['youtube_upload_uri'] = $uploadUri;
            $entry->update(['metadata' => $meta]);
        } else {
            Log::info("[YouTube] Resuming upload from stored URI for entry {$entry->id}");
        }

        // Step 2: Fetch video from source URL and stream to YouTube resumable URI
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

        // Clear stored upload URI on success
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

    /**
     * Fetch video statistics via YouTube Data API v3.
     */
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

        // Parse ISO 8601 duration (e.g. PT1M3S) into seconds
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

    /**
     * Refresh a Google OAuth2 access token using the stored refresh token.
     * Google access tokens expire every 3600 seconds (1 hour).
     */
    public function refreshToken(SocialAccount $account): SocialAccount
    {
        if (empty($account->refresh_token)) {
            $account->update(['is_connected' => false, 'last_error' => 'No refresh token — re-authorise required.']);
            return $account->fresh();
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
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
            // Google does not rotate refresh_token unless revoked — keep existing
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            'is_connected'     => true,
            'last_error'       => null,
        ]);

        return $account->fresh();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildTitle(ContentCalendar $entry, bool $isShort): string
    {
        $base = $entry->title ?? substr($entry->draft_content ?? '', 0, 80);
        return $isShort ? trim($base . ' #Shorts') : $base;
    }

    private function buildDescription(ContentCalendar $entry, bool $isShort): string
    {
        $body     = $entry->draft_content ?? '';
        $hashtags = collect($entry->hashtags ?? [])->map(fn ($t) => ltrim($t, '#') )->map(fn ($t) => "#{$t}")->implode(' ');
        $suffix   = $isShort ? "\n\n#Shorts" : '';
        return trim($body . ($hashtags ? "\n\n{$hashtags}" : '') . $suffix);
    }

    public function getRecentPosts(SocialAccount $account, int $limit = 20): array
    {
        try {
            $resp = Http::withToken($account->access_token)
                ->get('https://www.googleapis.com/youtube/v3/search', [
                    'part'      => 'snippet',
                    'forMine'   => 'true',
                    'type'      => 'video',
                    'maxResults'=> $limit,
                    'order'     => 'date',
                ]);
            $videoIds = collect($resp->json('items', []))->pluck('id.videoId')->filter()->join(',');

            $stats = [];
            if ($videoIds) {
                $statsResp = Http::withToken($account->access_token)
                    ->get('https://www.googleapis.com/youtube/v3/videos', [
                        'part' => 'statistics,snippet',
                        'id'   => $videoIds,
                    ]);
                foreach ($statsResp->json('items', []) as $item) {
                    $stats[$item['id']] = $item;
                }
            }

            return collect($resp->json('items', []))->map(function ($item) use ($stats) {
                $id = $item['id']['videoId'] ?? null;
                $s  = $stats[$id]['statistics'] ?? [];
                return [
                    'id'         => $id,
                    'text'       => $item['snippet']['title'] ?? '',
                    'created_at' => $item['snippet']['publishedAt'] ?? null,
                    'views'      => (int)($s['viewCount'] ?? 0),
                    'likes'      => (int)($s['likeCount'] ?? 0),
                    'comments'   => (int)($s['commentCount'] ?? 0),
                ];
            })->all();
        } catch (\Throwable $e) {
            Log::warning('YouTube getRecentPosts failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function testConnection(SocialAccount $account): array
    {
        try {
            $response = Http::timeout(10)
                ->withToken($account->access_token)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'mine' => 'true',
                    'part' => 'id',
                ]);
            if ($response->successful() && $response->json('pageInfo.totalResults') > 0) {
                return ['healthy' => true, 'error' => null];
            }
            return ['healthy' => false, 'error' => 'YouTube: ' . ($response->json('error.message') ?? $response->status())];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }
}
