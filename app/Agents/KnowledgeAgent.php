<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Models\ContextGraphNode;
use App\Models\KnowledgeBase;
use App\Services\Knowledge\ContextGraphService;
use App\Services\Knowledge\VectorStoreService;
use Illuminate\Support\Facades\Log;

class KnowledgeAgent extends BaseAgent
{
    protected string $agentType = 'knowledge';

    public function __construct(
        \App\Services\AI\OpenAIService            $openai,
        \App\Services\AI\AnthropicService         $anthropic,
        \App\Services\Telegram\TelegramBotService  $telegram,
        \App\Services\Knowledge\VectorStoreService $knowledge,
        private readonly ContextGraphService       $contextGraph,
    ) {
        parent::__construct($openai, $anthropic, $telegram, $knowledge);
    }

    protected function executeTool(string $name, array $args, AgentJob $job): mixed
    {
        return match ($name) {
            'store_knowledge'      => $this->toolStoreKnowledge($args),
            'search_knowledge'     => $this->toolSearchKnowledge($args),
            'create_graph_node'    => $this->toolCreateGraphNode($args),
            'create_graph_edge'    => $this->toolCreateGraphEdge($args),
            'traverse_graph'       => $this->toolTraverseGraph($args),
            'get_related_context'  => $this->toolGetRelatedContext($args),
            'summarise_knowledge'  => $this->toolSummariseKnowledge($args),
            'delete_knowledge'     => $this->toolDeleteKnowledge($args),
            default => $this->toolResult(false, null, "Unknown tool: {$name}"),
        };
    }

