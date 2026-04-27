<?php

namespace App\Services\AI;

use App\Models\CustomAiPlatform;
use Illuminate\Support\Facades\Log;

/**
 * Swarm mode: fan-out to multiple AI providers, then synthesize a consensus answer.
 *
 * Flow:
 *   1. Fan-out: call every active provider with the same prompt (collects all responses)
 *   2. Judge call: synthesize best elements → JSON {"confidence": 0.0-1.0, "response": "..."}
 *   3. Repeat up to AGENT_SWARM_MAX_ROUNDS if confidence < 0.85
 *   4. Return synthesized response in standard choices[0].message.content shape
 *
 * Enabled when AGENT_SWARM_ENABLED=true AND 2+ active providers are available.
 */
class SwarmOrchestratorService
{
    public function __construct(
        private readonly OpenAIService    $openai,
        private readonly AnthropicService $anthropic,
    ) {}

    public function isEnabled(): bool
    {
        if (! config('agents.swarm.enabled', false)) {
            return false;
        }

        return count($this->getActiveProviders()) >= 2;
    }

    /**
     * Run the swarm: fan-out + consensus synthesis.
     * Returns a standard chat response array (choices[0].message.content).
     * When $tools are provided, returns the first tool-calling response directly
     * (consensus synthesis only applies to text-only responses).
     */
    public function run(
        array   $messages,
        ?string $system    = null,
        array   $tools     = [],
        ?string $agentRunId = null,
    ): array {
        $providers = $this->getActiveProviders();
        $responses = $this->fanOut($messages, $system, $providers, $tools);

        // If any provider returned a tool-use response, return it directly —
        // tool calls cannot be meaningfully synthesised across providers.
        if (! empty($tools)) {
            foreach ($responses as $providerKey => $resp) {
                if (is_array($resp) && $this->hasToolCalls($resp)) {
                    Log::debug('Swarm: returning tool-calling response directly', ['provider' => $providerKey]);
                    return $resp;
                }
            }
            // No provider returned tool calls — extract text and fall through to synthesis
            $responses = array_map(
                fn($r) => is_array($r) ? ($r['choices'][0]['message']['content'] ?? '') : (string) $r,
                $responses
            );
        }

        if (empty($responses)) {
            throw new \RuntimeException('Swarm: all providers failed — no responses collected');
        }

        // With only one response, return it directly
        if (count($responses) === 1) {
            return $this->wrapInChoicesShape(array_values($responses)[0]);
        }

        $maxRounds  = (int) config('agents.swarm.max_rounds', 3);
        $finalText  = '';
        $confidence = 0.0;

        for ($round = 1; $round <= $maxRounds; $round++) {
            [$finalText, $confidence] = $this->synthesize($responses, $system, $round);

            Log::info('Swarm synthesis round', [
                'round'      => $round,
                'confidence' => $confidence,
                'agent_run'  => $agentRunId,
            ]);

            if ($confidence >= 0.85) {
                break;
            }

            // Feed synthesized result back for next round
            if ($round < $maxRounds) {
                $responses['swarm_synthesis'] = $finalText;
            }
        }

        return $this->wrapInChoicesShape($finalText, [
            'swarm_rounds'     => $maxRounds,
            'swarm_confidence' => $confidence,
            'swarm_providers'  => array_keys($responses),
        ]);
    }

