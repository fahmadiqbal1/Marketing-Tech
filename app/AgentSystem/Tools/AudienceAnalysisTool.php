<?php

namespace App\AgentSystem\Tools;

use App\AgentSystem\Gateway\AIGateway;

/**
 * Analyses and profiles the target audience for a given business or product.
 */
class AudienceAnalysisTool implements ToolInterface
{
    public function __construct(private readonly AIGateway $gateway) {}

    public function getName(): string
    {
        return 'AudienceAnalysisTool';
    }

    public function getDescription(): string
    {
        return 'Analyses and creates detailed buyer personas, demographic profiles, psychographic insights, and channel preferences for a target audience.';
    }

    public function getParameterSchema(): array
    {
        return [
            'business'    => ['type' => 'string', 'required' => true,  'description' => 'Business, product, or service to analyse the audience for.'],
            'location'    => ['type' => 'string', 'required' => false, 'description' => 'Geographic market (city, region, country).'],
            'industry'    => ['type' => 'string', 'required' => false, 'description' => 'Industry vertical.'],
            'price_range' => ['type' => 'string', 'required' => false, 'description' => 'Price range of the offering (budget | mid | premium).'],
        ];
    }

    public function execute(array $parameters): array
    {
        $business   = $parameters['business']    ?? 'business';
        $location   = $parameters['location']    ?? '';
        $industry   = $parameters['industry']    ?? '';
        $priceRange = $parameters['price_range'] ?? 'mid';

        $systemPrompt = <<<PROMPT
You are a market research expert specialised in consumer psychology and segmentation.
Always respond with valid JSON in this exact structure:
{
  "primary_persona": {
    "name": "string",
    "age_range": "string",
    "gender_split": "string",
    "income_level": "string",
    "education": "string",
    "pain_points": ["string"],
    "goals": ["string"],
    "preferred_channels": ["string"],
    "buying_triggers": ["string"]
  },
  "secondary_persona": {
    "name": "string",
    "description": "string",
    "key_characteristics": ["string"]
  },
  "market_size_estimate": "string",
  "recommended_messaging": ["string"],
  "best_ad_platforms": ["string"],
  "peak_engagement_times": ["string"]
}
PROMPT;

        $userPrompt = <<<PROMPT
Analyse the target audience for: "{$business}"
Location: {$location}
Industry: {$industry}
Price range: {$priceRange}

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
