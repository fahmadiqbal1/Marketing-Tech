<?php

namespace App\Skills\Marketing;

use App\Services\AI\AIRouter;
use App\Services\Media\MediaGenerationService;
use App\Skills\SkillInterface;
use Illuminate\Support\Facades\Log;

/**
 * Platform-aware creative content skill.
 *
 * Adapts strategy depth per platform:
 *   - TikTok    → hook-first, 2 sentences max, audio cue note
 *   - Instagram → visual-first, caption + 5-10 hashtags, emoji-friendly
 *   - LinkedIn  → long-form, story arc, spaced formatting
 *   - Twitter   → max 240 chars, single punch, optional thread
 *
 * Produces 2-3 content variations (A/B/C) with optional visual generation.
 */
class CreativeContentSkill implements SkillInterface
{
    public function __construct(
        private readonly AIRouter               $aiRouter,
        private readonly MediaGenerationService $media,
    ) {}

    public function getName(): string { return 'creative-content'; }

    public function getDescription(): string
    {
        return 'Generate platform-adapted creative content (copy + visual brief) with A/B/C variations. Supports TikTok, Instagram, LinkedIn, Twitter.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content'          => ['type' => 'string', 'description' => 'Base content, topic, or brief'],
                'platform'         => ['type' => 'string', 'enum' => ['instagram', 'linkedin', 'tiktok', 'twitter'], 'description' => 'Target platform'],
                'product'          => ['type' => 'string', 'description' => 'Product or brand name'],
                'audience'         => ['type' => 'string', 'description' => 'Target audience'],
                'format'           => ['type' => 'string', 'description' => 'Content format (post, reel, carousel, story, thread)'],
                'generate_visual'  => ['type' => 'boolean', 'description' => 'Generate a visual/image via MediaGenerationService'],
                'winning_hooks'    => ['type' => 'string',  'description' => 'Past winning hooks to build on'],
            ],
            'required' => ['content', 'platform'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $content       = $params['content']         ?? '';
        $platform      = strtolower($params['platform'] ?? 'instagram');
        $product       = $params['product']         ?? '';
        $audience      = $params['audience']        ?? '';
        $format        = $params['format']          ?? $this->defaultFormat($platform);
        $generateVisual= (bool) ($params['generate_visual'] ?? false);
        $winningHooks  = $params['winning_hooks']   ?? '';

        $platformRules = $this->getPlatformRules($platform);
        $hooksBlock    = $winningHooks ? "\nWinning hook patterns to reuse: {$winningHooks}" : '';
        $productBlock  = $product      ? "\nProduct: {$product}"   : '';
        $audienceBlock = $audience     ? "\nAudience: {$audience}" : '';

        $systemPrompt = "You are a social media creative strategist. Return ONLY valid JSON.";

        $userPrompt = <<<PROMPT
Create {$platform} creative content with 3 variations (A, B, C).

Platform: {$platform}
Format: {$format}{$productBlock}{$audienceBlock}{$hooksBlock}

BRIEF:
{$content}

PLATFORM RULES:
{$platformRules}

Return JSON:
{
  "platform": "{$platform}",
  "format": "{$format}",
  "variations": {
    "A": {
      "hook": "...",
      "body": "...",
      "cta": "...",
      "hashtags": ["..."],
      "visual_brief": "Describe the visual (composition, colours, style)",
      "audio_cue": "..."
    },
    "B": {"hook": "...", "body": "...", "cta": "...", "hashtags": ["..."], "visual_brief": "...", "audio_cue": "..."},
    "C": {"hook": "...", "body": "...", "cta": "...", "hashtags": ["..."], "visual_brief": "...", "audio_cue": "..."}
  },
  "posting_notes": "Best time, format tips for this platform",
  "engagement_tactics": ["..."]
}
PROMPT;

        try {
            $raw  = $this->aiRouter->complete($userPrompt, null, 2500, 0.8, $systemPrompt);
            $data = $this->parseJson($raw);

            // Optionally generate a visual for variation A
            $mediaResult = null;
            if ($generateVisual && ! empty($data['variations']['A']['visual_brief'])) {
                try {
                    $mediaResult = $this->media->generateAdCreative(
                        $data['variations']['A']['visual_brief'],
                        $platform
                    );
                } catch (\Throwable $e) {
                    Log::warning("[CreativeContentSkill] Visual generation failed: " . $e->getMessage());
                }
            }

            if ($mediaResult) {
                $data['generated_visual'] = $mediaResult;
            }

            return ['success' => true, 'fallback' => false, 'data' => $data, 'skill' => $this->getName()];
        } catch (\Throwable $e) {
            Log::error("[CreativeContentSkill] Failed: " . $e->getMessage());
            return [
                'success'  => false,
                'fallback' => true,
                'error'    => $e->getMessage(),
                'data'     => ['platform' => $platform, 'variations' => []],
                'skill'    => $this->getName(),
            ];
        }
    }

    private function getPlatformRules(string $platform): string
    {
        return match ($platform) {
            'tiktok' => <<<RULES
- LINE 1 = hook (max 10 words — this determines if they keep watching)
- Max 2 sentences total in the body
- Include an "audio_cue" note (trending sound type or original audio direction)
- CTA must be ultra-short (e.g. "Follow for more", "Comment YES")
- Variation A: curiosity hook ("You won't believe...")
- Variation B: bold statement hook ("Most people do this wrong...")
- Variation C: result hook ("How I [result] in [timeframe]...")
RULES,
            'instagram' => <<<RULES
- Caption starts with a strong first line (the hook — visible before "more")
- Body: 3–5 lines max, emoji-friendly, conversational
- End with a question or CTA to drive comments
- Include 5–10 hashtags (mix of niche, medium, broad)
- visual_brief: describe composition, mood, colour palette, style (photo/graphic/carousel)
- Variation A: educational (value-led)
- Variation B: relatable (story-led)
- Variation C: promotional (offer-led)
RULES,
            'linkedin' => <<<RULES
- Long-form: 150–300 words
- Story arc: line 1 = hook problem → body = insight/journey → end = resolution/lesson
- ONE idea per line — spaced formatting (no dense paragraphs)
- Professional but human tone — no corporate jargon
- End with a thought-provoking question to drive comments
- No hashtags in body — max 3 at the end
- Variation A: personal story
- Variation B: industry insight
- Variation C: contrarian take / unpopular opinion
RULES,
            'twitter' => <<<RULES
- Max 240 characters per tweet
- One single punchy hook — no fluff
- If content requires more, structure as a thread (1/N format)
- Plain language — no buzzwords
- Variation A: bold claim
- Variation B: question format
- Variation C: listicle thread starter (1/ ...)
RULES,
            default => "Write clear, engaging content suited to the platform.",
        };
    }

    private function defaultFormat(string $platform): string
    {
        return match ($platform) {
            'tiktok'    => 'short_video_script',
            'instagram' => 'post',
            'linkedin'  => 'article',
            'twitter'   => 'tweet',
            default     => 'post',
        };
    }

    private function parseJson(string $raw): array
    {
        $clean  = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $parsed = json_decode($clean, true);
        if (! $parsed) throw new \RuntimeException("CreativeContentSkill: invalid JSON from AI");
        return $parsed;
    }
}
