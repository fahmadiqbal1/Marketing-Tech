<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Cache;

/**
 * Brand Brain — persistent brand identity injected into every agent's context.
 * Stores structured identity in knowledge_base under category 'brand'.
 * Agents access it via getRagCategories() including 'brand'.
 */
class BrandBrainService
{
    private const CACHE_KEY = 'brand_brain_identity';
    private const CACHE_TTL = 600; // 10 minutes

    /**
     * Retrieve the current brand identity block.
     * Returns a formatted string ready for prompt injection.
     */
    public function getContextBlock(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $entries = KnowledgeBase::where('category', 'brand')
                ->where('is_active', true)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();

            if ($entries->isEmpty()) {
                return '';
            }

            $parts = ["## Brand Identity\n"];
            foreach ($entries as $entry) {
                $parts[] = $entry->content;
            }

            return implode("\n\n", $parts);
        });
    }

    /**
     * Save or update a brand identity field.
     * Fields: voice, tone, values, audience, visual_style, risk_tolerance, tagline
     */
    public function save(string $field, string $content, ?int $businessId = null): void
    {
        Cache::forget(self::CACHE_KEY);

        $existing = KnowledgeBase::where('category', 'brand')
            ->where('title', "brand.{$field}")
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->first();

        $data = [
            'category'    => 'brand',
            'title'       => "brand.{$field}",
            'content'     => $content,
            'source'      => 'brand_brain',
            'is_active'   => true,
            'business_id' => $businessId,
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            KnowledgeBase::create($data);
        }
    }

    /**
     * Returns the full brand identity as a structured array (for the dashboard).
     */
    public function getAll(?int $businessId = null): array
    {
        return KnowledgeBase::where('category', 'brand')
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn ($e) => [
                str_replace('brand.', '', $e->title) => $e->content,
            ])
            ->toArray();
    }

    /**
     * Flush the brand context cache (call after any save).
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
