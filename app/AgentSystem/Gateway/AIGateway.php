<?php

namespace App\AgentSystem\Gateway;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unified AI Gateway supporting OpenAI and Google Gemini.
 * Handles retries, JSON enforcement, latency and token logging.
 */
class AIGateway
{
    private const MAX_RETRIES    = 3;
    private const RETRY_DELAYS   = [1, 2, 4]; // seconds (exponential backoff)
    private const REQUEST_TIMEOUT = 90;        // seconds

    private string $provider;
    private string $apiKey;
    private string $model;

    public function __construct(string $provider = 'openai', ?string $apiKey = null, ?string $model = null)
    {
        $this->provider = strtolower($provider);
        $this->apiKey   = $apiKey ?? $this->resolveApiKey();
        $this->model    = $model  ?? $this->resolveDefaultModel();
    }

    /**
     * Send a chat completion request and return structured response.
     *
     * @param  array  $messages  OpenAI-style message array: [['role'=>'user','content'=>'...']]
     * @param  array  $options   Extra options (temperature, max_tokens, etc.)
     * @return array  ['content'=>string, 'tokens'=>array, 'latency_ms'=>int, 'provider'=>string]
     * @throws \RuntimeException on all retries exhausted
     */
    public function complete(array $messages, array $options = []): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = self::RETRY_DELAYS[$attempt - 1] ?? 4;
                Log::warning("[AIGateway] Retry {$attempt} after {$delay}s", [
                    'provider' => $this->provider,
                    'model'    => $this->model,
                ]);
                sleep($delay);
            }

            try {
                $startMs = (int) round(microtime(true) * 1000);

                $result = match ($this->provider) {
                    'gemini' => $this->callGemini($messages, $options),
                    default  => $this->callOpenAI($messages, $options),
                };

                $result['latency_ms'] = (int) round(microtime(true) * 1000) - $startMs;
                $result['provider']   = $this->provider;

                Log::info('[AIGateway] Success', [
                    'provider'   => $this->provider,
                    'model'      => $this->model,
                    'tokens'     => $result['tokens'],
                    'latency_ms' => $result['latency_ms'],
                ]);

                return $result;

            } catch (\Throwable $e) {
                $lastException = $e;
                Log::error('[AIGateway] Request failed', [
                    'attempt'  => $attempt + 1,
                    'provider' => $this->provider,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            "[AIGateway] All {$this->provider} retries exhausted: " . $lastException?->getMessage(),
            0,
            $lastException
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Provider implementations
    // ─────────────────────────────────────────────────────────────────────

    private function callOpenAI(array $messages, array $options): array
    {
        $payload = array_merge([
            'model'           => $this->model,
            'messages'        => $messages,
            'temperature'     => $options['temperature'] ?? 0.7,
            'max_tokens'      => $options['max_tokens'] ?? 2048,
            'response_format' => ['type' => 'json_object'],  // enforce JSON output
        ], $options['extra'] ?? []);

        $response = Http::withToken($this->apiKey)
            ->timeout(self::REQUEST_TIMEOUT)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                'OpenAI HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        $body   = $response->json();
        $choice = $body['choices'][0] ?? null;

        if (! $choice) {
            throw new \RuntimeException('OpenAI returned no choices: ' . json_encode($body));
        }

        return [
            'content' => $choice['message']['content'] ?? '',
            'tokens'  => [
                'prompt'     => $body['usage']['prompt_tokens'] ?? 0,
                'completion' => $body['usage']['completion_tokens'] ?? 0,
                'total'      => $body['usage']['total_tokens'] ?? 0,
            ],
            'raw' => $body,
        ];
    }

    private function callGemini(array $messages, array $options): array
    {
        // Convert OpenAI-style messages to Gemini format
        $geminiContents = [];
        $systemPrompt   = '';

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt = $msg['content'];
                continue;
            }
            $geminiContents[] = [
                'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $payload = [
            'contents'          => $geminiContents,
            'generationConfig'  => [
                'temperature'    => $options['temperature'] ?? 0.7,
                'maxOutputTokens'=> $options['max_tokens'] ?? 2048,
                'responseMimeType' => 'application/json',  // enforce JSON output
            ],
        ];

        if ($systemPrompt) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $response = Http::timeout(self::REQUEST_TIMEOUT)->post($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Gemini HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        $body      = $response->json();
        $candidate = $body['candidates'][0] ?? null;

        if (! $candidate) {
            throw new \RuntimeException('Gemini returned no candidates: ' . json_encode($body));
        }

        $text = $candidate['content']['parts'][0]['text'] ?? '';

        $usageMeta = $body['usageMetadata'] ?? [];

        return [
            'content' => $text,
            'tokens'  => [
                'prompt'     => $usageMeta['promptTokenCount'] ?? 0,
                'completion' => $usageMeta['candidatesTokenCount'] ?? 0,
                'total'      => $usageMeta['totalTokenCount'] ?? 0,
            ],
            'raw' => $body,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function resolveApiKey(): string
    {
        return match ($this->provider) {
            'gemini' => config('agent_system.gemini_api_key', env('GEMINI_API_KEY', '')),
            default  => config('agent_system.openai_api_key', env('OPENAI_API_KEY', '')),
        };
    }

    private function resolveDefaultModel(): string
    {
        return match ($this->provider) {
            'gemini' => config('agent_system.gemini_model', 'gemini-1.5-flash'),
            default  => config('agent_system.openai_model', 'gpt-4o-mini'),
        };
    }

    /**
     * Parse the AI response content into an associative array.
     * Strips markdown code fences if present.
     */
    public function parseJson(string $content): array
    {
        // Strip markdown code fences (```json ... ```)
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/^```\s*$/m', '', $content);
        $content = trim($content);

        // Extract first JSON object or array if wrapped in extra text
        if (! str_starts_with($content, '{') && ! str_starts_with($content, '[')) {
            if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/m', $content, $m)) {
                $content = $m[1];
            }
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Failed to parse AI JSON response: ' . json_last_error_msg() . "\nContent: " . substr($content, 0, 500)
            );
        }

        return $decoded;
    }

    public function getProvider(): string { return $this->provider; }
    public function getModel(): string    { return $this->model; }
}
