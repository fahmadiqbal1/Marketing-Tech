<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the RAGFlow REST API (default port 9380).
 * Enabled via RAGFLOW_ENABLED=true in .env.
 *
 * Docs: https://ragflow.io/docs/dev/http_api_reference
 */
class RAGFlowService
{
    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('agents.ragflow.base_url', 'http://localhost:9380'), '/');
        $this->apiKey  = config('agents.ragflow.api_key', '');
        $this->timeout = config('agents.ragflow.timeout', 60);
    }

    public function isEnabled(): bool
    {
        return config('agents.ragflow.enabled', false) && ! empty($this->apiKey);
    }

    /**
     * Create a dataset (knowledge base) in RAGFlow.
     * Returns the dataset ID.
     */
    public function createDataset(string $name, string $chunkMethod = 'naive'): string
    {
        $response = $this->http()->post('/api/v1/datasets', [
            'name'         => $name,
            'chunk_method' => $chunkMethod,
        ]);

        $this->assertSuccess($response, 'createDataset');

        return $response->json('data.id');
    }

    /**
     * Upload a text document to a RAGFlow dataset.
     * Returns the document ID.
     */
    public function uploadDocument(string $datasetId, string $content, string $title): string
    {
        $response = $this->http()->post("/api/v1/datasets/{$datasetId}/documents", [
            'name'    => $title,
            'content' => $content,
        ]);

        $this->assertSuccess($response, 'uploadDocument');

        return $response->json('data.id');
    }

    /**
     * Retrieve chunks matching a query from one or more datasets.
     * Returns array of ['content' => ..., 'score' => ..., 'document_id' => ...].
     */
    public function retrieve(string $query, array $datasetIds, int $topK = 5, float $threshold = 0.3): array
    {
        $response = $this->http()->post('/api/v1/retrieval', [
            'question'    => $query,
            'dataset_ids' => $datasetIds,
            'top_n'       => $topK,
            'similarity_threshold' => $threshold,
        ]);

        if ($response->failed()) {
            Log::warning('RAGFlow retrieval failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return [];
        }

        return collect($response->json('data.chunks', []))->map(fn ($chunk) => [
            'content'     => $chunk['content'] ?? '',
            'score'       => $chunk['similarity'] ?? null,
            'document_id' => $chunk['document_id'] ?? null,
            'title'       => $chunk['document_keyword'] ?? '',
        ])->filter(fn ($c) => ! empty($c['content']))->values()->all();
    }

    /**
     * Delete a document from a dataset.
     */
    public function deleteDocument(string $datasetId, string $docId): void
    {
        $this->http()->delete("/api/v1/datasets/{$datasetId}/documents/{$docId}");
    }

    /**
     * List all datasets. Returns array of ['id' => ..., 'name' => ...].
     */
    public function listDatasets(): array
    {
        $response = $this->http()->get('/api/v1/datasets');

        return $response->json('data', []);
    }

    /**
     * Check if RAGFlow API is reachable.
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->http()->timeout(5)->get('/api/v1/health');
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->timeout($this->timeout)
            ->acceptJson();
    }

    private function assertSuccess(\Illuminate\Http\Client\Response $response, string $operation): void
    {
        if ($response->failed()) {
            throw new \RuntimeException(
                "RAGFlow {$operation} failed [{$response->status()}]: " . $response->body()
            );
        }
    }
}
