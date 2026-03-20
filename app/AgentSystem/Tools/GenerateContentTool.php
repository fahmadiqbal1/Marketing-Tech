<?php

namespace App\AgentSystem\Tools;

use App\AgentSystem\Gateway\AIGateway;

/**
 * Generates marketing content (ad copy, social posts, email campaigns, etc.)
 * using the AI Gateway.
 */
class GenerateContentTool implements ToolInterface
{
    public function __construct(private readonly AIGateway $gateway) {}

    public function getName(): string
    {
        return 'GenerateContentTool';
    }

    public function getDescription(): string
    {
        return 'Generates marketing content such as ad copy, social media posts, blog articles, email campaigns, or promotional text for a given topic, audience, and content type.';
    }

    public function getParameterSchema(): array
    {
        return [
            'topic'        => ['type' => 'string', 'required' => true,  'description' => 'The main subject or product to create content for.'],
            'content_type' => ['type' => 'string', 'required' => true,  'description' => 'Type: ad_copy | social_post | email | blog | sms | tagline | product_description'],
            'audience'     => ['type' => 'string', 'required' => false, 'description' => 'Target audience description.'],
            'tone'         => ['type' => 'string', 'required' => false, 'description' => 'Tone: professional | friendly | urgent | inspirational | humorous'],
            'language'     => ['type' => 'string', 'required' => false, 'description' => 'Language code, e.g. en, ur, ar. Default: en'],
            'keywords'     => ['type' => 'array',  'required' => false, 'description' => 'Keywords to include in the content.'],
            'length'       => ['type' => 'string', 'required' => false, 'description' => 'Content length: short | medium | long'],
        ];
    }

    public function execute(array $parameters): array
    {
        $topic       = $parameters['topic']        ?? 'product';
        $contentType = $parameters['content_type'] ?? 'ad_copy';
        $audience    = $parameters['audience']     ?? 'general audience';
        $tone        = $parameters['tone']         ?? 'professional';
        $language    = $parameters['language']     ?? 'en';
        $keywords    = $parameters['keywords']     ?? [];
        $length      = $parameters['length']       ?? 'medium';

        $keywordStr = $keywords ? 'Include these keywords: ' . implode(', ', $keywords) . '.' : '';

        $systemPrompt = <<<PROMPT
You are an expert marketing copywriter. Generate compelling marketing content.
Always respond with valid JSON in this exact structure:
{
  "headline": "string",
  "body": "string",
  "call_to_action": "string",
  "hashtags": ["string"],
  "variations": ["string", "string"]
}
PROMPT;

        $userPrompt = <<<PROMPT
Create {$contentType} marketing content for: "{$topic}"
Target audience: {$audience}
Tone: {$tone}
Length: {$length}
Language: {$language}
{$keywordStr}

Return ONLY valid JSON matching the schema above.
PROMPT;

        try {
            $response = $this->gateway->complete([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ], ['temperature' => 0.8]);

            $content = $this->gateway->parseJson($response['content']);

            return [
                'success' => true,
                'data'    => array_merge($content, [
                    'topic'        => $topic,
                    'content_type' => $contentType,
                    'tokens'       => $response['tokens'],
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
