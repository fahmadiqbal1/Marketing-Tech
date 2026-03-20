<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class OpenAIService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.openai.com/v1';
    private int    $retryAttempts;
    private int    $retryDelayMs;

    public function __construct()
    {
        $this->apiKey       = config('agents.openai.api_key');
        $this->retryAttempts = config('agents.openai.retry_attempts', 3);
        $this->retryDelayMs  = config('agents.openai.retry_delay_ms', 1000);

        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }
    }

    /**
     * Chat completion with optional tool/function calling.
     */
    public function chat(
        array   $messages,
        string  $model       = 'gpt-4o',
        string  $systemPrompt = '',
        array   $tools       = [],
        int     $maxTokens   = 4096,
        float   $temperature = 0.7,
    ): array {
        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ];

        // Prepend system prompt as system message
        if (! empty($systemPrompt)) {
            array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
        }

        $payload['messages'] = $messages;

        if (! empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $this->request('chat/completions', $payload);
    }

    /**
     * Simple text completion — convenience wrapper.
     */
    public function complete(
        string $prompt,
        string $model       = 'gpt-4o-mini',
        int    $maxTokens   = 1024,
        float  $temperature = 0.7,
    ): string {
        $response = $this->chat(
            messages:    [['role' => 'user', 'content' => $prompt]],
            model:       $model,
            maxTokens:   $maxTokens,
            temperature: $temperature,
        );

        return $response['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Generate text embeddings for semantic search.
     */
    public function embed(string|array $input): array
    {
        $model     = config('agents.openai.embedding_model', 'text-embedding-3-large');
        $dimensions = config('agents.openai.embedding_dim', 3072);

        $texts = is_array($input) ? $input : [$input];

        $response = $this->request('embeddings', [
            'model'      => $model,
            'input'      => $texts,
            'dimensions' => $dimensions,
        ]);

        $embeddings = array_map(
            fn($d) => $d['embedding'],
            $response['data'] ?? []
        );

        return is_array($input) ? $embeddings : ($embeddings[0] ?? []);
    }

    /**
     * Transcribe audio file using Whisper.
     */
    public function transcribe(string $filePath, string $language = 'en'): string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post("{$this->baseUrl}/audio/transcriptions", [
                'model'    => 'whisper-1',
                'language' => $language,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Whisper transcription failed: " . $response->body());
        }

        return $response->json('text', '');
    }

    /**
     * Analyse an image with GPT-4 Vision.
     */
    public function analyseImage(string $imageBase64, string $prompt, string $mimeType = 'image/jpeg'): string
    {
        $response = $this->chat(
            messages: [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url'    => "data:{$mimeType};base64,{$imageBase64}",
                                'detail' => 'high',
                            ],
                        ],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ],
            ],
            model:     'gpt-4o',
            maxTokens: 2048,
        );

        return $response['choices'][0]['message']['content'] ?? '';
    }

    // ─── HTTP ─────────────────────────────────────────────────────

    private function request(string $endpoint, array $payload): array
    {
        $attempt = 0;

        while ($attempt < $this->retryAttempts) {
            $attempt++;

            try {
                $response = Http::withToken($this->apiKey)
                    ->timeout(config('agents.openai.timeout', 60))
                    ->withHeaders(['OpenAI-Organization' => config('agents.openai.organization', '')])
                    ->post("{$this->baseUrl}/{$endpoint}", $payload);

                if ($response->successful()) {
                    return $response->json();
                }

                $status = $response->status();
                $body   = $response->json();

                // Rate limit — wait and retry
                if ($status === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? 10);
                    Log::warning("OpenAI rate limit hit, retrying after {$retryAfter}s");
                    sleep($retryAfter);
                    continue;
                }

                // Server error — retry with backoff
                if ($status >= 500 && $attempt < $this->retryAttempts) {
                    $delay = $this->retryDelayMs * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                    continue;
                }

                $errorMessage = $body['error']['message'] ?? "OpenAI API error {$status}";
                throw new \RuntimeException($errorMessage, $status);

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($attempt < $this->retryAttempts) {
                    usleep($this->retryDelayMs * 1000 * $attempt);
                    continue;
                }
                throw new \RuntimeException("OpenAI connection failed: " . $e->getMessage());
            }
        }

        throw new \RuntimeException("OpenAI request failed after {$this->retryAttempts} attempts");
    }
}
