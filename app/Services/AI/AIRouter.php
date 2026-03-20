<?php

namespace App\Services\AI;

use App\Models\AiRequest;
use Illuminate\Support\Facades\Log;

class AIRouter
{
    private array $modelCosts = [
        // Per 1M tokens: [input_cost, output_cost]
        'gpt-4o'                => [2.50,  10.00],
        'gpt-4o-mini'           => [0.15,   0.60],
        'gpt-4-turbo'           => [10.00,  30.00],
        'o1'                    => [15.00,  60.00],
        'o1-mini'               => [3.00,   12.00],
        'text-embedding-3-large' => [0.13,  0.00],
        'text-embedding-3-small' => [0.02,  0.00],
        'claude-opus-4-5'       => [15.00,  75.00],
        'claude-sonnet-4-6'     => [3.00,   15.00],
        'claude-haiku-4-5-20251001' => [0.25, 1.25],
    ];

    private array $fallbackChain = [
        'gpt-4o'          => ['gpt-4o-mini', 'claude-haiku-4-5-20251001'],
        'claude-opus-4-5' => ['claude-sonnet-4-6', 'gpt-4o'],
        'gpt-4-turbo'     => ['gpt-4o', 'claude-opus-4-5'],
    ];

    public function __construct(
        private readonly OpenAIService    $openai,
        private readonly AnthropicService $anthropic,
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
            fn($m, $p) => $this->callProvider($p, $m, [
                ['role' => 'user', 'content' => $prompt]
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
    ): array {
        $resolvedModel    = $model    ?? $this->selectModel('', $provider);
        $resolvedProvider = $provider === 'auto' ? $this->providerForModel($resolvedModel) : $provider;

        $startedAt = microtime(true);

        try {
            $response = $this->executeWithFallback(
                fn($m, $p) => $this->callProviderChat($p, $m, $messages, $system, $maxTokens, $temperature, $tools),
                $resolvedModel,
                $resolvedProvider,
                $agentRunId,
                $workflowId,
            );

            $duration = (int) ((microtime(true) - $startedAt) * 1000);

            // Log successful request
            $this->logRequest(
                provider:    $resolvedProvider,
                model:       $resolvedModel,
                type:        empty($tools) ? 'chat' : 'chat_tools',
                tokensIn:    $this->extractTokensIn($response),
                tokensOut:   $this->extractTokensOut($response),
                durationMs:  $duration,
                status:      'success',
                agentRunId:  $agentRunId,
                workflowId:  $workflowId,
            );

            return $response;

        } catch (\Throwable $e) {
            $this->logRequest(
                provider:    $resolvedProvider,
                model:       $resolvedModel,
                type:        'chat',
                tokensIn:    0,
                tokensOut:   0,
                durationMs:  (int) ((microtime(true) - $startedAt) * 1000),
                status:      'failed',
                error:       $e->getMessage(),
                agentRunId:  $agentRunId,
                workflowId:  $workflowId,
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
                tokensIn:   is_array($text) ? count($text) * 50 : str_word_count($text) * 2, // estimate
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

    /**
     * Get aggregate cost for a workflow.
     */
    public function getWorkflowCost(string $workflowId): float
    {
        return AiRequest::where('workflow_id', $workflowId)->sum('cost_usd');
    }

    /**
     * Get today's total spend.
     */
    public function getTodaySpend(): float
    {
        return AiRequest::whereDate('requested_at', today())->sum('cost_usd');
    }

    // ── Private implementation ────────────────────────────────────

    private function executeWithFallback(
        callable $callFn,
        string   $model,
        string   $provider,
        ?string  $agentRunId = null,
        ?string  $workflowId = null,
    ): mixed {
        $attempts = [$model];
        $fallbacks = $this->fallbackChain[$model] ?? [];

        foreach ($fallbacks as $fb) {
            if ($this->providerForModel($fb) !== $provider) {
                $attempts[] = $fb;
            }
        }
        // Add cross-provider fallbacks last
        foreach ($fallbacks as $fb) {
            if (! in_array($fb, $attempts)) {
                $attempts[] = $fb;
            }
        }

        $lastException = null;

        foreach ($attempts as $i => $attemptModel) {
            $attemptProvider = $this->providerForModel($attemptModel);
            $isFallback      = $i > 0;

            try {
                if ($isFallback) {
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
        if ($provider === 'anthropic') {
            return $this->anthropic->complete($messages[0]['content'], $model, $maxTokens, $temperature);
        }
        return $this->openai->complete($messages[0]['content'], $model, $maxTokens, $temperature);
    }

    private function callProviderChat(string $provider, string $model, array $messages, ?string $system, int $maxTokens, float $temperature, array $tools): array
    {
        if ($provider === 'anthropic') {
            return $this->anthropic->chat($messages, $model, $system ?? '', $tools, $maxTokens, $temperature);
        }
        return $this->openai->chat($messages, $model, $system ?? '', $tools, $maxTokens, $temperature);
    }

    private function providerForModel(string $model): string
    {
        if (str_starts_with($model, 'claude')) {
            return 'anthropic';
        }
        return 'openai';
    }

    private function selectModel(string $prompt, string $preference): string
    {
        if ($preference === 'anthropic') {
            return config('agents.anthropic.default_model', 'claude-sonnet-4-6');
        }
        if ($preference === 'openai') {
            return config('agents.openai.default_model', 'gpt-4o');
        }
        // Auto: use cheaper model for short prompts
        if (strlen($prompt) < 500) {
            return 'gpt-4o-mini';
        }
        return config('agents.openai.default_model', 'gpt-4o');
    }

    private function calculateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        [$inRate, $outRate] = $this->modelCosts[$model] ?? [1.0, 3.0];
        return ($tokensIn * $inRate + $tokensOut * $outRate) / 1_000_000;
    }

    private function logRequest(
        string  $provider,
        string  $model,
        string  $type,
        int     $tokensIn,
        int     $tokensOut,
        int     $durationMs,
        string  $status,
        ?string $error      = null,
        ?string $agentRunId = null,
        ?string $workflowId = null,
        bool    $usedFallback = false,
        ?string $fallbackModel = null,
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
                'cost_usd'         => $this->calculateCost($model, $tokensIn, $tokensOut),
                'duration_ms'      => $durationMs,
                'status'           => $status,
                'error_message'    => $error,
                'used_fallback'    => $usedFallback,
                'fallback_model'   => $fallbackModel,
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
