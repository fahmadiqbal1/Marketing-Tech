<?php

namespace App\Skills\Marketing;

use App\Services\AI\AIRouter;
use App\Skills\SkillInterface;
use Illuminate\Support\Facades\Log;

/**
 * Builds a 30-day content strategy with pillars, cadence, and channel-specific formats.
 */
class ContentStrategySkill implements SkillInterface
{
    public function __construct(private readonly AIRouter $aiRouter) {}

    public function getName(): string { return 'content-strategy'; }

    public function getDescription(): string
    {
        return 'Build a 30-day content strategy with pillars, posting cadence, channel mix, and content themes — organic only.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product'        => ['type' => 'string', 'description' => 'Product or service'],
                'audience'       => ['type' => 'string', 'description' => 'Target audience'],
                'channels'       => ['type' => 'array',  'items' => ['type' => 'string'], 'description' => 'Channels to plan for (instagram, linkedin, tiktok, twitter, blog, email)'],
                'goals'          => ['type' => 'array',  'items' => ['type' => 'string'], 'description' => 'Marketing goals (awareness, leads, retention)'],
                'brand_voice'    => ['type' => 'string', 'description' => 'Brand voice / personality'],
                'value_prop'     => ['type' => 'string', 'description' => 'Core value proposition'],
                'top_performers' => ['type' => 'string', 'description' => 'Summary of past top-performing content to build on'],
            ],
            'required' => ['product', 'audience'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $product     = $params['product']        ?? 'the product';
        $audience    = $params['audience']       ?? 'general audience';
        $channels    = $params['channels']       ?? ['instagram', 'linkedin', 'twitter'];
        $goals       = $params['goals']          ?? ['awareness'];
        $brandVoice  = $params['brand_voice']    ?? '';
        $valueProp   = $params['value_prop']     ?? '';
        $topPerf     = $params['top_performers'] ?? '';

        $channelList = implode(', ', $channels);
        $goalList    = implode(', ', $goals);

        $topPerfBlock  = $topPerf     ? "\n\nPast top performers (build on what worked):\n{$topPerf}" : '';
        $brandBlock    = $brandVoice  ? "\nBrand voice: {$brandVoice}"    : '';
        $valuePropBlock= $valueProp   ? "\nValue proposition: {$valueProp}" : '';

        $systemPrompt = "You are a senior content strategist. Return ONLY valid JSON.";

        $userPrompt = <<<PROMPT
Create a 30-day content strategy for organic growth.

Product: {$product}
Target audience: {$audience}
Channels: {$channelList}
Goals: {$goalList}{$brandBlock}{$valuePropBlock}{$topPerfBlock}

Return JSON:
{
  "content_pillars": [{"name": "...", "description": "...", "percentage": 0}],
  "posting_cadence": {"instagram": "X/week", "linkedin": "X/week", "tiktok": "X/week", "twitter": "X/day", "blog": "X/month", "email": "X/week"},
  "week_1_plan": [{"day": 1, "channel": "...", "pillar": "...", "format": "...", "topic": "..."}],
  "content_themes": ["..."],
  "hooks_library": ["..."],
  "channel_strategy": {"instagram": "...", "linkedin": "...", "tiktok": "...", "twitter": "..."},
  "kpis": [{"metric": "...", "target": "...", "channel": "..."}],
  "quick_start": ["First 3 posts to publish immediately"]
}
PROMPT;

        try {
            $raw  = $this->aiRouter->complete($userPrompt, null, 3000, 0.7, $systemPrompt);
            $data = $this->parseJson($raw);

            return ['success' => true, 'fallback' => false, 'data' => $data, 'skill' => $this->getName()];
        } catch (\Throwable $e) {
            Log::error("[ContentStrategySkill] Failed: " . $e->getMessage());
            return [
                'success'  => false,
                'fallback' => true,
                'error'    => $e->getMessage(),
                'data'     => ['content_pillars' => [], 'quick_start' => []],
                'skill'    => $this->getName(),
            ];
        }
    }

    private function parseJson(string $raw): array
    {
        $clean  = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $parsed = json_decode($clean, true);
        if (! $parsed) throw new \RuntimeException("ContentStrategySkill: invalid JSON from AI");
        return $parsed;
    }
}
