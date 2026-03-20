<?php

namespace App\AgentSystem\Tools;

use App\AgentSystem\Gateway\AIGateway;

/**
 * Builds a complete multi-channel marketing campaign plan.
 */
class CampaignPlannerTool implements ToolInterface
{
    public function __construct(private readonly AIGateway $gateway) {}

    public function getName(): string
    {
        return 'CampaignPlannerTool';
    }

    public function getDescription(): string
    {
        return 'Creates a detailed, multi-channel marketing campaign plan including timeline, budget allocation, content calendar, and performance metrics.';
    }

    public function getParameterSchema(): array
    {
        return [
            'goal'           => ['type' => 'string',  'required' => true,  'description' => 'Campaign goal: brand_awareness | lead_generation | sales | retention | event_promotion'],
            'business'       => ['type' => 'string',  'required' => true,  'description' => 'Business or product being promoted.'],
            'budget_range'   => ['type' => 'string',  'required' => false, 'description' => 'Budget range (e.g. "$500-$2000/month").'],
            'duration_weeks' => ['type' => 'integer', 'required' => false, 'description' => 'Campaign duration in weeks (default 4).'],
            'channels'       => ['type' => 'array',   'required' => false, 'description' => 'Preferred channels: facebook | instagram | google | tiktok | email | sms | whatsapp'],
            'audience'       => ['type' => 'string',  'required' => false, 'description' => 'Target audience description.'],
        ];
    }

    public function execute(array $parameters): array
    {
        $goal          = $parameters['goal']           ?? 'brand_awareness';
        $business      = $parameters['business']       ?? 'business';
        $budgetRange   = $parameters['budget_range']   ?? 'flexible';
        $durationWeeks = (int) ($parameters['duration_weeks'] ?? 4);
        $channels      = $parameters['channels']       ?? ['facebook', 'instagram', 'google'];
        $audience      = $parameters['audience']       ?? 'general audience';

        $channelsStr = implode(', ', $channels);

        $systemPrompt = <<<PROMPT
You are an expert digital marketing campaign strategist.
Always respond with valid JSON in this exact structure:
{
  "campaign_name": "string",
  "goal": "string",
  "duration": "string",
  "total_budget_recommendation": "string",
  "phases": [
    {
      "phase": "string",
      "week": "string",
      "focus": "string",
      "activities": ["string"]
    }
  ],
  "channel_allocation": [
    {"channel": "string", "budget_pct": "number", "objective": "string", "weekly_posts": "number"}
  ],
  "content_calendar_sample": [
    {"day": "string", "channel": "string", "content_type": "string", "topic": "string"}
  ],
  "kpis": [{"metric": "string", "weekly_target": "string"}],
  "tools_needed": ["string"],
  "success_criteria": "string"
}
PROMPT;

        $userPrompt = <<<PROMPT
Create a {$durationWeeks}-week marketing campaign for: "{$business}"
Goal: {$goal}
Budget: {$budgetRange}
Channels: {$channelsStr}
Audience: {$audience}

Return ONLY valid JSON.
PROMPT;

        try {
            $response = $this->gateway->complete([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ], ['temperature' => 0.6, 'max_tokens' => 3000]);

            $content = $this->gateway->parseJson($response['content']);

            return [
                'success' => true,
                'data'    => array_merge($content, ['tokens' => $response['tokens']]),
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
