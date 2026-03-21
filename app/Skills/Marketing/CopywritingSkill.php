<?php

namespace App\Skills\Marketing;

use App\Services\AI\AIRouter;
use App\Skills\SkillInterface;
use Illuminate\Support\Facades\Log;

/**
 * Generates 3 copy variations (A/B/C) — each with a distinct hook, tone, and structure.
 * Uses product context when available.
 */
class CopywritingSkill implements SkillInterface
{
    public function __construct(private readonly AIRouter $aiRouter) {}

    public function getName(): string { return 'copywriting'; }

    public function getDescription(): string
    {
        return 'Generate persuasive marketing copy with 3 A/B/C variations per output — each differs in hook, tone, and structure.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product'        => ['type' => 'string', 'description' => 'Product or service name'],
                'audience'       => ['type' => 'string', 'description' => 'Target audience description'],
                'format'         => ['type' => 'string', 'enum' => ['ad', 'email', 'social', 'landing_page', 'tagline'], 'description' => 'Content format'],
                'tone'           => ['type' => 'string', 'description' => 'Desired tone (optional — will vary across variations)'],
                'value_prop'     => ['type' => 'string', 'description' => 'Core value proposition'],
                'context'        => ['type' => 'string', 'description' => 'Additional product/market context'],
                'winning_hooks'  => ['type' => 'string', 'description' => 'Past winning hooks to reuse the formula of'],
            ],
            'required' => ['product', 'audience', 'format'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $product    = $params['product']    ?? 'the product';
        $audience   = $params['audience']   ?? 'general audience';
        $format     = $params['format']     ?? 'ad';
        $valueProp  = $params['value_prop'] ?? '';
        $context    = $params['context']    ?? '';
        $hooks      = $params['winning_hooks'] ?? '';

        $hooksBlock = $hooks
            ? "\n\nWINNING HOOK PATTERNS TO REUSE (vary the angle, not the formula):\n{$hooks}"
            : '';

        $contextBlock = $context ? "\nContext: {$context}" : '';
        $valuePropBlock = $valueProp ? "\nValue proposition: {$valueProp}" : '';

        $systemPrompt = <<<SYS
You are a world-class direct-response copywriter. You produce concise, high-converting marketing copy.
Always return ONLY valid JSON — no markdown, no explanation outside JSON.
SYS;

        $userPrompt = <<<PROMPT
Write 3 copy variations (A, B, C) for a {$format}.

Product: {$product}
Target audience: {$audience}{$valuePropBlock}{$contextBlock}{$hooksBlock}

Rules:
- Each variation MUST differ in hook, tone, and structure
- Variation A: Direct / benefit-led
- Variation B: Conversational / story-led
- Variation C: Aspirational / outcome-led
- Keep each concise and punchy (appropriate for {$format})

Return this exact JSON:
{
  "variations": {
    "A": {"hook": "...", "body": "...", "cta": "...", "tone": "direct"},
    "B": {"hook": "...", "body": "...", "cta": "...", "tone": "conversational"},
    "C": {"hook": "...", "body": "...", "cta": "...", "tone": "aspirational"}
  },
  "format": "{$format}",
  "product": "{$product}"
}
PROMPT;

        try {
            $raw  = $this->aiRouter->complete($userPrompt, null, 2000, 0.8, $systemPrompt);
            $data = $this->parseJson($raw);

            return [
                'success'   => true,
                'fallback'  => false,
                'data'      => $data,
                'skill'     => $this->getName(),
            ];
        } catch (\Throwable $e) {
            Log::error("[CopywritingSkill] Failed: " . $e->getMessage());
            return [
                'success'  => false,
                'fallback' => true,
                'error'    => $e->getMessage(),
                'data'     => ['variations' => [], 'format' => $format, 'product' => $product],
                'skill'    => $this->getName(),
            ];
        }
    }

    private function parseJson(string $raw): array
    {
        $clean  = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $parsed = json_decode($clean, true);

        if (! $parsed) {
            throw new \RuntimeException("CopywritingSkill: invalid JSON from AI");
        }

        return $parsed;
    }
}
