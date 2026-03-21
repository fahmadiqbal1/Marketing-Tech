<?php

namespace App\Services\AI;

use App\Services\ApiCredentialService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gemini API service for the modern agent system.
 *
 * Accepts messages and tool definitions in OpenAI format and returns
 * a response in OpenAI format so BaseAgent requires no extra branches.
 */
class GeminiService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const TIMEOUT  = 90;

    public function __construct(
        private readonly ApiCredentialService $credentials,
    ) {}

    /**
     * Chat completion with optional tool/function calling.
     * Input/output mirrors OpenAIService::chat() so BaseAgent handles it uniformly.
     */
    public function chat(
        array  $messages,
        string $model        = 'gemini-2.0-flash',
        string $systemPrompt = '',
        array  $tools        = [],
        int    $maxTokens    = 4096,
        float  $temperature  = 0.7,
    ): array {
        $apiKey = $this->credentials->retrieve('GEMINI_API_KEY')
            ?? config('agents.gemini.api_key', env('GEMINI_API_KEY'));

        if (empty($apiKey)) {
            throw new \RuntimeException('Gemini API key is not configured.');
        }

        // Override model from DB credential if saved via settings
        $storedModel = $this->credentials->retrieve('GEMINI_DEFAULT_MODEL');
        if (! empty($storedModel)) {
            $model = $storedModel;
        }

        $payload = [
            'contents'         => $this->convertMessages($messages),
            'generationConfig' => [
                'temperature'     => $temperature,
                'maxOutputTokens' => $maxTokens,
            ],
        ];

        if (! empty($systemPrompt)) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        if (! empty($tools)) {
            $payload['tools'] = [['functionDeclarations' => $this->convertTools($tools)]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        $url = self::BASE_URL . "/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout(self::TIMEOUT)->post($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Gemini HTTP ' . $response->status() . ': ' . $response->body());
        }

        return $this->normalizeResponse($response->json());
    }

    // ─── Format Conversions ───────────────────────────────────────

    /**
     * Convert OpenAI-format messages array to Gemini contents array.
     */
    private function convertMessages(array $messages): array
    {
        $contents = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];

            // Skip system messages — handled via systemInstruction
            if ($role === 'system') {
                continue;
            }

            if ($role === 'tool') {
                // OpenAI tool results: role='tool', content=json_encode([{tool_call_id, name, content}])
                $results = json_decode($msg['content'] ?? '[]', true) ?? [];
                $parts   = [];
                foreach ($results as $r) {
                    $parts[] = [
                        'functionResponse' => [
                            'name'     => $r['name'] ?? 'unknown',
                            'response' => ['result' => $r['content'] ?? ''],
                        ],
                    ];
                }
                $contents[] = ['role' => 'user', 'parts' => $parts ?: [['text' => '']]];
                continue;
            }

            if ($role === 'assistant') {
                $toolCalls = $msg['tool_calls'] ?? [];
                if (! empty($toolCalls)) {
                    // Assistant message with tool calls
                    $parts = [];
                    foreach ($toolCalls as $tc) {
                        $args = is_string($tc['function']['arguments'] ?? '')
                            ? json_decode($tc['function']['arguments'], true) ?? []
                            : ($tc['function']['arguments'] ?? []);
                        $parts[] = [
                            'functionCall' => [
                                'name' => $tc['function']['name'],
                                'args' => $args,
                            ],
                        ];
                    }
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                    continue;
                }

                // Plain assistant text
                $contents[] = [
                    'role'  => 'model',
                    'parts' => [['text' => $msg['content'] ?? '']],
                ];
                continue;
            }

            // User message — content may be string or array (multimodal)
            $content = $msg['content'] ?? '';
            $contents[] = [
                'role'  => 'user',
                'parts' => [['text' => is_array($content) ? json_encode($content) : $content]],
            ];
        }

        return $contents;
    }

    /**
     * Convert OpenAI tool definitions to Gemini functionDeclarations.
     */
    private function convertTools(array $tools): array
    {
        $decls = [];
        foreach ($tools as $tool) {
            if (($tool['type'] ?? '') !== 'function') {
                continue;
            }
            $fn = $tool['function'];
            $decls[] = [
                'name'        => $fn['name'],
                'description' => $fn['description'] ?? '',
                'parameters'  => $this->convertSchema($fn['parameters'] ?? []),
            ];
        }
        return $decls;
    }

    /**
     * Recursively convert JSON schema types from lowercase (OpenAI) to uppercase (Gemini).
     */
    private function convertSchema(array $schema): array
    {
        if (isset($schema['type'])) {
            $schema['type'] = strtoupper($schema['type']);
        }
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $k => $v) {
                $schema['properties'][$k] = $this->convertSchema($v);
            }
        }
        if (isset($schema['items'])) {
            $schema['items'] = $this->convertSchema($schema['items']);
        }
        return $schema;
    }

    /**
     * Normalize Gemini response to OpenAI format so BaseAgent's default branch handles it.
     */
    private function normalizeResponse(array $body): array
    {
        $candidate = $body['candidates'][0] ?? null;

        if (! $candidate) {
            throw new \RuntimeException('Gemini returned no candidates: ' . json_encode($body));
        }

        $parts     = $candidate['content']['parts'] ?? [];
        $textParts = [];
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $toolCalls[] = [
                    'id'   => 'call_' . Str::random(8),
                    'type' => 'function',
                    'function' => [
                        'name'      => $part['functionCall']['name'],
                        'arguments' => json_encode($part['functionCall']['args'] ?? []),
                    ],
                ];
            } elseif (isset($part['text'])) {
                $textParts[] = $part['text'];
            }
        }

        $usageMeta = $body['usageMetadata'] ?? [];

        return [
            'choices' => [
                [
                    'message' => [
                        'role'       => 'assistant',
                        'content'    => $textParts ? implode('', $textParts) : null,
                        'tool_calls' => $toolCalls,
                    ],
                    'finish_reason' => $candidate['finishReason'] ?? 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens'     => $usageMeta['promptTokenCount']     ?? 0,
                'completion_tokens' => $usageMeta['candidatesTokenCount'] ?? 0,
                'total_tokens'      => $usageMeta['totalTokenCount']      ?? 0,
            ],
        ];
    }
}
