<?php

namespace App\AgentSystem\Tools;

use App\AgentSystem\Gateway\AIGateway;

/**
 * Summarises and structures the outputs gathered by the agent into a
 * clean, actionable marketing plan ready for the end user.
 */
class SummarizeTool implements ToolInterface
{
    public function __construct(private readonly AIGateway $gateway) {}

    public function getName(): string
    {
        return 'SummarizeTool';
    }

    public function getDescription(): string
    {
        return 'Consolidates all gathered content, keywords, and analysis into a structured, easy-to-read marketing plan with prioritised recommendations.';
    }

    public function getParameterSchema(): array
    {
        return [
            'task_description' => ['type' => 'string', 'required' => true,  'description' => 'Original user task.'],
            'gathered_data'    => ['type' => 'object', 'required' => true,  'description' => 'All data collected by previous steps (keywords, content, analysis, etc.).'],
            'format'           => ['type' => 'string', 'required' => false, 'description' => 'Output format: full_plan | executive_summary | action_list'],
        ];
    }

    public function execute(array $parameters): array
    {
        $taskDescription = $parameters['task_description'] ?? 'Marketing task';
        $gatheredData    = $parameters['gathered_data']    ?? [];
        $format          = $parameters['format']           ?? 'full_plan';

        $dataJson = json_encode($gatheredData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<PROMPT
You are a senior marketing strategist. Create a comprehensive, professional marketing plan.
Always respond with valid JSON in this exact structure (Marketing OS canonical format):
{
  "strategy": "2-3 sentence executive strategy summary",
  "content": [
    {"channel": "string", "content_type": "string", "frequency": "string", "sample_content": "string", "variations": {}}
  ],
  "media": [
    {"type": "image|video|creative", "brief": "string", "platform": "string"}
  ],
  "recommendations": [
    {"priority": "high|medium|low", "action": "string", "timeline": "string", "expected_impact": "string"}
  ],
  "ads": null,
  "target_audience": "string",
  "recommended_channels": ["string"],
  "key_messages": ["string"],
  "keywords_strategy": {"primary": ["string"], "secondary": ["string"]},
  "kpis": [{"metric": "string", "target": "string"}],
  "budget_recommendations": "string"
}
Note: "ads" should remain null unless paid advertising was explicitly part of the task.
PROMPT;

        $userPrompt = <<<PROMPT
Create a {$format} for this task: "{$taskDescription}"

Gathered data from research and content generation:
{$dataJson}

Synthesise everything into a structured marketing plan. Return ONLY valid JSON.
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
