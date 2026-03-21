<?php

namespace App\Skills\Marketing;

use App\Services\AI\AIRouter;
use App\Skills\SkillInterface;
use Illuminate\Support\Facades\Log;

/**
 * Audits content for SEO health and returns keyword alignment + on-page recommendations.
 */
class SeoAuditSkill implements SkillInterface
{
    public function __construct(private readonly AIRouter $aiRouter) {}

    public function getName(): string { return 'seo-audit'; }

    public function getDescription(): string
    {
        return 'Audit content for SEO — keyword coverage, on-page signals, title/meta suggestions, and content gaps.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content'          => ['type' => 'string', 'description' => 'Page or post content to audit'],
                'target_keywords'  => ['type' => 'array',  'items' => ['type' => 'string'], 'description' => 'Primary keywords to target'],
                'page_title'       => ['type' => 'string', 'description' => 'Current page title'],
                'meta_description' => ['type' => 'string', 'description' => 'Current meta description'],
                'competitor_urls'  => ['type' => 'array',  'items' => ['type' => 'string'], 'description' => 'Competitor URLs for gap analysis (informational)'],
                'product'          => ['type' => 'string', 'description' => 'Product / brand name'],
            ],
            'required' => ['content'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $content     = $params['content']          ?? '';
        $keywords    = $params['target_keywords']  ?? [];
        $title       = $params['page_title']       ?? '';
        $meta        = $params['meta_description'] ?? '';
        $competitors = $params['competitor_urls']  ?? [];
        $product     = $params['product']          ?? '';

        $kwList   = $keywords    ? implode(', ', $keywords)    : 'not specified';
        $compList = $competitors ? implode(', ', $competitors) : 'none provided';

        $systemPrompt = "You are an SEO specialist. Return ONLY valid JSON.";

        $userPrompt = <<<PROMPT
Perform an SEO audit.

Product: {$product}
Target keywords: {$kwList}
Current title: {$title}
Current meta: {$meta}
Competitor URLs (for gap context): {$compList}

CONTENT:
{$content}

Return JSON:
{
  "seo_score": 0-100,
  "keyword_density": {"primary": {}, "secondary": {}},
  "title_analysis": {"current": "...", "issues": "...", "suggested": "..."},
  "meta_analysis": {"current": "...", "issues": "...", "suggested": "..."},
  "content_gaps": ["..."],
  "heading_structure": {"issues": "...", "suggestions": ["..."]},
  "internal_linking": {"recommendation": "..."},
  "featured_snippet_opportunity": {"exists": true, "format": "paragraph|list|table", "draft": "..."},
  "quick_wins": [{"action": "...", "impact": "high|medium|low"}],
  "long_tail_keywords": ["..."],
  "recommended_word_count": 0
}
PROMPT;

        try {
            $raw  = $this->aiRouter->complete($userPrompt, null, 2500, 0.4, $systemPrompt);
            $data = $this->parseJson($raw);

            return ['success' => true, 'fallback' => false, 'data' => $data, 'skill' => $this->getName()];
        } catch (\Throwable $e) {
            Log::error("[SeoAuditSkill] Failed: " . $e->getMessage());
            return [
                'success'  => false,
                'fallback' => true,
                'error'    => $e->getMessage(),
                'data'     => ['seo_score' => 0, 'quick_wins' => []],
                'skill'    => $this->getName(),
            ];
        }
    }

    private function parseJson(string $raw): array
    {
        $clean  = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $parsed = json_decode($clean, true);
        if (! $parsed) throw new \RuntimeException("SeoAuditSkill: invalid JSON from AI");
        return $parsed;
    }
}
