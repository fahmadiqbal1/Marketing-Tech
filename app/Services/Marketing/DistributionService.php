<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Log;

/**
 * Prepares content for distribution — formats posts for each platform.
 *
 * This service NEVER auto-publishes. It returns a ready-to-post payload
 * that the caller can review and push to a scheduler or API.
 *
 * Platform formatting rules:
 *   - TikTok    → hook is first line (≤10 words), max 2 sentences, audio cue
 *   - Instagram → caption (≤150 chars before "more") + 5-10 hashtags, emoji-friendly
 *   - LinkedIn  → long-form, spaced, 3 hashtags max at end
 *   - Twitter   → max 240 chars; long content becomes a thread array
 */
class DistributionService
{
    private const CAPTION_LIMITS = [
        'tiktok'    => 2200,
        'instagram' => 2200,
        'linkedin'  => 3000,
        'twitter'   => 240,
    ];

    private const HASHTAG_LIMITS = [
        'tiktok'    => 5,
        'instagram' => 10,
        'linkedin'  => 3,
        'twitter'   => 2,
    ];

    /**
     * Prepare a post payload for a specific platform.
     *
     * @param string      $platform  instagram|linkedin|tiktok|twitter
     * @param string      $content   Raw content / copy
     * @param string|null $mediaRef  Storage key of associated media
     * @param array       $hashtags  Suggested hashtags (will be trimmed to platform limit)
     *
     * @return array{platform: string, caption: string, hashtags: array, media_ref: string|null, char_count: int, ready: bool, notes: string}
     */
    public function preparePost(
        string  $platform,
        string  $content,
        ?string $mediaRef  = null,
        array   $hashtags  = [],
    ): array {
        $platform = strtolower($platform);

        try {
            $prepared = match ($platform) {
                'tiktok'    => $this->formatTikTok($content, $hashtags, $mediaRef),
                'instagram' => $this->formatInstagram($content, $hashtags, $mediaRef),
                'linkedin'  => $this->formatLinkedIn($content, $hashtags, $mediaRef),
                'twitter'   => $this->formatTwitter($content, $hashtags, $mediaRef),
                default     => $this->formatGeneric($platform, $content, $hashtags, $mediaRef),
            };

            return array_merge($prepared, ['ready' => true]);
        } catch (\Throwable $e) {
            Log::error("[DistributionService] preparePost failed for {$platform}: " . $e->getMessage());

            // Fallback — always return a usable payload
            return [
                'platform'   => $platform,
                'caption'    => mb_substr($content, 0, self::CAPTION_LIMITS[$platform] ?? 2200),
                'hashtags'   => array_slice($hashtags, 0, self::HASHTAG_LIMITS[$platform] ?? 5),
                'media_ref'  => $mediaRef,
                'char_count' => strlen($content),
                'ready'      => true,
                'notes'      => 'Fallback formatting applied — review before publishing.',
            ];
        }
    }

    // ── Platform formatters ──────────────────────────────────────────

    private function formatTikTok(string $content, array $hashtags, ?string $mediaRef): array
    {
        $lines = explode("\n", $content);

        // Enforce: first line = hook (≤ 10 words)
        $firstLine = $lines[0] ?? $content;
        $hookWords = explode(' ', trim($firstLine));
        if (count($hookWords) > 10) {
            $firstLine = implode(' ', array_slice($hookWords, 0, 10)) . '...';
        }

        // Max 2 sentences in body
        $body      = $lines[1] ?? '';
        $sentences = preg_split('/(?<=[.!?])\s+/', $body, 3);
        $body      = implode(' ', array_slice($sentences, 0, 2));

        $caption = trim("{$firstLine}\n{$body}");
        $tags    = array_slice($hashtags, 0, self::HASHTAG_LIMITS['tiktok']);

        return [
            'platform'   => 'tiktok',
            'caption'    => $caption,
            'hook'       => $firstLine,
            'hashtags'   => $tags,
            'media_ref'  => $mediaRef,
            'char_count' => strlen($caption),
            'notes'      => 'TikTok: hook is first line, max 2 body sentences. Add trending audio.',
        ];
    }

