<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.anthropic.com/v1';
    private int    $retryAttempts;
    private int    $retryDelayMs;

    public function __construct()
    {
        $this->apiKey        = config('agents.anthropic.api_key');
        $this->retryAttempts = config('agents.anthropic.retry_attempts', 3);
        $this->retryDelayMs  = config('agents.anthropic.retry_delay_ms', 1500);

        if (empty($this->apiKey)) {
            throw new \RuntimeException('Anthropic API key is not configured.');
        }
    }

    /**
     * Chat completion with optional tool use.
     */
    public function chat(
        array  $messages,
        string $model        = 'claude-opus-4-5',
        string $systemPrompt = '',
        array  $tools        = [],
        int    $maxTokens    = 8192,
        float  $temperature  = 0.7,
    ): array {
        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'messages'    => $messages,
        ];

        if (! empty($systemPrompt)) {
            $payload['system'] = $systemPrompt;
        }

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        return $this->request('messages', $payload);
    }

    /**
     * Simple text completion — convenience wrapper.
     */
    public function complete(
        string $prompt,
        string $model      = 'claude-haiku-4-5-20251001',
        int    $maxTokens  = 1024,
        float  $temperature = 0.7,
    ): string {
        $response = $this->chat(
            messages:    [['role' => 'user', 'content' => $prompt]],
            model:       $model,
            maxTokens:   $maxTokens,
            temperature: $temperature,
        );

        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                return $block['text'];
            }
        }

        return '';
    }

    /**
     * Count tokens for a given text (uses /tokens endpoint).
     */
    public function countTokens(string $text, string $model = 'claude-haiku-4-5-20251001'): int
    {
        try {
            $response = $this->request('messages/count_tokens', [
                'model'    => $model,
                'messages' => [['role' => 'user', 'content' => $text]],
            ]);
            return $response['input_tokens'] ?? 0;
        } catch (\Throwable) {
            // Rough estimate: ~4 chars per token
            return (int) (strlen($text) / 4);
        }
    }

    // ─── HTTP ─────────────────────────────────────────────────────

    private function request(string $endpoint, array $payload): array
    {
        $attempt = 0;

        while ($attempt < $this->retryAttempts) {
            $attempt++;

            try {
                $response = Http::withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                    ->timeout(config('agents.anthropic.timeout', 90))
                    ->post("{$this->baseUrl}/{$endpoint}", $payload);

                if ($response->successful()) {
                    return $response->json();
                }

                $status = $response->status();
                $body   = $response->json();

                if ($status === 529 || $status === 429) {
                    $retryAfter = (int) ($response->header('retry-after') ?? 15);
                    Log::warning("Anthropic rate limit / overload, retrying after {$retryAfter}s");
                    sleep($retryAfter);
                    continue;
                }

                if ($status >= 500 && $attempt < $this->retryAttempts) {
                    usleep($this->retryDelayMs * 1000 * $attempt);
                    continue;
                }

                $errorMessage = $body['error']['message'] ?? "Anthropic API error {$status}";
                throw new \RuntimeException($errorMessage, $status);

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($attempt < $this->retryAttempts) {
                    usleep($this->retryDelayMs * 1000 * $attempt);
                    continue;
                }
                throw new \RuntimeException("Anthropic connection failed: " . $e->getMessage());
            }
        }

        throw new \RuntimeException("Anthropic request failed after {$this->retryAttempts} attempts");
    }
}
