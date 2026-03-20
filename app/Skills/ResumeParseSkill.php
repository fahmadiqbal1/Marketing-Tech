<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\Media\FFmpegService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResumeParseSkill implements SkillInterface
{
    public function __construct(
        private readonly AIRouter   $aiRouter,
        private readonly OCRService $ocr,
    ) {}

    public function getName(): string { return 'resume_parse'; }
    public function getDescription(): string { return 'Parse a resume/CV from PDF or image into structured data'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'storage_key'  => ['type' => 'string', 'description' => 'PDF or image in MinIO'],
                'raw_text'     => ['type' => 'string', 'description' => 'Pre-extracted text (skip OCR)'],
            ],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        // Get text — either pre-supplied or via OCR
        if (! empty($params['raw_text'])) {
            $text = $params['raw_text'];
        } elseif (! empty($params['storage_key'])) {
            $content  = Storage::disk('minio')->get($params['storage_key']);
            $ext      = pathinfo($params['storage_key'], PATHINFO_EXTENSION) ?: 'pdf';
            $tempPath = storage_path('app/temp/' . Str::uuid() . '.' . $ext);
            @mkdir(dirname($tempPath), 0755, true);
            file_put_contents($tempPath, $content);
            $text = $this->ocr->extractText($tempPath);
            @unlink($tempPath);
        } else {
            throw new \InvalidArgumentException("Either storage_key or raw_text is required");
        }

        $prompt = <<<PROMPT
Parse this resume/CV. Return ONLY valid JSON with no commentary.

{$text}

Required JSON structure:
{
  "name": "string",
  "email": "string or null",
  "phone": "string or null",
  "location": "string or null",
  "linkedin_url": "string or null",
  "github_url": "string or null",
  "summary": "2-3 sentence professional summary",
  "years_experience": 0,
  "current_title": "string or null",
  "current_company": "string or null",
  "skills": ["skill1"],
  "languages": ["English"],
  "education": [{"degree":"","institution":"","year":0}],
  "experience": [{"title":"","company":"","from":"YYYY-MM","to":"YYYY-MM or present","summary":""}],
  "certifications": []
}
PROMPT;

        $raw    = $this->aiRouter->complete($prompt, 'claude-haiku-4-5-20251001', 2048, 0.0);
        $clean  = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $parsed = json_decode($clean, true);

        if (! $parsed) {
            throw new \RuntimeException("Failed to parse resume JSON from AI response");
        }

        return $parsed;
    }
}
