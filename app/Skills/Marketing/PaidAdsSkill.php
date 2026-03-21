<?php

namespace App\Skills\Marketing;

use App\Services\AI\AIRouter;
use App\Services\Marketing\PaidAdsDecisionService;
use App\Services\Marketing\PerformanceService;
use App\Skills\SkillInterface;
use Illuminate\Support\Facades\Log;

/**
 * Paid ads planning skill — ONLY runs when all three gate conditions pass:
 *   1. Organic traffic is underperforming
 *   2. Product price is above the minimum viable threshold
 *   3. A conversion rate is known
 *
 * Additionally requires `approved => true` in params to prevent accidental execution.
 * Returns a structured explanation if ads are not recommended.
 */
class PaidAdsSkill implements SkillInterface
{
    public function __construct(
        private readonly AIRouter              $aiRouter,
        private readonly PaidAdsDecisionService $decisionService,
        private readonly PerformanceService    $performance,
    ) {}

    public function getName(): string { return 'paid-ads'; }

    public function getDescription(): string
    {
        return 'Plan paid advertising campaigns — ONLY runs when organic is underperforming, product price is viable, and conversion rate is known. Requires explicit approval.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product'          => ['type' => 'string',  'description' => 'Product or service name'],
                'budget_usd'       => ['type' => 'number',  'description' => 'Available ad budget in USD'],
                'product_price'    => ['type' => 'number',  'description' => 'Product price — used for ROI viability check'],
                'conversion_rate'  => ['type' => 'number',  'description' => 'Known conversion rate (0–1). Required for ROI check.'],
                'objective'        => ['type' => 'string',  'enum' => ['awareness', 'traffic', 'conversions', 'retargeting'], 'description' => 'Campaign objective'],
                'audience'         => ['type' => 'string',  'description' => 'Target audience description'],
                'approved'         => ['type' => 'boolean', 'description' => 'Explicit approval to run ads. Must be true.'],
                'agent_task_id'    => ['type' => 'integer', 'description' => 'Task ID for organic performance lookup'],
            ],
            'required' => ['product', 'product_price', 'approved'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        // ── Gate 1: Explicit approval ──────────────────────────────────
        if (! ($params['approved'] ?? false)) {
            return [
                'success'            => false,
                'fallback'           => true,
                'requires_approval'  => true,
                'error'              => 'Paid ads require explicit approval. Set approved=true after reviewing the plan.',
                'data'               => ['recommendation' => 'Run organic content first. Return with approved=true when ready.'],
                'skill'              => $this->getName(),
            ];
        }

        // ── Gate 2: Decision engine checks ───────────────────────────
        $taskId         = $params['agent_task_id']   ?? 0;
        $productPrice   = (float) ($params['product_price']   ?? 0);
        $conversionRate = isset($params['conversion_rate']) ? (float) $params['conversion_rate'] : null;

        if (! $this->decisionService->canRunAds($taskId, $productPrice, $conversionRate)) {
            $reason = $this->decisionService->getRejectionReason($taskId, $productPrice, $conversionRate);
            return [
                'success'     => false,
                'fallback'    => true,
                'ads_blocked' => true,
                'error'       => $reason,
                'data'        => [
                    'recommendation' => $reason,
                    'next_action'    => 'Improve organic performance first, then retry.',
                ],
                'skill'       => $this->getName(),
            ];
        }

        // ── Gate passed: Generate ad plan ────────────────────────────
        $product   = $params['product']    ?? 'the product';
        $budget    = $params['budget_usd'] ?? 500;
        $objective = $params['objective']  ?? 'conversions';
        $audience  = $params['audience']   ?? '';

        $systemPrompt = "You are a performance marketing expert. Return ONLY valid JSON.";

        $userPrompt = <<<PROMPT
Create a paid ads plan. This product has been validated organically.

Product: {$product}
Budget: \${$budget} USD
Objective: {$objective}
Target audience: {$audience}
Product price: \${$productPrice}
Known conversion rate: {$conversionRate}

Return JSON:
{
  "campaign_structure": [
    {
      "campaign_name": "...",
      "objective": "...",
      "budget_usd": 0,
      "channels": ["..."],
      "ad_sets": [{"name": "...", "audience": "...", "budget_usd": 0, "bid_strategy": "..."}]
    }
  ],
  "ad_creatives": [{"format": "...", "headline": "...", "body": "...", "cta": "...", "channel": "..."}],
  "targeting": {"interests": ["..."], "behaviours": ["..."], "lookalike_seed": "..."},
  "estimated_reach": 0,
  "estimated_cpc": 0.00,
  "estimated_conversions": 0,
  "estimated_revenue": 0.00,
  "estimated_roas": 0.0,
  "budget_split": {"prospecting": "70%", "retargeting": "30%"},
  "launch_checklist": ["..."],
  "tracking_requirements": ["..."]
}
PROMPT;

        try {
            $raw  = $this->aiRouter->complete($userPrompt, null, 3000, 0.6, $systemPrompt);
            $data = $this->parseJson($raw);

            // Log performance entry for cost tracking
            if ($taskId) {
                $this->performance->log($taskId, 'paid_ads_planned', [
                    'impressions'     => 0,
                    'clicks'          => 0,
                    'conversions'     => $data['estimated_conversions'] ?? 0,
                    'cost_usd'        => (float) $budget,
                    'revenue_estimate'=> (float) ($data['estimated_revenue'] ?? 0),
                ]);
            }

            return ['success' => true, 'fallback' => false, 'data' => $data, 'skill' => $this->getName()];
        } catch (\Throwable $e) {
            Log::error("[PaidAdsSkill] Failed: " . $e->getMessage());
            return [
                'success'  => false,
                'fallback' => true,
                'error'    => $e->getMessage(),
                'data'     => ['campaign_structure' => [], 'launch_checklist' => []],
                'skill'    => $this->getName(),
            ];
        }
    }

    private function parseJson(string $raw): array
    {
        $clean  = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $parsed = json_decode($clean, true);
        if (! $parsed) throw new \RuntimeException("PaidAdsSkill: invalid JSON from AI");
        return $parsed;
    }
}
