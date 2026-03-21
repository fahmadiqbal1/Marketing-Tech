<?php

namespace App\Skills\Marketing;

use App\Services\AI\AIRouter;
use App\Skills\SkillInterface;
use Illuminate\Support\Facades\Log;

/**
 * Analyses a page or landing page content and returns prioritised CRO recommendations.
 */
class PageCroSkill implements SkillInterface
{
    public function __construct(private readonly AIRouter $aiRouter) {}

    public function getName(): string { return 'page-cro'; }

    public function getDescription(): string
    {
        return 'Audit a landing page or ad for conversion rate issues and return prioritised, actionable CRO fixes.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_content'           => ['type' => 'string', 'description' => 'Full page copy / HTML text'],
                'target_action'          => ['type' => 'string', 'description' => 'Desired conversion action (e.g. sign up, purchase, book a call)'],
                'current_conversion_rate'=> ['type' => 'number', 'description' => 'Current CVR % if known'],
                'audience'               => ['type' => 'string', 'description' => 'Target audience'],
                'product'                => ['type' => 'string', 'description' => 'Product or service name'],
            ],
            'required' => ['page_content', 'target_action'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $content   = $params['page_content']            ?? '';
        $action    = $params['target_action']           ?? 'conversion';
        $cvr       = $params['current_conversion_rate'] ?? null;
        $audience  = $params['audience']                ?? '';
        $product   = $params['product']                 ?? '';

        $cvrBlock      = $cvr !== null ? "Current CVR: {$cvr}%" : '';
        $audienceBlock = $audience ? "Target audience: {$audience}" : '';
        $productBlock  = $product  ? "Product: {$product}"          : '';

        $systemPrompt = "You are a CRO (Conversion Rate Optimisation) expert. Return ONLY valid JSON.";

        $userPrompt = <<<PROMPT
Audit this page for conversion rate issues. Target action: {$action}
{$cvrBlock}
{$audienceBlock}
{$productBlock}

PAGE CONTENT:
{$content}

Return JSON:
{
  "overall_score": 0-100,
  "critical_issues": [{"issue": "...", "fix": "...", "impact": "high|medium|low"}],
  "headline_analysis": {"current": "...", "problem": "...", "suggested": "..."},
  "cta_analysis": {"current": "...", "problem": "...", "suggested": "..."},
  "trust_signals": {"missing": ["..."], "present": ["..."]},
  "above_fold": {"assessment": "...", "fixes": ["..."]},
  "recommendations": [{"priority": 1, "action": "...", "expected_lift": "..."}],
  "rewritten_headline": "...",
  "rewritten_cta": "..."
}
PROMPT;

        try {
            $raw  = $this->aiRouter->complete($userPrompt, null, 2500, 0.5, $systemPrompt);
            $data = $this->parseJson($raw);

            return ['success' => true, 'fallback' => false, 'data' => $data, 'skill' => $this->getName()];
        } catch (\Throwable $e) {
            Log::error("[PageCroSkill] Failed: " . $e->getMessage());
            return [
                'success'  => false,
                'fallback' => true,
                'error'    => $e->getMessage(),
                'data'     => ['overall_score' => 0, 'recommendations' => []],
                'skill'    => $this->getName(),
            ];
        }
    }

    private function parseJson(string $raw): array
    {
        $clean  = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $parsed = json_decode($clean, true);
        if (! $parsed) throw new \RuntimeException("PageCroSkill: invalid JSON from AI");
        return $parsed;
    }
}
