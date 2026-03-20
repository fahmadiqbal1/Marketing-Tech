<?php

namespace App\Services\Knowledge;

use App\Models\ContextGraphNode;
use App\Models\ContextGraphEdge;
use App\Models\Workflow;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ContextGraphService
{
    public function __construct(
        private readonly AIRouter $aiRouter,
    ) {}

    /**
     * Stage 10: Create a new node in the context graph with embedding.
     */
    public function createNode(
        string $type,
        string $title,
        string $content,
        array  $attributes = [],
        array  $tags       = [],
        string $category   = 'general',
        int    $importance = 5,
    ): ContextGraphNode {
        $embeddingText = "{$title}\n{$content}";
        $embedding     = $this->aiRouter->embed($embeddingText);

        $node = ContextGraphNode::create([
            'id'         => (string) Str::uuid(),
            'type'       => $type,
            'title'      => $title,
            'content'    => $content,
            'attributes' => $attributes,
            'tags'       => $tags,
            'category'   => $category,
            'importance' => $importance,
            'embedding'  => '[' . implode(',', $embedding) . ']',
        ]);

        Log::debug("Context graph node created", ['id' => $node->id, 'type' => $type]);

        return $node;
    }

    /**
     * Create a directed edge between two nodes.
     */
    public function createEdge(
        string  $sourceId,
        string  $targetId,
        string  $relationType,
        float   $strength    = 1.0,
        ?string $description = null,
    ): ContextGraphEdge {
        return ContextGraphEdge::updateOrCreate(
            ['source_node_id' => $sourceId, 'target_node_id' => $targetId, 'relation_type' => $relationType],
            ['strength' => $strength, 'description' => $description]
        );
    }

    /**
     * Retrieve relevant context for a workflow via semantic search + graph traversal.
     */
    public function retrieveForWorkflow(
        string $instruction,
        string $workflowType,
        int    $topK = 8,
    ): array {
        $embedding = $this->aiRouter->embed($instruction);

        // Semantic search for most relevant nodes
        $nodes = ContextGraphNode::semanticSearch($embedding, $topK, null, $workflowType);

        if ($nodes->isEmpty()) {
            // Fall back to broader search without category filter
            $nodes = ContextGraphNode::semanticSearch($embedding, $topK);
        }

        // Mark these nodes as accessed
        foreach ($nodes as $node) {
            ContextGraphNode::find($node->id)?->markAccessed();
        }

        // Expand context via graph edges (1-hop neighborhood)
        $expandedNodeIds = $nodes->pluck('id')->toArray();
        $neighborNodes   = $this->getNeighbors($expandedNodeIds, maxHops: 1);

        $allNodes = $nodes->map(fn($n) => [
            'id'          => $n->id,
            'type'        => $n->type,
            'title'       => $n->title,
            'content'     => $n->content,
            'similarity'  => round($n->similarity ?? 0, 4),
            'category'    => $n->category,
        ])->toArray();

        // Deduplicate neighbors
        foreach ($neighborNodes as $neighbor) {
            if (! in_array($neighbor->id, $expandedNodeIds)) {
                $allNodes[] = [
                    'id'       => $neighbor->id,
                    'type'     => $neighbor->type,
                    'title'    => $neighbor->title,
                    'content'  => substr($neighbor->content, 0, 300),
                    'category' => $neighbor->category,
                    'via_edge' => true,
                ];
            }
        }

        // Get relevant edges between found nodes
        $nodeIds = array_column($allNodes, 'id');
        $edges   = ContextGraphEdge::whereIn('source_node_id', $nodeIds)
            ->whereIn('target_node_id', $nodeIds)
            ->get(['source_node_id', 'target_node_id', 'relation_type', 'strength'])
            ->toArray();

        return [
            'nodes'       => $allNodes,
            'edges'       => $edges,
            'retrieved_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Stage 10: Semantic search across all graph nodes.
     */
    public function search(string $query, int $topK = 5, ?string $category = null): array
    {
        $embedding = $this->aiRouter->embed($query);
        $nodes     = ContextGraphNode::semanticSearch($embedding, $topK, null, $category);

        return $nodes->map(fn($n) => [
            'id'         => $n->id,
            'title'      => $n->title,
            'content'    => $n->content,
            'type'       => $n->type,
            'similarity' => round($n->similarity ?? 0, 4),
        ])->toArray();
    }

    /**
     * Store learnings from a completed workflow into the context graph.
     */
    public function learnFromWorkflow(Workflow $workflow): void
    {
        try {
            $summary = $this->generateWorkflowSummary($workflow);
            if (! $summary) {
                return;
            }

            // Create a "result" node for this workflow's outcome
            $resultNode = $this->createNode(
                type:       'result',
                title:      "Workflow result: {$workflow->name}",
                content:    $summary,
                attributes: [
                    'workflow_id'  => $workflow->id,
                    'workflow_type' => $workflow->type,
                    'task_count'   => $workflow->tasks()->count(),
                    'duration_s'   => $workflow->started_at
                        ? now()->diffInSeconds($workflow->started_at) : 0,
                ],
                tags:       [$workflow->type, 'completed_workflow'],
                category:   $workflow->type,
                importance: 6,
            );

            // If input contained identifiable entities, link them
            $instruction = $workflow->input_payload['instruction'] ?? '';
            if (strlen($instruction) > 20) {
                $this->createNode(
                    type:       'learning',
                    title:      "Learning from: {$workflow->name}",
                    content:    "Instruction: {$instruction}\nOutcome: {$summary}",
                    attributes: ['workflow_id' => $workflow->id],
                    tags:       [$workflow->type, 'learning'],
                    category:   $workflow->type,
                    importance: 4,
                );
            }

            Log::info("Context graph updated with workflow learnings", ['workflow_id' => $workflow->id]);

        } catch (\Throwable $e) {
            Log::warning("Failed to store workflow learnings", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Store an arbitrary fact or knowledge item.
     */
    public function storeFact(
        string $title,
        string $content,
        array  $tags     = [],
        string $category = 'general',
    ): ContextGraphNode {
        return $this->createNode('fact', $title, $content, [], $tags, $category, 5);
    }

    /**
     * Get 1-hop neighbors of a set of node IDs.
     */
    public function getNeighbors(array $nodeIds, int $maxHops = 1): \Illuminate\Support\Collection
    {
        return DB::table('context_graph_nodes as n')
            ->join('context_graph_edges as e', function ($join) use ($nodeIds) {
                $join->on('n.id', '=', 'e.target_node_id')
                     ->whereIn('e.source_node_id', $nodeIds);
            })
            ->select('n.*')
            ->orderBy('e.strength', 'desc')
            ->limit(10)
            ->get();
    }

    // ── Private helpers ───────────────────────────────────────────

    private function generateWorkflowSummary(Workflow $workflow): ?string
    {
        $tasks  = $workflow->tasks()->where('status', 'completed')->get();
        $output = $workflow->output ?? [];

        if ($tasks->isEmpty() && empty($output)) {
            return null;
        }

        $taskSummary = $tasks->map(fn($t) => "- {$t->name}: completed")->implode("\n");
        $outputKeys  = array_keys($output);

        $prompt = <<<PROMPT
Summarize this completed workflow in 2-3 sentences for future reference.

Workflow: {$workflow->name} (type: {$workflow->type})
Tasks completed: {$tasks->count()}
{$taskSummary}
Output contains: {implode(', ', $outputKeys)}

Write a concise summary of what was accomplished and any notable outcomes.
PROMPT;

        try {
            return $this->aiRouter->complete($prompt, 'claude-haiku-4-5-20251001', 300, 0.3);
        } catch (\Throwable) {
            return "Workflow '{$workflow->name}' completed with {$tasks->count()} tasks.";
        }
    }

    /**
     * Traverse the graph from seed node IDs following typed edges.
     * Returns all reachable nodes within maxHops distance.
     */
    public function traverse(array $startNodeIds, string $relationType = '', int $maxHops = 2): array
    {
        if (empty($startNodeIds)) {
            return [];
        }

        $visited = array_fill_keys($startNodeIds, true);
        $current = $startNodeIds;
        $results = [];

        for ($hop = 0; $hop < $maxHops; $hop++) {
            $query = \Illuminate\Support\Facades\DB::table('context_graph_edges')
                ->whereIn('source_node_id', $current);

            if ($relationType !== '') {
                $query->where('relation_type', $relationType);
            }

            $edges       = $query->get();
            $nextNodeIds = [];

            foreach ($edges as $edge) {
                $targetId = $edge->target_node_id;
                if (! isset($visited[$targetId])) {
                    $visited[$targetId] = true;
                    $nextNodeIds[]      = $targetId;

                    $node = ContextGraphNode::find($targetId);
                    if ($node) {
                        $results[] = [
                            'id'           => $node->id,
                            'type'         => $node->type,
                            'title'        => $node->title,
                            'content'      => $node->content,
                            'relation'     => $edge->relation_type,
                            'hop_distance' => $hop + 1,
                        ];
                    }
                }
            }

            $current = $nextNodeIds;
            if (empty($current)) {
                break;
            }
        }

        return $results;
    }
}
