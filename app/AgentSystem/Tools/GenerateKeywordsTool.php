<?php

namespace App\AgentSystem\Tools;

use App\AgentSystem\Gateway\AIGateway;

/**
 * Generates SEO/marketing keywords and keyphrases for a given topic.
 */
class GenerateKeywordsTool implements ToolInterface
{
    public function __construct(private readonly AIGateway $gateway) {}

    public function getName(): string
    {
        return 'GenerateKeywordsTool';
    }

    public function getDescription(): string
    {
        return 'Generates a ranked list of SEO and marketing keywords, long-tail phrases, and negative keywords for a topic, business, or product to help maximise reach and ad effectiveness.';
    }

    public function getParameterSchema(): array
    {
        return [
            'topic'       => ['type' => 'string',  'required' => true,  'description' => 'Business, product, or service to generate keywords for.'],
            'location'    => ['type' => 'string',  'required' => false, 'description' => 'Geographic focus (city, country). E.g. "Faisalabad, Pakistan"'],
            'count'       => ['type' => 'integer', 'required' => false, 'description' => 'Number of keywords to generate (default 15).'],
            'include_longtail' => ['type' => 'boolean', 'required' => false, 'description' => 'Whether to include long-tail phrases.'],
            'industry'    => ['type' => 'string',  'required' => false, 'description' => 'Industry/vertical (e.g. healthcare, retail, tech).'],
        ];
    }

    public function execute(array $parameters): array
    {
        $topic     = $parameters['topic']     ?? 'business';
        $location  = $parameters['location']  ?? '';
        $count     = (int) ($parameters['count'] ?? 15);
        $longtail  = $parameters['include_longtail'] ?? true;
        $industry  = $parameters['industry']  ?? '';

        $locationStr = $location ? "Geographic focus: {$location}" : '';
        $industryStr = $industry ? "Industry: {$industry}" : '';
        $longtailStr = $longtail ? 'Include long-tail keyword phrases.' : '';

        $systemPrompt = <<<PROMPT
You are an SEO and digital marketing expert. Generate strategic keywords.
Always respond with valid JSON in this exact structure:
{
  "primary_keywords": ["string"],
  "secondary_keywords": ["string"],
  "long_tail_phrases": ["string"],
  "negative_keywords": ["string"],
  "hashtags": ["string"],
  "search_intent": "informational | commercial | transactional | navigational"
}
PROMPT;

        $userPrompt = <<<PROMPT
Generate {$count} marketing and SEO keywords for: "{$topic}"
{$locationStr}
{$industryStr}
{$longtailStr}

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
                'data'    => array_merge($content, [
                    'topic'  => $topic,
                    'tokens' => $response['tokens'],
                ]),
                'error' => null,
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
