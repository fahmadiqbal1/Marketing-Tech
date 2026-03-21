<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeBase;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VectorStoreService
{
    private int $chunkSize    = 1000;
    private int $chunkOverlap = 200;

    public function __construct(private readonly AIRouter $aiRouter) {}

    /**
     * Store a knowledge item with embedding. Long content is auto-chunked.
     *
     * Centralised deduplication: computes a content_hash from the first 1000
     * normalised chars of the full content. If a matching hash already exists
     * in the knowledge base, the entry is skipped and the existing parent ID
     * is returned. This dedup applies to ALL ingestion sources (manual, GitHub,
     * agent skills seeder, etc.) — no per-source logic needed.
     */
    public function store(
        string  $title,
        string  $content,
        array   $tags     = [],
        string  $category = 'general',
        ?string $source   = null,
    ): string {
        // Soft growth cap: prevent unbounded accumulation (50k entries)
        $totalEntries = KnowledgeBase::whereNull('parent_id')->count();
        if ($totalEntries >= 50000) {
            Log::warning('VectorStoreService: knowledge base soft cap reached — pruning oldest 1000 low-access entries', [
                'total' => $totalEntries,
            ]);
            // Prune oldest entries with zero accesses first, then by age
            $toPrune = KnowledgeBase::whereNull('parent_id')
                ->where('access_count', 0)
                ->orderBy('created_at')
                ->limit(1000)
                ->pluck('id');

            if ($toPrune->isEmpty()) {
                // Fall back to pruning by age if all have accesses
                $toPrune = KnowledgeBase::whereNull('parent_id')
                    ->orderBy('created_at')
                    ->limit(1000)
                    ->pluck('id');
            }

            KnowledgeBase::whereIn('id', $toPrune)->delete();
        }

        // Compute content hash for deduplication (1000 chars = lower collision risk)
        $normalised  = strtolower(preg_replace('/\s+/', ' ', trim($content)));
        $contentHash = md5(mb_substr($normalised, 0, 1000, 'UTF-8'));

        // Check for duplicate (any source)
        $existing = KnowledgeBase::where('content_hash', $contentHash)->first();
        if ($existing) {
            Log::debug("Knowledge store skipped — duplicate content hash", [
                'title'    => $title,
                'hash'     => $contentHash,
                'existing' => $existing->title,
            ]);
            return (string) ($existing->parent_id ?? $existing->id);
        }

        $chunks   = $this->chunkText($content);
        $parentId = (string) Str::uuid();

        foreach ($chunks as $i => $chunk) {
            $embeddingText = "{$title}\n{$chunk}";
            $embedding     = $this->aiRouter->embed($embeddingText);

            KnowledgeBase::create([
                'id'           => $i === 0 ? $parentId : (string) Str::uuid(),
                'title'        => $title,
                'content'      => $chunk,
                'category'     => $category,
                'tags'         => $tags,
                'source'       => $source,
                'embedding'    => '[' . implode(',', $embedding) . ']',
                'chunk_index'  => $i,
                'parent_id'    => $i > 0 ? $parentId : null,
                // Store hash only on the parent chunk; child chunks inherit dedup via parent
                'content_hash' => $i === 0 ? $contentHash : null,
            ]);
        }

        Log::debug("Knowledge stored", ['title' => $title, 'chunks' => count($chunks)]);
        return $parentId;
    }

    /**
     * Search knowledge base using vector similarity.
     */
    public function search(string $query, int $topK = 5, ?string $category = null): array
    {
        try {
            $embedding = $this->aiRouter->embed($query);
            $results   = KnowledgeBase::semanticSearch($embedding, $topK, $category);

            return $results->map(fn($r) => [
                'id'         => $r->id,
                'title'      => $r->title,
                'content'    => $r->content,
                'category'   => $r->category,
                'similarity' => round($r->similarity ?? 0, 4),
            ])->toArray();
        } catch (\Throwable $e) {
            Log::warning("Vector search failed", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Delete all chunks of a knowledge item by parent ID.
     */
    public function delete(string $id): void
    {
        KnowledgeBase::where('id', $id)->orWhere('parent_id', $id)->delete();
    }

    // ── Private helpers ───────────────────────────────────────────

    private function chunkText(string $text): array
    {
        if (strlen($text) <= $this->chunkSize) {
            return [$text];
        }

        $chunks  = [];
        $words   = explode(' ', $text);
        $current = '';

        foreach ($words as $word) {
            if (strlen($current) + strlen($word) + 1 > $this->chunkSize) {
                if ($current) {
                    $chunks[] = trim($current);
                }
                // Start new chunk with overlap from end of previous
                $overlapWords = array_slice(explode(' ', $current), -20);
                $current      = implode(' ', $overlapWords) . ' ' . $word;
            } else {
                $current .= ($current ? ' ' : '') . $word;
            }
        }

        if ($current) {
            $chunks[] = trim($current);
        }

        return array_filter($chunks);
    }
}
