<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\Media\FFmpegService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LlmGenerateTextSkill implements SkillInterface
{
    public function __construct(private readonly AIRouter $aiRouter) {}

    public function getName(): string { return 'llm_generate_text'; }
    public function getDescription(): string { return 'Generate text using the AI router with provider selection'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'prompt'       => ['type' => 'string'],
                'system'       => ['type' => 'string', 'description' => 'System prompt'],
                'model'        => ['type' => 'string'],
                'provider'     => ['type' => 'string', 'enum' => ['openai', 'anthropic', 'auto']],
                'max_tokens'   => ['type' => 'integer'],
                'temperature'  => ['type' => 'number'],
                'json_output'  => ['type' => 'boolean', 'description' => 'Force JSON response'],
            ],
            'required' => ['prompt'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $text = $this->aiRouter->complete(
            prompt:      $params['prompt'],
            model:       $params['model']       ?? null,
            maxTokens:   $params['max_tokens']  ?? 2048,
            temperature: $params['temperature'] ?? 0.7,
            system:      $params['system']      ?? null,
            provider:    $params['provider']    ?? 'auto',
        );

        if ($params['json_output'] ?? false) {
            $parsed = json_decode($text, true);
            return ['text' => $text, 'parsed' => $parsed, 'valid_json' => $parsed !== null];
        }

        return [
            'text'       => $text,
            'word_count' => str_word_count($text),
            'char_count' => strlen($text),
        ];
    }
}
