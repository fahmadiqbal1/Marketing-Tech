<?php

namespace App\AgentSystem\Tools;

use App\AgentSystem\Gateway\AIGateway;

/**
 * Provides competitor insights and positioning recommendations.
 */
class CompetitorAnalysisTool implements ToolInterface
{
    public function __construct(private readonly AIGateway $gateway) {}

    public function getName(): string
    {
        return 'CompetitorAnalysisTool';
    }

    public function getDescription(): string
    {
        return 'Analyses the competitive landscape for a business or product, identifying typical competitor strategies, market gaps, and differentiation opportunities.';
    }

    public function getParameterSchema(): array
    {
        return [
            'business'   => ['type' => 'string', 'required' => true,  'description' => 'Business, product, or service to analyse.'],
            'location'   => ['type' => 'string', 'required' => false, 'description' => 'Geographic market.'],
            'industry'   => ['type' => 'string', 'required' => false, 'description' => 'Industry vertical.'],
            'focus_area' => ['type' => 'string', 'required' => false, 'description' => 'Specific focus: pricing | messaging | channels | product_features'],
        ];
    }

    public function execute(array $parameters): array
    {
        $business  = $parameters['business']   ?? 'business';
        $location  = $parameters['location']   ?? '';
        $industry  = $parameters['industry']   ?? '';
        $focusArea = $parameters['focus_area'] ?? 'messaging';

        $systemPrompt = <<<PROMPT
You are a competitive intelligence analyst specialised in marketing strategy.
Always respond with valid JSON in this exact structure:
{
  "market_overview": "string",
  "typical_competitors": [
    {"type": "string", "typical_strategy": "string", "strengths": ["string"], "weaknesses": ["string"]}
  ],
  "market_gaps": ["string"],
  "differentiation_opportunities": ["string"],
  "recommended_usp": "string",
  "pricing_insights": "string",
  "marketing_channel_gaps": ["string"],
  "quick_wins": ["string"]
}
PROMPT;

        $userPrompt = <<<PROMPT
Perform competitive analysis for: "{$business}"
Location: {$location}
Industry: {$industry}
Focus area: {$focusArea}

Return ONLY valid JSON.
PROMPT;

        try {
            $response = $this->gateway->complete([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ], ['temperature' => 0.5]);

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
