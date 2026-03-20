<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\Media\FFmpegService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OcrExtractSkill implements SkillInterface
{
    public function __construct(private readonly OCRService $ocr) {}

    public function getName(): string { return 'ocr_extract'; }
    public function getDescription(): string { return 'Extract text from images or PDFs using Tesseract OCR'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'storage_key' => ['type' => 'string'],
                'language'    => ['type' => 'string', 'description' => 'Language code e.g. eng, fra, deu'],
                'dpi'         => ['type' => 'integer', 'description' => 'DPI for PDF rendering (default 300)'],
            ],
            'required' => ['storage_key'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $content  = Storage::disk('minio')->get($params['storage_key']);
        $ext      = pathinfo($params['storage_key'], PATHINFO_EXTENSION) ?: 'jpg';
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.' . $ext);
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);

        try {
            $text = $this->ocr->extractText($tempPath, $params['language'] ?? 'eng');
            @unlink($tempPath);

            return [
                'text'       => $text,
                'char_count' => strlen($text),
                'word_count' => str_word_count($text),
                'language'   => $params['language'] ?? 'eng',
            ];
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }
    }
}