    private function formatInstagram(string $content, array $hashtags, ?string $mediaRef): array
    {
        // First 125 chars are visible before "more"
        $caption = $content;
        if (strlen($caption) > self::CAPTION_LIMITS['instagram']) {
            $caption = mb_substr($caption, 0, self::CAPTION_LIMITS['instagram'] - 3) . '...';
        }

        $tags = array_slice($hashtags, 0, self::HASHTAG_LIMITS['instagram']);

        // Append hashtags as a block separated from caption
        $hashtagBlock = empty($tags) ? '' : "\n\n" . implode(' ', array_map(fn($t) => str_starts_with($t, '#') ? $t : "#{$t}", $tags));

        $finalCaption = $caption . $hashtagBlock;

        return [
            'platform'   => 'instagram',
            'caption'    => $finalCaption,
            'hashtags'   => $tags,
            'media_ref'  => $mediaRef,
            'char_count' => strlen($finalCaption),
            'notes'      => 'Instagram: first 125 chars shown before "more". Add visual if media_ref is null.',
        ];
    }

    private function formatLinkedIn(string $content, array $hashtags, ?string $mediaRef): array
    {
        // LinkedIn loves single-idea-per-line formatting
        $paragraphs   = array_filter(explode("\n", $content));
        $formatted    = implode("\n\n", $paragraphs);

        if (strlen($formatted) > self::CAPTION_LIMITS['linkedin']) {
            $formatted = mb_substr($formatted, 0, self::CAPTION_LIMITS['linkedin'] - 3) . '...';
        }

        $tags = array_slice($hashtags, 0, self::HASHTAG_LIMITS['linkedin']);
        if (! empty($tags)) {
            $formatted .= "\n\n" . implode(' ', array_map(fn($t) => str_starts_with($t, '#') ? $t : "#{$t}", $tags));
        }

        return [
            'platform'   => 'linkedin',
            'caption'    => $formatted,
            'hashtags'   => $tags,
            'media_ref'  => $mediaRef,
            'char_count' => strlen($formatted),
            'notes'      => 'LinkedIn: spaced formatting, max 3 hashtags at end.',
        ];
    }

    private function formatTwitter(string $content, array $hashtags, ?string $mediaRef): array
    {
        $limit = self::CAPTION_LIMITS['twitter'];
        $tags  = array_slice($hashtags, 0, self::HASHTAG_LIMITS['twitter']);
        $tagStr= empty($tags) ? '' : ' ' . implode(' ', array_map(fn($t) => str_starts_with($t, '#') ? $t : "#{$t}", $tags));

        if (strlen($content . $tagStr) <= $limit) {
            $caption = $content . $tagStr;
            $thread  = null;
        } else {
            // Build a thread
            $words  = explode(' ', $content);
            $tweets = [];
            $tweet  = '';
            $n      = 1;

            foreach ($words as $word) {
                $prefix  = count($tweets) === 0 ? '' : ($n . '/ ');
                $test    = trim($tweet . ' ' . $word);
                if (strlen($prefix . $test) > $limit - 5) {
                    $tweets[] = trim($prefix . $tweet);
                    $tweet    = $word;
                    $n++;
                } else {
                    $tweet .= ' ' . $word;
                }
            }
            if ($tweet) {
                $tweets[] = trim($n . '/ ' . $tweet);
            }

            $caption = $tweets[0] ?? mb_substr($content, 0, $limit);
            $thread  = $tweets;
        }

        return [
            'platform'   => 'twitter',
            'caption'    => $caption,
            'hashtags'   => $tags,
            'thread'     => $thread,
            'media_ref'  => $mediaRef,
            'char_count' => strlen($caption),
            'notes'      => $thread ? 'Twitter thread — post tweets in order.' : 'Single tweet.',
        ];
    }

    private function formatGeneric(string $platform, string $content, array $hashtags, ?string $mediaRef): array
    {
        $limit   = self::CAPTION_LIMITS[$platform] ?? 2200;
        $tagLimit= self::HASHTAG_LIMITS[$platform] ?? 5;
        $caption = mb_substr($content, 0, $limit);
        $tags    = array_slice($hashtags, 0, $tagLimit);

        return [
            'platform'   => $platform,
            'caption'    => $caption,
            'hashtags'   => $tags,
            'media_ref'  => $mediaRef,
            'char_count' => strlen($caption),
            'notes'      => "Generic formatting applied for platform: {$platform}.",
        ];
    }
}
