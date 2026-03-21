<?php

namespace App\Services\AI;

use App\Services\ApiCredentialService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private string $baseUrl      = 'https://api.openai.com/v1';
    private int    $retryAttempts;
    private int    $retryDelayMs;

    public function __construct(private readonly ApiCredentialService $credentials)
    {
        $this->retryAttempts = config('agents.openai.retry_attempts', 3);
        $this->retryDelayMs  = config('agents.openai.retry_delay_ms', 1000);
    }

    /**
     * Resolve the API key at call-time: DB-stored credential takes priority over .env.
     */
    private function apiKey(): string
    {
        $key = $this->credentials->retrieve('OPENAI_API_KEY')
            ?? config('agents.openai.api_key')
            ?? '';

        if (empty($key) || str_contains($key, 'CHANGE_ME')) {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        return $key;
    }

    /**
     * Chat completion with optional tool/function calling.
     */
    public function chat(
        array   $messages,
        string  $model        = 'gpt-4o',
        string  $systemPrompt = '',
        array   $tools        = [],
        int     $maxTokens    = 4096,
        float   $temperature  = 0.7,
    ): array {
        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ];

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
        $model      = config('agents.openai.embedding_model', 'text-embedding-3-large');
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
        $response = Http::withToken($this->apiKey())
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

    /**
     * List available models (used by test-connection endpoint).
     */
    public function listModels(): array
    {
        return $this->request('models', []);
    }

    // ─── HTTP ─────────────────────────────────────────────────────

    private function request(string $endpoint, array $payload): array
    {
        $apiKey  = $this->apiKey();
        $attempt = 0;

        // GET requests (e.g. list models)
        if (empty($payload)) {
            $response = Http::withToken($apiKey)
                ->timeout(config('agents.openai.timeout', 60))
                ->get("{$this->baseUrl}/{$endpoint}");

            if ($response->successful()) {
                return $response->json();
            }

            throw new \RuntimeException("OpenAI API error {$response->status()}");
        }

        while ($attempt < $this->retryAttempts) {
            $attempt++;

            try {
                $response = Http::withToken($apiKey)
                    ->timeout(config('agents.openai.timeout', 60))
                    ->withHeaders(['OpenAI-Organization' => config('agents.openai.organization', '')])
                    ->post("{$this->baseUrl}/{$endpoint}", $payload);

                if ($response->successful()) {
                    return $response->json();
                }

                $status = $response->status();
                $body   = $response->json();

                if ($status === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? 10);
                    Log::warning("OpenAI rate limit hit, retrying after {$retryAfter}s");
                    sleep($retryAfter);
                    continue;
                }

                if ($status >= 500 && $attempt < $this->retryAttempts) {
                    $delay = $this->retryDelayMs * pow(2, $attempt - 1);
                    usleep((int) ($delay * 1000));
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