    /**
     * Call every active provider with the same messages. Returns provider→response map.
     * When $tools are provided, raw response arrays are returned (not text strings)
     * so tool-call blocks are preserved for inspection in run().
     */
    private function fanOut(array $messages, ?string $system, array $providers, array $tools = []): array
    {
        $responses = [];

        foreach ($providers as $providerKey => $config) {
            try {
                $response = $this->callProvider($providerKey, $config, $messages, $system, $tools);
                if (! empty($tools)) {
                    // Preserve full response array so run() can check for tool calls
                    $responses[$providerKey] = $response;
                } else {
                    $text = is_string($response) ? $response : ($response['choices'][0]['message']['content'] ?? '');
                    if (! empty(trim($text))) {
                        $responses[$providerKey] = $text;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Swarm fan-out: provider failed', [
                    'provider' => $providerKey,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $responses;
    }

    /**
     * Check if a response array contains tool calls (OpenAI or Anthropic format).
     */
    private function hasToolCalls(array $response): bool
    {
        // OpenAI format
        $toolCalls = $response['choices'][0]['message']['tool_calls'] ?? [];
        if (! empty($toolCalls)) return true;

        // Anthropic format
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use') return true;
        }

        return false;
    }

    /**
     * Call a single provider, returning either text or full response array.
     */
    private function callProvider(string $key, array $config, array $messages, ?string $system, array $tools = []): mixed
    {
        $model    = $config['model'];
        $provider = $config['type'];

        if ($provider === 'anthropic') {
            $anthropicTools = ! empty($tools) ? $this->convertToolsForAnthropic($tools) : [];
            $result = $this->anthropic->chat($messages, $model, $system ?? '', $anthropicTools, 4096, 0.7);
            return empty($tools)
                ? ($result['choices'][0]['message']['content'] ?? '')
                : $result;
        }

        if ($provider === 'openai') {
            $result = $this->openai->chat($messages, $model, $system ?? '', $tools, 4096, 0.7);
            return empty($tools)
                ? ($result['choices'][0]['message']['content'] ?? '')
                : $result;
        }

        // Custom platform via HTTP
        if ($provider === 'custom') {
            $platform = $config['platform'] ?? null;
            if (! $platform) {
                return '';
            }
            $apiKey = env($platform->api_key_env, '');
            $http   = \Illuminate\Support\Facades\Http::timeout(60);
            if ($platform->auth_type === 'bearer') {
                $http = $http->withToken($apiKey);
            } else {
                $http = $http->withHeaders([$platform->auth_header ?: 'X-API-Key' => $apiKey]);
            }

            $body = [
                'model'       => $model,
                'messages'    => $system ? array_merge([['role' => 'system', 'content' => $system]], $messages) : $messages,
                'max_tokens'  => 4096,
                'temperature' => 0.7,
            ];

            $resp = $http->post(rtrim($platform->api_base_url, '/') . '/chat/completions', $body);
            return $resp->json('choices.0.message.content', '');
        }

        return '';
    }

    /**
     * Synthesize a consensus from multiple responses using the judge LLM.
     * Returns [finalText, confidence].
     */
    private function synthesize(array $responses, ?string $system, int $round): array
    {
        $numbered = collect($responses)->map(fn ($text, $key) =>
            "[{$key}]: " . mb_substr($text, 0, 1500)
        )->join("\n\n---\n\n");

        $systemContext = $system ? "\nOriginal system prompt: {$system}\n" : '';

        $prompt = <<<PROMPT
You are a synthesis judge. {$systemContext}

You have received {$round} round of AI responses to the same query. Your task is to:
1. Identify the best elements from each response
2. Synthesize them into a single, coherent, complete answer
3. Estimate your confidence that the synthesized answer is correct and complete (0.0 to 1.0)

Responses to synthesize:
{$numbered}

Respond ONLY with a JSON object:
{"confidence": 0.0-1.0, "response": "your synthesized answer here"}

Do not include markdown code blocks. Output valid JSON only.
PROMPT;

        $judgeModel    = config('agents.swarm.judge_model', 'gpt-4o-mini');
        $judgeProvider = config('agents.swarm.judge_provider', 'openai');

        try {
            if ($judgeProvider === 'anthropic') {
                $text = $this->anthropic->complete($prompt, $judgeModel, 2000, 0.2);
            } else {
                $text = $this->openai->complete($prompt, $judgeModel, 2000, 0.2, jsonMode: true);
            }

            $decoded = json_decode(trim($text), true);
            if (is_array($decoded) && isset($decoded['response'])) {
                return [$decoded['response'], (float)($decoded['confidence'] ?? 0.7)];
            }
        } catch (\Throwable $e) {
            Log::warning('Swarm synthesis judge failed', ['error' => $e->getMessage()]);
        }

        // Fallback: return the longest response
        $longest = collect($responses)->sortByDesc(fn ($t) => mb_strlen($t))->first();
        return [$longest, 0.5];
    }

    /**
     * Convert OpenAI-format tool definitions to Anthropic's input_schema format.
     */
    private function convertToolsForAnthropic(array $openAiTools): array
    {
        return array_map(fn ($tool) => [
            'name'         => $tool['function']['name'],
            'description'  => $tool['function']['description'],
            'input_schema' => $tool['function']['parameters'],
        ], array_filter($openAiTools, fn ($t) => isset($t['function'])));
    }

    /**
     * Return list of active providers as [key => ['type', 'model', 'platform?']].
     */
    public function getActiveProviders(): array
    {
        $providers = [];

        if (! empty(config('agents.openai.api_key'))) {
            $providers['openai'] = [
                'type'  => 'openai',
                'model' => config('agents.openai.default_model', 'gpt-4o'),
            ];
        }

        if (! empty(config('agents.anthropic.api_key'))) {
            $providers['anthropic'] = [
                'type'  => 'anthropic',
                'model' => config('agents.anthropic.default_model', 'claude-opus-4-5'),
            ];
        }

        // Active custom platforms
        $customs = CustomAiPlatform::where('is_active', true)->get();
        foreach ($customs as $platform) {
            $apiKey = env($platform->api_key_env, '');
            if (! empty($apiKey)) {
                $providers['custom:' . $platform->name] = [
                    'type'     => 'custom',
                    'model'    => $platform->default_model,
                    'platform' => $platform,
                ];
            }
        }

        return $providers;
    }

    /**
     * Wrap a text string in the standard choices[0].message.content response shape.
     */
    private function wrapInChoicesShape(string $content, array $extra = []): array
    {
        return array_merge([
            'choices' => [[
                'message' => [
                    'role'    => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
        ], $extra);
    }
}
