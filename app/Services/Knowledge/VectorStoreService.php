<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeBase;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PageIndex-style vectorless RAG service.
 *
 * Ingestion  — LLM builds a hierarchical "table of contents" (index_tree) for
 *              each stored document. Chunks are tagged with a short node_id that
 *              maps them back to a tree node.
 *
 * Retrieval  — LLM reasons over the compact index catalog (titles + summaries
 *              only, NOT full content) to identify which parent documents and
 *              sections are relevant. Actual content is then fetched by ID from
 *              the database. No vector embeddings are used in the hot path.
 *
 * Fallback   — When index_tree is absent (legacy data) a text ILIKE search is
 *              used so nothing is ever silently dropped.
 */
class VectorStoreService
{
    private int $chunkSize    = 1200;
    private int $chunkOverlap = 150;

    public function __construct(private readonly AIRouter $aiRouter) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Store a knowledge item and build a PageIndex tree.
     * Long content is auto-chunked; the tree is built from the full text first.
     */
    public function store(
        string  $title,
        string  $content,
        array   $tags     = [],
        string  $category = 'general',
        ?string $source   = null,
    ): string {
        // Soft growth cap
        $totalEntries = KnowledgeBase::whereNull('parent_id')->count();
        if ($totalEntries >= 50000) {
            Log::warning('VectorStoreService: knowledge base soft cap reached — pruning 1000 entries');
            $toPrune = KnowledgeBase::whereNull('parent_id')
                ->where('access_count', 0)->orderBy('created_at')->limit(1000)->pluck('id');
            if ($toPrune->isEmpty()) {
                $toPrune = KnowledgeBase::whereNull('parent_id')
                    ->orderBy('created_at')->limit(1000)->pluck('id');
            }
            KnowledgeBase::whereIn('id', $toPrune)->delete();
        }

        // Deduplication
        $normalised  = strtolower(preg_replace('/\s+/', ' ', trim($content)));
        $contentHash = md5(mb_substr($normalised, 0, 1000, 'UTF-8'));

        $existing = KnowledgeBase::where('content_hash', $contentHash)->first();
        if ($existing) {
            return (string) ($existing->parent_id ?? $existing->id);
        }

        // Build PageIndex tree (LLM call on full content)
        $indexTree = $this->buildPageIndex($title, $content);

        // Chunk content — assign node IDs from the tree
        $chunks   = $this->chunkText($content);
        $parentId = (string) Str::uuid();
        $treeNodes = $indexTree['nodes'] ?? [];

        foreach ($chunks as $i => $chunk) {
            $nodeId = $treeNodes[$i]['node_id'] ?? sprintf('%04d', $i);

            KnowledgeBase::create([
                'id'           => $i === 0 ? $parentId : (string) Str::uuid(),
                'title'        => $title,
                'content'      => $chunk,
                'category'     => $category,
                'tags'         => $tags,
                'source'       => $source,
                'embedding'    => null,   // no longer generated
                'chunk_index'  => $i,
                'parent_id'    => $i > 0 ? $parentId : null,
                'content_hash' => $i === 0 ? $contentHash : null,
                'index_tree'   => $i === 0 ? $indexTree : null,
                'node_id'      => $nodeId,
            ]);
        }

        Log::debug('Knowledge stored (PageIndex)', [
            'title'  => $title,
            'chunks' => count($chunks),
            'nodes'  => count($treeNodes),
        ]);

        return $parentId;
    }

    /**
     * PageIndex-style reasoning search.
     *
     * Step 1 — Build a compact catalog of all parent entries (titles + summaries).
     * Step 2 — Ask the LLM to reason and return relevant parent IDs.
     * Step 3 — Fetch chunks belonging to those parents from the DB.
     *
     * Legacy entries (no index_tree) fall back to ILIKE text search.
     */
    public function search(
        string  $query,
        int     $topK       = 5,
        ?string $category   = null,
        array   $categories = [],
    ): array {
        try {
            return $this->pageIndexSearch($query, $topK, $category, $categories);
        } catch (\Throwable $e) {
            Log::warning('PageIndex search failed, falling back to text search', [
                'error' => $e->getMessage(),
            ]);
            return $this->textFallbackSearch($query, $topK, $category, $categories);
        }
    }