    protected function getToolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'store_knowledge',
                    'description' => 'Store a fact, document, or learning in the knowledge base with semantic embedding',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'title'    => ['type' => 'string'],
                            'content'  => ['type' => 'string'],
                            'category' => ['type' => 'string', 'description' => 'e.g. brand, strategy, product, hiring, marketing'],
                            'tags'     => ['type' => 'array', 'items' => ['type' => 'string']],
                            'source'   => ['type' => 'string', 'description' => 'Where this came from'],
                        ],
                        'required' => ['title', 'content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'search_knowledge',
                    'description' => 'Semantically search the knowledge base',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query'    => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'limit'    => ['type' => 'integer'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'create_graph_node',
                    'description' => 'Add a node to the context graph for long-term relational memory',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'type'       => ['type' => 'string', 'enum' => ['fact', 'campaign', 'candidate', 'experiment', 'result', 'learning', 'entity']],
                            'category'   => ['type' => 'string'],
                            'title'      => ['type' => 'string'],
                            'content'    => ['type' => 'string'],
                            'importance' => ['type' => 'integer', 'description' => '1-10'],
                            'attributes' => ['type' => 'object'],
                            'tags'       => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['type', 'title', 'content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'create_graph_edge',
                    'description' => 'Create a relationship between two context graph nodes',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'source_node_id' => ['type' => 'string'],
                            'target_node_id' => ['type' => 'string'],
                            'relation_type'  => ['type' => 'string', 'enum' => ['caused', 'improved', 'related_to', 'followed_by', 'contradicts', 'supports']],
                            'strength'       => ['type' => 'number', 'description' => '0.0-1.0'],
                            'description'    => ['type' => 'string'],
                        ],
                        'required' => ['source_node_id', 'target_node_id', 'relation_type'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'traverse_graph',
                    'description' => 'Follow relationships from a node to discover connected knowledge',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'node_id'        => ['type' => 'string'],
                            'relation_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'depth'          => ['type' => 'integer', 'description' => 'Max traversal depth 1-3'],
                            'direction'      => ['type' => 'string', 'enum' => ['outgoing', 'incoming', 'both']],
                        ],
                        'required' => ['node_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_related_context',
                    'description' => 'Get semantically related context for a given topic or query',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query'    => ['type' => 'string'],
                            'type'     => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'limit'    => ['type' => 'integer'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'summarise_knowledge',
                    'description' => 'Get an AI-generated summary of stored knowledge on a topic',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'topic'    => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                        ],
                        'required' => ['topic'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'delete_knowledge',
                    'description' => 'Remove a knowledge node by ID',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'node_id' => ['type' => 'string'],
                            'confirm' => ['type' => 'boolean'],
                        ],
                        'required' => ['node_id', 'confirm'],
                    ],
                ],
            ],
        ];
    }

    // ── Tool Implementations ──────────────────────────────────────

    private function toolStoreKnowledge(array $args): string
    {
        try {
            $id = $this->knowledge->store(
                title:    $args['title'],
                content:  $args['content'],
                tags:     $args['tags']     ?? [],
                category: $args['category'] ?? 'general',
                source:   $args['source']   ?? 'agent',
            );
            return $this->toolResult(true, ['id' => $id, 'title' => $args['title']]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolSearchKnowledge(array $args): string
    {
        try {
            $results = $this->knowledge->search(
                query:    $args['query'],
                topK:     $args['limit']    ?? 5,
                category: $args['category'] ?? null,
            );
            return $this->toolResult(true, $results);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCreateGraphNode(array $args): string
    {
        try {
            $node = $this->contextGraph->createNode(
                type:       $args['type'],
                title:      $args['title'],
                content:    $args['content'],
                category:   $args['category']   ?? null,
                importance: $args['importance']  ?? 5,
                attributes: $args['attributes']  ?? [],
                tags:       $args['tags']        ?? [],
            );
            return $this->toolResult(true, ['node_id' => $node->id, 'title' => $node->title]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCreateGraphEdge(array $args): string
    {
        try {
            $edge = $this->contextGraph->createEdge(
                sourceId:     $args['source_node_id'],
                targetId:     $args['target_node_id'],
                relationType: $args['relation_type'],
                strength:     $args['strength']    ?? 1.0,
                description:  $args['description'] ?? null,
            );
            return $this->toolResult(true, ['edge_id' => $edge->id, 'relation' => $edge->relation_type]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolTraverseGraph(array $args): string
    {
        try {
            $nodes = $this->contextGraph->traverse(
                nodeId:        $args['node_id'],
                depth:         min($args['depth'] ?? 2, 3),
                direction:     $args['direction']      ?? 'both',
                relationTypes: $args['relation_types'] ?? [],
            );
            return $this->toolResult(true, $nodes);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGetRelatedContext(array $args): string
    {
        try {
            $embedding = $this->openai->embed($args['query']);
            $nodes     = ContextGraphNode::semanticSearch(
                embedding: $embedding,
                topK:      $args['limit']    ?? 8,
                type:      $args['type']     ?? null,
                category:  $args['category'] ?? null,
            );
            foreach ($nodes as $node) {
                ContextGraphNode::find($node->id)?->markAccessed();
            }
            return $this->toolResult(true, $nodes->toArray());
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolSummariseKnowledge(array $args): string
    {
        try {
            $embedding = $this->openai->embed($args['topic']);
            $items     = KnowledgeBase::semanticSearch($embedding, 10, $args['category'] ?? null);

            if ($items->isEmpty()) {
                return $this->toolResult(true, ['summary' => 'No knowledge found on this topic.', 'items_found' => 0]);
            }

            $knowledgeText = $items->map(fn($i) => "**{$i->title}**\n{$i->content}")->implode("\n\n");

            $summary = $this->anthropic->complete(
                prompt: "Summarise this knowledge about '{$args['topic']}' into a concise, useful overview:\n\n{$knowledgeText}",
                maxTokens: 1000,
                temperature: 0.3,
            );

            return $this->toolResult(true, [
                'topic'       => $args['topic'],
                'summary'     => $summary,
                'items_found' => $items->count(),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolDeleteKnowledge(array $args): string
    {
        if (! ($args['confirm'] ?? false)) {
            return $this->toolResult(false, null, 'Deletion requires confirm=true');
        }
        try {
            $deleted = ContextGraphNode::destroy($args['node_id']);
            return $this->toolResult(true, ['deleted' => $deleted > 0, 'node_id' => $args['node_id']]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }
}
