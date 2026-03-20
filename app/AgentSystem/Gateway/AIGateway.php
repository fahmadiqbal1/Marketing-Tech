<?php

namespace App\AgentSystem\Gateway;

use App\Models\AiRequest;
use App\Services\AI\CostCalculatorService;
use App\Services\ApiCredentialService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unified AI Gateway supporting OpenAI and Google Gemini.
 * - Retries with exponential backoff
 * - JSON response enforcement
 * - Logs every call to ai_requests for dashboard cost visibility
 * - Reads API keys from ApiCredentialService (DB) with env() fallback
 */
class AIGateway
{
    private const MAX_RETRIES       = 3;
    private const RETRY_DELAYS      = [1, 2, 4]; // seconds
    private const REQUEST_TIMEOUT   = 90;         // seconds
    private const CIRCUIT_THRESHOLD = 5;          // failures before open
    private const CIRCUIT_TTL       = 120;        // seconds circuit stays open

    private string $provider;
    private string $apiKey;
    private string $model;

    /** Nullable: links ai_requests rows to an AgentTask */
    private ?int $agentTaskId = null;

    private ?CostCalculatorService $costCalc;
    private ?ApiCredentialService  $credentialService;

    public function __construct(
        string                   $provider          = 'openai',
        ?string                  $apiKey            = null,
        ?string                  $model             = null,
        ?CostCalculatorService   $costCalc          = null,
        ?ApiCredentialService    $credentialService = null,
    ) {
        $this->provider           = strtolower($provider);
        $this->costCalc           = $costCalc;
        $this->credentialService  = $credentialService;
        $this->apiKey             = $apiKey ?? $this->resolveApiKey();
        $this->model              = $model  ?? $this->resolveDefaultModel();
    }

    /**
     * Set the AgentTask ID so ai_requests rows can be linked for cost tracking.
     */
    public function setContext(int $agentTaskId): void
    {
        $this->agentTaskId = $agentTaskId;
    }

    /**
     * Send a chat completion request and return structured response.
     *
     * @param  array  $messages  OpenAI-style [['role'=>'user','content'=>'...']]
     * @return array  ['content'=>string, 'tokens'=>array, 'latency_ms'=>int, 'provider'=>string]
     * @throws \RuntimeException when all retries are exhausted
     */
    public function complete(array $messages, array $options = []): array
    {
        // ── Circuit breaker: fail-fast if provider is known-down ──────
        $circuitKey = 'aigw:circuit:' . $this->provider;
        if (Cache::get($circuitKey, 0) >= self::CIRCUIT_THRESHOLD) {
            throw new \RuntimeException(
                "[AIGateway] Circuit open for {$this->provider} — too many recent failures. Retry in " . self::CIRCUIT_TTL . "s."
            );
        }

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

                // Success — reset circuit failure counter
                Cache::forget('aigw:circuit:' . $this->provider);

                // Log to ai_requests for dashboard cost visibility
                $this->logRequest(
                    tokensIn:  $result['tokens']['prompt']     ?? 0,
                    tokensOut: $result['tokens']['completion'] ?? 0,
                    latencyMs: $result['latency_ms'],
                    status:    'success',
                );

                Log::info('[AIGateway] Success', [
                    'provider'   => $this->provider,
                    'model'      => $this->model,
                    'tokens'     => $result['tokens'],
                    'latency_ms' => $result['latency_ms'],
                ]);

                return $result;

            } catch (\Throwable $e) {
                $lastException = $e;
                // Increment circuit failure counter (expires after CIRCUIT_TTL seconds)
                Cache::put(
                    'aigw:circuit:' . $this->provider,
                    Cache::get('aigw:circuit:' . $this->provider, 0) + 1,
                    self::CIRCUIT_TTL
                );
                Log::error('[AIGateway] Request failed', [
                    'attempt'  => $attempt + 1,
                    'provider' => $this->provider,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // Log the final failure
        $this->logRequest(
            tokensIn:  0,
            tokensOut: 0,
            latencyMs: 0,
            status:    'failed',
            error:     $lastException?->getMessage(),
        );

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
            'max_tokens'      => $options['max_tokens']  ?? 2048,
            'response_format' => ['type' => 'json_object'],
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
                'prompt'     => $body['usage']['prompt_tokens']     ?? 0,
                'completion' => $body['usage']['completion_tokens'] ?? 0,
                'total'      => $body['usage']['total_tokens']      ?? 0,
            ],
            'raw' => $body,
        ];
    }

    private function callGemini(array $messages, array $options): array
    {
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
            'contents'         => $geminiContents,
            'generationConfig' => [
                'temperature'     => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['max_tokens']  ?? 2048,
                'responseMimeType'=> 'application/json',
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

        $usageMeta = $body['usageMetadata'] ?? [];

        return [
            'content' => $candidate['content']['parts'][0]['text'] ?? '',
            'tokens'  => [
                'prompt'     => $usageMeta['promptTokenCount']     ?? 0,
                'completion' => $usageMeta['candidatesTokenCount'] ?? 0,
                'total'      => $usageMeta['totalTokenCount']      ?? 0,
            ],
            'raw' => $body,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function resolveApiKey(): string
    {
        // 1. Try DB credential store (with 5-minute cache)
        if ($this->credentialService) {
            $envKey = $this->provider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY';
            $stored = $this->credentialService->retrieve($envKey);
            if (! empty($stored)) {
                return $stored;
            }
        }

        // 2. Fall back to config/env
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

    private function logRequest(int $tokensIn, int $tokensOut, int $latencyMs, string $status, ?string $error = null): void
    {
        try {
            AiRequest::create([
                'provider'         => $this->provider,
                'model'            => $this->model,
                'request_type'     => 'agent_chat',
                'tokens_in'        => $tokensIn,
                'tokens_out'       => $tokensOut,
                'cost_usd'         => $this->costCalc?->calculate($this->provider, $this->model, $tokensIn, $tokensOut) ?? 0,
                'duration_ms'      => $latencyMs,
                'status'           => $status,
                'error_message'    => $error,
                'used_fallback'    => false,
                'request_metadata' => $this->agentTaskId ? ['agent_task_id' => $this->agentTaskId] : [],
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — never let logging break the agent
            Log::warning('[AIGateway] Failed to log ai_request: ' . $e->getMessage());
        }
    }

    /**
     * Parse AI response content into an array, stripping markdown fences.
     */
    public function parseJson(string $content): array
    {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/^```\s*$/m', '', $content);
        $content = trim($content);

        if (! str_starts_with($content, '{') && ! str_starts_with($content, '[')) {
            if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/m', $content, $m)) {
                $content = $m[1];
            }
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Failed to parse AI JSON response: ' . json_last_error_msg()
                . "\nContent: " . substr($content, 0, 500)
            );
        }

        return $decoded;
    }

    public function getProvider(): string { return $this->provider; }
    public function getModel(): string    { return $this->model; }
}
