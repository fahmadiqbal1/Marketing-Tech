<?php

namespace App\Skills\Marketing;

use App\Services\AI\AIRouter;
use App\Skills\SkillInterface;
use Illuminate\Support\Facades\Log;

/**
 * Generates analytics tracking plans, event schemas, and conversion goal definitions.
 */
class AnalyticsTrackingSkill implements SkillInterface
{
    public function __construct(private readonly AIRouter $aiRouter) {}

    public function getName(): string { return 'analytics-tracking'; }

    public function getDescription(): string
    {
        return 'Generate an analytics tracking plan with event schema, conversion goals, and funnel definition for a product or campaign.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product'           => ['type' => 'string', 'description' => 'Product or service'],
                'conversion_goal'   => ['type' => 'string', 'description' => 'Primary conversion goal (e.g. signup, purchase, demo booked)'],
                'platform'          => ['type' => 'string', 'description' => 'Analytics platform (google_analytics, mixpanel, amplitude, posthog)'],
                'channels'          => ['type' => 'array',  'items' => ['type' => 'string'], 'description' => 'Marketing channels being tracked'],
                'funnel_stages'     => ['type' => 'array',  'items' => ['type' => 'string'], 'description' => 'Funnel stages to track'],
            ],
            'required' => ['product', 'conversion_goal'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $product    = $params['product']         ?? 'the product';
        $goal       = $params['conversion_goal'] ?? 'signup';
        $platform   = $params['platform']        ?? 'google_analytics';
        $channels   = $params['channels']        ?? ['organic_search', 'social', 'email'];
        $funnel     = $params['funnel_stages']   ?? ['awareness', 'consideration', 'conversion'];

        $channelList = implode(', ', $channels);
        $funnelList  = implode(' → ', $funnel);

        $systemPrompt = "You are an analytics engineering expert. Return ONLY valid JSON.";

        $userPrompt = <<<PROMPT
Create a complete analytics tracking plan.

Product: {$product}
Primary conversion goal: {$goal}
Platform: {$platform}
Channels: {$channelList}
Funnel: {$funnelList}

Return JSON:
{
  "events": [{"name": "...", "trigger": "...", "properties": {"key": "type"}, "priority": "high|medium|low"}],
  "conversion_goal": {"event_name": "...", "description": "...", "value": "..."},
  "funnel_definition": [{"stage": "...", "event": "...", "drop_off_threshold": "...%"}],
  "utm_schema": {"source": "...", "medium": "...", "campaign": "...", "content": "...", "term": "..."},
  "kpis": [{"metric": "...", "formula": "...", "target": "..."}],
  "dashboard_widgets": ["..."],
  "implementation_notes": "...",
  "platform_specific": {"setup_steps": ["..."], "gotchas": ["..."]}
}
PROMPT;

        try {
            $raw  = $this->aiRouter->complete($userPrompt, null, 2500, 0.4, $systemPrompt);
            $data = $this->parseJson($raw);

            return ['success' => true, 'fallback' => false, 'data' => $data, 'skill' => $this->getName()];
        } catch (\Throwable $e) {
            Log::error("[AnalyticsTrackingSkill] Failed: " . $e->getMessage());
            return [
                'success'  => false,
                'fallback' => true,
                'error'    => $e->getMessage(),
                'data'     => ['events' => [], 'kpis' => []],
                'skill'    => $this->getName(),
            ];
        }
    }

    private function parseJson(string $raw): array
    {
        $clean  = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $parsed = json_decode($clean, true);
        if (! $parsed) throw new \RuntimeException("AnalyticsTrackingSkill: invalid JSON from AI");
        return $parsed;
    }
}
