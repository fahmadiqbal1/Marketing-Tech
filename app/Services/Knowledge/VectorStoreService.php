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
     */
    public function store(
        string  $title,
        string  $content,
        array   $tags     = [],
        string  $category = 'general',
        ?string $source   = null,
    ): string {
        $chunks   = $this->chunkText($content);
        $parentId = (string) Str::uuid();

        foreach ($chunks as $i => $chunk) {
            $embeddingText = "{$title}\n{$chunk}";
            $embedding     = $this->aiRouter->embed($embeddingText);

            KnowledgeBase::create([
                'id'          => $i === 0 ? $parentId : (string) Str::uuid(),
                'title'       => $title,
                'content'     => $chunk,
                'category'    => $category,
                'tags'        => $tags,
                'source'      => $source,
                'embedding'   => '[' . implode(',', $embedding) . ']',
                'chunk_index' => $i,
                'parent_id'   => $i > 0 ? $parentId : null,
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