    /**
     * Delete all chunks of a knowledge item by parent ID.
     */
    public function delete(string $id): void
    {
        KnowledgeBase::where('id', $id)->orWhere('parent_id', $id)->delete();
    }

    // ── PageIndex core ────────────────────────────────────────────────────────

    /**
     * Build a hierarchical table-of-contents for a document using the LLM.
     * Returns a JSON-serialisable array compatible with the DB jsonb column.
     */
    private function buildPageIndex(string $title, string $content): array
    {
        // For short content, build a minimal single-node index without an API call
        if (mb_strlen($content, 'UTF-8') <= $this->chunkSize) {
            $preview = mb_substr($content, 0, 200, 'UTF-8');
            return [
                'title'   => $title,
                'summary' => $preview,
                'nodes'   => [
                    ['node_id' => '0001', 'title' => $title, 'summary' => $preview],
                ],
            ];
        }

        $excerpt = mb_substr($content, 0, 3000, 'UTF-8'); // keep LLM call cheap

        $prompt = <<<PROMPT
Analyse this document and return ONLY a JSON object (no markdown, no explanation):
{
  "title": "document title",
  "summary": "one-sentence overview of the entire document",
  "nodes": [
    {"node_id": "0001", "title": "Section name", "summary": "What this section covers in one sentence"},
    {"node_id": "0002", "title": "Section name", "summary": "..."}
  ]
}
Create one node per ~300 words. Use sequential node_ids (0001, 0002, …).
Document title: {$title}
Document (first 3000 chars): {$excerpt}
PROMPT;

        try {
            $raw   = $this->aiRouter->complete($prompt, 'gpt-4o-mini', 600, 0.0);
            $clean = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', trim($raw ?? ''))));
            $tree  = json_decode($clean, true);

            if (is_array($tree) && isset($tree['nodes'])) {
                return $tree;
            }
        } catch (\Throwable $e) {
            Log::debug('PageIndex build failed, using minimal index', ['error' => $e->getMessage()]);
        }

        // Fallback minimal index
        return [
            'title'   => $title,
            'summary' => mb_substr($content, 0, 200, 'UTF-8'),
            'nodes'   => [['node_id' => '0001', 'title' => $title, 'summary' => mb_substr($content, 0, 200, 'UTF-8')]],
        ];
    }

