<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Stores and retrieves product marketing context for a given agent task.
 *
 * Context is cached per task (24h TTL) so all skills can access it without
 * being coupled to each other. Falls back to empty array — system runs fine
 * without context.
 */
class ProductMarketingContextService
{
    private const TTL_SECONDS = 86400; // 24 hours

    private const SCHEMA = [
        'product_name',
        'description',
        'target_audience',
        'problem_solved',
        'value_proposition',
        'pricing',
    ];

    private function cacheKey(int $taskId): string
    {
        return "marketing_context:task:{$taskId}";
    }

    /**
     * Store product context for a task.
     * Only recognised keys are stored; unknown keys are silently dropped.
     */
    public function set(int $taskId, array $context): void
    {
        $filtered = array_intersect_key($context, array_flip(self::SCHEMA));

        try {
            Cache::put($this->cacheKey($taskId), $filtered, self::TTL_SECONDS);
        } catch (\Throwable $e) {
            Log::warning("[ProductMarketingContextService] Failed to store context for task {$taskId}: " . $e->getMessage());
        }
    }

    /**
     * Retrieve product context. Returns [] if not set — callers must handle missing context.
     *
     * @return array{product_name?:string, description?:string, target_audience?:string, problem_solved?:string, value_proposition?:string, pricing?:string}
     */
    public function get(int $taskId): array
    {
        try {
            return Cache::get($this->cacheKey($taskId), []);
        } catch (\Throwable $e) {
            Log::warning("[ProductMarketingContextService] Failed to load context for task {$taskId}: " . $e->getMessage());
            return [];
        }
    }

    public function has(int $taskId): bool
    {
        return ! empty($this->get($taskId));
    }

    /**
     * Format context as a concise block for injection into LLM prompts.
     * Returns empty string if no context — prompt stays clean.
     */
    public function toPromptContext(int $taskId): string
    {
        $ctx = $this->get($taskId);

        if (empty($ctx)) {
            return '';
        }

        $lines = ["## Product Context"];

        if (! empty($ctx['product_name']))      $lines[] = "Product: {$ctx['product_name']}";
        if (! empty($ctx['description']))        $lines[] = "Description: {$ctx['description']}";
        if (! empty($ctx['target_audience']))    $lines[] = "Target Audience: {$ctx['target_audience']}";
        if (! empty($ctx['problem_solved']))     $lines[] = "Problem Solved: {$ctx['problem_solved']}";
        if (! empty($ctx['value_proposition'])) $lines[] = "Value Proposition: {$ctx['value_proposition']}";
        if (! empty($ctx['pricing']))            $lines[] = "Pricing: {$ctx['pricing']}";

        return implode("\n", $lines) . "\n\n";
    }
}
