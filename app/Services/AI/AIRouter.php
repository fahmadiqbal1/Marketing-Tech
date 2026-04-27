<?php

namespace App\Services\AI;

use App\Models\AiRequest;
use App\Models\CustomAiPlatform;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIRouter
{
    private array $fallbackChain = [
        'gpt-4o'          => ['gpt-4o-mini', 'claude-haiku-4-5-20251001'],
        'claude-opus-4-5' => ['claude-sonnet-4-6', 'gpt-4o'],
        'gpt-4-turbo'     => ['gpt-4o', 'claude-opus-4-5'],
    ];

    public function __construct(
        private readonly OpenAIService       $openai,
        private readonly AnthropicService    $anthropic,
        private readonly CostCalculatorService $costCalc,
    ) {}

    /**
     * Route a completion request to the best provider with cost tracking and fallback.
     */
    public function complete(
        string  $prompt,
        ?string $model       = null,
        int     $maxTokens   = 2048,
        float   $temperature = 0.7,
        ?string $system      = null,
        string  $provider    = 'auto',
    ): string {
        $resolvedModel    = $model    ?? $this->selectModel($prompt, $provider);
        $resolvedProvider = $provider === 'auto' ? $this->providerForModel($resolvedModel) : $provider;

        return $this->executeWithFallback(
            fn ($m, $p) => $this->callProvider($p, $m, [
                ['role' => 'user', 'content' => $prompt],
            ], $system, $maxTokens, $temperature),
            $resolvedModel,
            $resolvedProvider,
        );
    }

    /**
     * Route a chat completion with messages array.
     */
    public function chat(
        array   $messages,
        ?string $model       = null,
        int     $maxTokens   = 4096,
        float   $temperature = 0.7,
        ?string $system      = null,
        array   $tools       = [],
        string  $provider    = 'auto',
        ?string $agentRunId  = null,
        ?string $workflowId  = null,
        bool    $jsonMode    = false,
        ?array  $jsonSchema  = null,
    ): array {
        $resolvedModel    = $model    ?? $this->selectModel('', $provider);
        $resolvedProvider = $provider === 'auto' ? $this->providerForModel($resolvedModel) : $provider;

        $startedAt = microtime(true);

        try {
            $response = $this->executeWithFallback(
                fn ($m, $p) => $this->callProviderChat($p, $m, $messages, $system, $maxTokens, $temperature, $tools, $jsonMode, $jsonSchema),
                $resolvedModel,
                $resolvedProvider,
                $agentRunId,
                $workflowId,
            );

            $duration = (int) ((microtime(true) - $startedAt) * 1000);

            $this->logRequest(
                provider:   $resolvedProvider,
                model:      $resolvedModel,
                type:       empty($tools) ? 'chat' : 'chat_tools',
                tokensIn:   $this->extractTokensIn($response),
                tokensOut:  $this->extractTokensOut($response),
                durationMs: $duration,
                status:     'success',
                agentRunId: $agentRunId,
                workflowId: $workflowId,
            );

            return $response;

        } catch (\Throwable $e) {
            $this->logRequest(
                provider:   $resolvedProvider,
                model:      $resolvedModel,
                type:       'chat',
                tokensIn:   0,
                tokensOut:  0,
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                status:     'failed',
                error:      $e->getMessage(),
                agentRunId: $agentRunId,
                workflowId: $workflowId,
            );
            throw $e;
        }
    }

    /**
     * Generate embeddings.
     */
    public function embed(string|array $text): array
    {
        $startedAt = microtime(true);
        $model     = config('agents.openai.embedding_model', 'text-embedding-3-large');

        try {
            $result = $this->openai->embed($text);

            $this->logRequest(
                provider:   'openai',
                model:      $model,
                type:       'embedding',
                tokensIn:   is_array($text) ? count($text) * 50 : str_word_count($text) * 2,
                tokensOut:  0,
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                status:     'success',
            );

            return $result;
        } catch (\Throwable $e) {
            $this->logRequest('openai', $model, 'embedding', 0, 0,
                (int) ((microtime(true) - $startedAt) * 1000), 'failed', $e->getMessage());
            throw $e;
        }
    }

    public function getWorkflowCost(string $workflowId): float
    {
        return AiRequest::where('workflow_id', $workflowId)->sum('cost_usd');
    }

    public function getTodaySpend(): float
    {
        return AiRequest::whereDate('requested_at', today())->sum('cost_usd');
    }

    // ── Private implementation ────────────────────────────────────────

    private function executeWithFallback(
        callable $callFn,
        string   $model,
        string   $provider,
        ?string  $agentRunId = null,
        ?string  $workflowId = null,
    ): mixed {
        $attempts  = [$model];
        $fallbacks = $this->fallbackChain[$model] ?? [];

        foreach ($fallbacks as $fb) {
            if ($this->providerForModel($fb) !== $provider) {
                $attempts[] = $fb;
            }
        }
        foreach ($fallbacks as $fb) {
            if (! in_array($fb, $attempts)) {
                $attempts[] = $fb;
            }
        }

        $lastException = null;

        foreach ($attempts as $i => $attemptModel) {
            $attemptProvider = $this->providerForModel($attemptModel);

            try {
                if ($i > 0) {
                    Log::warning("AI Router fallback", ['from' => $model, 'to' => $attemptModel]);
                }
                return $callFn($attemptModel, $attemptProvider);
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("AI Router attempt failed", [
                    'model' => $attemptModel, 'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastException ?? new \RuntimeException("All AI Router attempts failed for model: {$model}");
    }

    private function callProvider(string $provider, string $model, array $messages, ?string $system, int $maxTokens, float $temperature): string
    {
        $custom = $this->resolveCustomPlatform($provider);
        if ($custom) {
            try {
                $response = $this->callCustomPlatform($custom, $model, $messages, $system, $maxTokens, $temperature);
                return $response['choices'][0]['message']['content'] ?? '';
            } catch (\Throwable $e) {
                Log::warning('AIRouter: custom platform fallback triggered', [
                    'platform'      => $custom->name,
                    'error'         => $e->getMessage(),
                    'fallback_to'   => 'openai/gpt-4o',
                ]);
                return $this->openai->complete($messages[0]['content'], 'gpt-4o', $maxTokens, $temperature);
            }
        }

        if ($provider === 'anthropic') {
            return $this->anthropic->complete($messages[0]['content'], $model, $maxTokens, $temperature);
        }
        return $this->openai->complete($messages[0]['content'], $model, $maxTokens, $temperature);
    }

    private function callProviderChat(string $provider, string $model, array $messages, ?string $system, int $maxTokens, float $temperature, array $tools, bool $jsonMode = false, ?array $jsonSchema = null): array
    {
        $custom = $this->resolveCustomPlatform($provider);
        if ($custom) {
            try {
                return $this->callCustomPlatform($custom, $model, $messages, $system, $maxTokens, $temperature);
            } catch (\Throwable $e) {
                Log::warning('AIRouter: custom platform chat fallback triggered', [
                    'platform'    => $custom->name,
                    'error'       => $e->getMessage(),
                    'fallback_to' => 'openai/gpt-4o',
                ]);
                return $this->openai->chat($messages, 'gpt-4o', $system ?? '', $tools, $maxTokens, $temperature, $jsonMode);
            }
        }

        if ($provider === 'anthropic') {
            $anthropicTools = $this->convertToolsForAnthropic($tools);
            return $this->anthropic->chat($messages, $model, $system ?? '', $anthropicTools, $maxTokens, $temperature, $jsonSchema);
        }
        return $this->openai->chat($messages, $model, $system ?? '', $tools, $maxTokens, $temperature, $jsonMode);
    }

    /**
     * Convert OpenAI-format tool definitions to Anthropic's input_schema format.
     */
    public function convertToolsForAnthropic(array $openAiTools): array
    {
        return array_map(fn ($tool) => [
            'name'         => $tool['function']['name'],
            'description'  => $tool['function']['description'],
            'input_schema' => $tool['function']['parameters'],
        ], array_filter($openAiTools, fn ($t) => isset($t['function'])));
    }

    private function providerForModel(string $model): string
    {
        if (str_starts_with($model, 'claude')) {
            return 'anthropic';
        }

        // Check if this model belongs to a custom platform (cached 5 min)
        $customPlatform = Cache::remember('custom_platform_for_model_' . md5($model), 300, function () use ($model) {
            return CustomAiPlatform::where('default_model', $model)->where('is_active', true)->first();
        });

        if ($customPlatform) {
            return 'custom:' . $customPlatform->name;
        }

        return 'openai';
    }

    private function resolveCustomPlatform(string $provider): ?CustomAiPlatform
    {
        if (! str_starts_with($provider, 'custom:')) {
            return null;
        }

        $name = substr($provider, 7);
        return Cache::remember('custom_platform_' . $name, 300, function () use ($name) {
            return CustomAiPlatform::where('name', $name)->where('is_active', true)->first();
        });
    }

    private function callCustomPlatform(CustomAiPlatform $platform, string $model, array $messages, ?string $system, int $maxTokens, float $temperature): array
    {
        // Circuit breaker: skip provider if it has failed 5+ consecutive times in the last 5 min
        $cbKey = "provider_failures_{$platform->name}";
        $failures = (int) Cache::get($cbKey, 0);
        if ($failures >= 5) {
            Log::warning('AIRouter: circuit breaker open — custom platform skipped', [
                'platform'  => $platform->name,
                'failures'  => $failures,
            ]);
            throw new \RuntimeException("Provider {$platform->name} temporarily disabled (circuit breaker open after {$failures} failures)");
        }

        $apiKey = config($platform->api_key_env) ?? env($platform->api_key_env, '');

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($platform->auth_type === 'x-api-key') {
            $authHeader = $platform->auth_header ?: 'X-API-Key';
            $headers[$authHeader] = $apiKey;
        } else {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        $body = ['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature];
        if ($system) {
            array_unshift($body['messages'], ['role' => 'system', 'content' => $system]);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->retry(1, 0)
                ->post(rtrim($platform->api_base_url, '/') . '/chat/completions', $body);

            if ($response->failed()) {
                throw new \RuntimeException("Custom platform {$platform->name} responded HTTP {$response->status()}: " . ($response->json('error.message') ?? 'Unknown error'));
            }

            $data = $response->json();
            if (empty($data['choices'][0]['message']['content'])) {
                throw new \RuntimeException("Custom platform {$platform->name} returned invalid response shape");
            }

            // Success: reset circuit breaker
            Cache::forget($cbKey);
            return $data;

        } catch (\Throwable $e) {
            // Increment failure counter; expire in 5 minutes (window resets after recovery)
            $newCount = $failures + 1;
            Cache::put($cbKey, $newCount, 300);

            if ($newCount >= 5) {
                Log::error('AIRouter: circuit breaker OPENED for custom platform', [
                    'platform' => $platform->name,
                    'failures' => $newCount,
                    'error'    => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    private function selectModel(string $prompt, string $preference): string
    {
        if ($preference === 'anthropic') {
            return config('agents.anthropic.default_model', 'claude-sonnet-4-6');
        }
        if ($preference === 'openai') {
            return config('agents.openai.default_model', 'gpt-4o');
        }
        return strlen($prompt) < 500
            ? 'gpt-4o-mini'
            : config('agents.openai.default_model', 'gpt-4o');
    }

    private function logRequest(
        string  $provider,
        string  $model,
        string  $type,
        int     $tokensIn,
        int     $tokensOut,
        int     $durationMs,
        string  $status,
        ?string $error       = null,
        ?string $agentRunId  = null,
        ?string $workflowId  = null,
    ): void {
        try {
            AiRequest::create([
                'agent_run_id'     => $agentRunId,
                'workflow_id'      => $workflowId,
                'provider'         => $provider,
                'model'            => $model,
                'request_type'     => $type,
                'tokens_in'        => $tokensIn,
                'tokens_out'       => $tokensOut,
                'cost_usd'         => $this->costCalc->calculate($provider, $model, $tokensIn, $tokensOut),
                'duration_ms'      => $durationMs,
                'status'           => $status,
                'error_message'    => $error,
                'used_fallback'    => false,
                'fallback_model'   => null,
                'request_metadata' => [],
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to log AI request", ['error' => $e->getMessage()]);
        }
    }

    private function extractTokensIn(array $response): int
    {
        return $response['usage']['prompt_tokens']
            ?? $response['usage']['input_tokens']
            ?? 0;
    }

    private function extractTokensOut(array $response): int
    {
        return $response['usage']['completion_tokens']
            ?? $response['usage']['output_tokens']
            ?? 0;
    }
}