    /**
     * Two-step LLM-reasoning retrieval.
     */
    private function pageIndexSearch(
        string  $query,
        int     $topK,
        ?string $category,
        array   $categories,
    ): array {
        // ── Step 1: load compact index catalog ──────────────────────────────
        $parentsQuery = KnowledgeBase::whereNull('parent_id')
            ->whereNotNull('index_tree');

        if (! empty($categories)) {
            $parentsQuery->whereIn('category', $categories);
        } elseif ($category) {
            $parentsQuery->where('category', $category);
        }

        $parents = $parentsQuery->limit(200)->get(['id', 'title', 'category', 'index_tree']);

        // ── Step 2: LLM reasons over catalog ────────────────────────────────
        $relevantIds = [];

        if ($parents->isNotEmpty()) {
            $catalog = $parents->map(fn ($p) => [
                'id'       => $p->id,
                'title'    => $p->title,
                'category' => $p->category,
                'summary'  => $p->index_tree['summary'] ?? '',
                'sections' => collect($p->index_tree['nodes'] ?? [])
                    ->map(fn ($n) => $n['node_id'] . ': ' . ($n['title'] ?? '') . ' — ' . ($n['summary'] ?? ''))
                    ->implode(' | '),
            ])->values()->toArray();

            $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $prompt = <<<PROMPT
You are a knowledge retrieval assistant. Return ONLY a JSON array of the most relevant document IDs.

Query: "{$query}"

Knowledge catalog (each entry has id, title, category, summary, sections):
{$catalogJson}

Return the IDs of the top {$topK} most relevant documents as a JSON array, e.g. ["uuid1","uuid2"].
If nothing is relevant, return [].
PROMPT;

            try {
                $raw  = $this->aiRouter->complete($prompt, 'gpt-4o-mini', 300, 0.0);
                $clean = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', trim($raw ?? ''))));
                $ids  = json_decode($clean, true);
                if (is_array($ids)) {
                    $relevantIds = array_filter($ids, 'is_string');
                }
            } catch (\Throwable $e) {
                Log::debug('PageIndex LLM reasoning failed', ['error' => $e->getMessage()]);
            }
        }

        // ── Step 3: fetch chunks for relevant parent IDs ─────────────────────
        $results = [];

        if (! empty($relevantIds)) {
            $chunks = KnowledgeBase::where(function ($q) use ($relevantIds) {
                $q->whereIn('id', $relevantIds)
                  ->orWhereIn('parent_id', $relevantIds);
            })->orderBy('chunk_index')->limit($topK * 3)->get([
                'id', 'title', 'content', 'category', 'chunk_index', 'parent_id', 'node_id',
            ]);

            // Group by parent, take first chunk as representative, respect topK
            $seen   = [];
            $count  = 0;
            foreach ($chunks as $chunk) {
                $key = $chunk->parent_id ?? $chunk->id;
                if (isset($seen[$key]) || $count >= $topK) continue;
                $seen[$key] = true;
                $count++;

                $results[] = [
                    'id'         => $chunk->id,
                    'title'      => $chunk->title,
                    'content'    => $chunk->content,
                    'category'   => $chunk->category,
                    'similarity' => null, // PageIndex doesn't use similarity scores
                ];

                // Track access
                KnowledgeBase::where('id', $chunk->id)->increment('access_count', 1, ['last_accessed_at' => now()]);
            }
        }

        // ── Step 4: supplement with legacy text search if results are thin ───
        if (count($results) < $topK) {
            $legacy = $this->textFallbackSearch($query, $topK - count($results), $category, $categories);
            $seenIds = array_column($results, 'id');
            foreach ($legacy as $item) {
                if (! in_array($item['id'], $seenIds)) {
                    $results[] = $item;
                }
            }
        }

        return array_slice($results, 0, $topK);
    }

    /**
     * Plain ILIKE text search — used for legacy data without index_tree and as
     * final fallback when LLM reasoning fails.
     */
    private function textFallbackSearch(
        string  $query,
        int     $topK,
        ?string $category,
        array   $categories,
    ): array {
        $q = KnowledgeBase::query()
            ->when(! empty($categories), fn ($q) => $q->whereIn('category', $categories))
            ->when(empty($categories) && $category, fn ($q) => $q->where('category', $category))
            ->where(function ($q) use ($query) {
                $safe = addcslashes($query, '%_\\');
                $q->where('title', 'ilike', "%{$safe}%")
                  ->orWhere('content', 'ilike', "%{$safe}%");
            })
            ->orderByRaw('access_count DESC')
            ->limit($topK)
            ->get(['id', 'title', 'content', 'category']);

        return $q->map(fn ($r) => [
            'id'         => $r->id,
            'title'      => $r->title,
            'content'    => $r->content,
            'category'   => $r->category,
            'similarity' => null,
        ])->toArray();
    }

    // ── Chunking ──────────────────────────────────────────────────────────────

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
                $overlapWords = array_slice(explode(' ', $current), -15);
                $current      = implode(' ', $overlapWords) . ' ' . $word;
            } else {
                $current .= ($current ? ' ' : '') . $word;
            }
        }

        if ($current) {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks));
    }
}
