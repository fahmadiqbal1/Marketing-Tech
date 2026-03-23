<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\Media\FFmpegService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// ─────────────────────────────────────────────────────────────────────────────
// 1. image_enhance
// ─────────────────────────────────────────────────────────────────────────────
class ImageEnhanceSkill implements SkillInterface
{
    public function __construct(private readonly ImageService $images) {}

    public function getName(): string { return 'image_enhance'; }
    public function getDescription(): string { return 'Sharpen, denoise, and auto-correct levels on an image using ImageMagick'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'storage_key' => ['type' => 'string', 'description' => 'MinIO key of source image'],
                'sharpen'     => ['type' => 'boolean', 'description' => 'Apply unsharp mask'],
                'denoise'     => ['type' => 'boolean', 'description' => 'Reduce noise'],
                'auto_levels' => ['type' => 'boolean', 'description' => 'Auto white balance'],
                'quality'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'required' => ['storage_key'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $localPath = $this->downloadFromStorage($params['storage_key']);

        try {
            $outputPath = $this->images->enhance($localPath, [
                'sharpen'     => $params['sharpen']     ?? true,
                'denoise'     => $params['denoise']     ?? false,
                'auto_levels' => $params['auto_levels'] ?? true,
                'quality'     => $params['quality']     ?? 85,
            ]);

            $outputKey = 'enhanced/' . Str::uuid() . '.' . pathinfo($outputPath, PATHINFO_EXTENSION);
            Storage::disk('minio')->put($outputKey, file_get_contents($outputPath));
            @unlink($localPath);
            @unlink($outputPath);

            return ['output_key' => $outputKey, 'success' => true];
        } catch (\Throwable $e) {
            @unlink($localPath);
            throw $e;
        }
    }

    private function downloadFromStorage(string $key): string
    {
        $content  = Storage::disk('minio')->get($key);
        $ext      = pathinfo($key, PATHINFO_EXTENSION) ?: 'jpg';
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.' . $ext);
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);
        return $tempPath;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. video_extract_frames
// ─────────────────────────────────────────────────────────────────────────────
class VideoExtractFramesSkill implements SkillInterface
{
    public function __construct(private readonly FFmpegService $ffmpeg) {}

    public function getName(): string { return 'video_extract_frames'; }
    public function getDescription(): string { return 'Extract frames from a video at specified intervals using FFmpeg'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'storage_key' => ['type' => 'string'],
                'fps'         => ['type' => 'number', 'description' => 'Frames per second to extract (e.g. 1 = 1 frame/sec)'],
                'start_time'  => ['type' => 'string', 'description' => 'HH:MM:SS'],
                'duration'    => ['type' => 'string', 'description' => 'HH:MM:SS'],
                'max_frames'  => ['type' => 'integer'],
            ],
            'required' => ['storage_key'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $content  = Storage::disk('minio')->get($params['storage_key']);
        $ext      = pathinfo($params['storage_key'], PATHINFO_EXTENSION) ?: 'mp4';
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.' . $ext);
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);

        try {
            $outputDir  = storage_path('app/temp/frames_' . Str::uuid());
            @mkdir($outputDir, 0755, true);

            $frames = $this->ffmpeg->extractFrames($tempPath, $outputDir, [
                'fps'       => $params['fps']       ?? 1,
                'start'     => $params['start_time'] ?? null,
                'duration'  => $params['duration']   ?? null,
                'max'       => $params['max_frames']  ?? 30,
            ]);

            $storedKeys = [];
            foreach ($frames as $framePath) {
                $key = 'frames/' . Str::uuid() . '.jpg';
                Storage::disk('minio')->put($key, file_get_contents($framePath));
                $storedKeys[] = $key;
                @unlink($framePath);
            }

            @unlink($tempPath);
            @rmdir($outputDir);

            return ['frame_keys' => $storedKeys, 'count' => count($storedKeys)];
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. background_remove  (uses ImageMagick edge-detection approach)
// ─────────────────────────────────────────────────────────────────────────────
class BackgroundRemoveSkill implements SkillInterface
{
    public function __construct(private readonly ImageService $images) {}

    public function getName(): string { return 'background_remove'; }
    public function getDescription(): string { return 'Remove image background using ImageMagick flood-fill'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'storage_key' => ['type' => 'string'],
                'fuzz'        => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Color similarity tolerance %'],
                'background_color' => ['type' => 'string', 'description' => 'Hex color to remove e.g. #FFFFFF'],
            ],
            'required' => ['storage_key'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $content  = Storage::disk('minio')->get($params['storage_key']);
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.png');
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);

        try {
            $outputPath = $this->images->removeBackground($tempPath, [
                'fuzz'       => $params['fuzz']             ?? 10,
                'bg_color'   => $params['background_color'] ?? '#FFFFFF',
            ]);

            $key = 'processed/bg_removed_' . Str::uuid() . '.png';
            Storage::disk('minio')->put($key, file_get_contents($outputPath));
            @unlink($tempPath);
            @unlink($outputPath);

            return ['output_key' => $key, 'format' => 'png', 'transparent' => true];
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. ocr_extract
// ─────────────────────────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────────────────────────
// 5. llm_generate_text
// ─────────────────────────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────────────────────────
// 6. resume_parse
// ─────────────────────────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────────────────────────
// 7. candidate_score
// ─────────────────────────────────────────────────────────────────────────────
class CandidateScoreSkill implements SkillInterface
{
    public function __construct(private readonly AIRouter $aiRouter) {}

    public function getName(): string { return 'candidate_score'; }
    public function getDescription(): string { return 'Score a parsed candidate against job requirements (0-100)'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'candidate'    => ['type' => 'object', 'description' => 'Parsed candidate data'],
                'job_title'    => ['type' => 'string'],
                'job_level'    => ['type' => 'string'],
                'requirements' => ['type' => 'array',  'items' => ['type' => 'string']],
                'nice_to_have' => ['type' => 'array',  'items' => ['type' => 'string']],
            ],
            'required' => ['candidate', 'job_title', 'requirements'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $candidate    = $params['candidate'];
        $requirements = implode("\n- ", $params['requirements']);
        $niceToHave   = ! empty($params['nice_to_have']) ? implode("\n- ", $params['nice_to_have']) : 'None specified';

        $candidateSkills = implode(', ', $candidate['skills'] ?? []);
        $prompt = <<<PROMPT
Score this candidate for the role. Return ONLY valid JSON.

ROLE: {$params['job_title']} ({$params['job_level']})

MUST-HAVE REQUIREMENTS:
- {$requirements}

NICE TO HAVE:
- {$niceToHave}

CANDIDATE:
Name: {$candidate['name']}
Title: {$candidate['current_title']}
Experience: {$candidate['years_experience']} years
Skills: {$candidateSkills}
Summary: {$candidate['summary']}

Score 0-100 on each dimension. Return:
{
  "overall": 0,
  "skills_match": {"score": 0, "notes": ""},
  "experience_match": {"score": 0, "notes": ""},
  "seniority_match": {"score": 0, "notes": ""},
  "red_flags": [],
  "strengths": [],
  "recommendation": "strong_yes|yes|maybe|no",
  "summary": "2 sentence hiring recommendation"
}
PROMPT;

        $raw   = $this->aiRouter->complete($prompt, 'claude-haiku-4-5-20251001', 1000, 0.1);
        $clean = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $score = json_decode($clean, true);

        if (! $score) {
            throw new \RuntimeException("Failed to parse scoring JSON");
        }

        return $score;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 8. job_post_publish
// ─────────────────────────────────────────────────────────────────────────────
class JobPostPublishSkill implements SkillInterface
{
    public function __construct(private readonly AIRouter $aiRouter) {}

    public function getName(): string { return 'job_post_publish'; }
    public function getDescription(): string { return 'Generate and store a formatted job posting'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'job_posting_id' => ['type' => 'string', 'description' => 'Existing JobPosting UUID'],
                'title'          => ['type' => 'string'],
                'department'     => ['type' => 'string'],
                'level'          => ['type' => 'string'],
                'requirements'   => ['type' => 'array', 'items' => ['type' => 'string']],
                'description'    => ['type' => 'string'],
            ],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        if (! empty($params['job_posting_id'])) {
            $posting = \App\Models\JobPosting::findOrFail($params['job_posting_id']);
        } else {
            // Generate full description if short
            $description = $params['description'] ?? '';
            if (strlen($description) < 300) {
                $reqs = implode(', ', $params['requirements'] ?? []);
                $description = $this->aiRouter->complete(
                    "Write a 400-word job description for {$params['title']} ({$params['level']}) requiring: {$reqs}. Cover role, responsibilities, and culture.",
                    'claude-haiku-4-5-20251001', 1000
                );
            }

            $posting = \App\Models\JobPosting::create([
                'title'        => $params['title'],
                'department'   => $params['department'] ?? 'Engineering',
                'level'        => $params['level']       ?? 'mid',
                'description'  => $description,
                'requirements' => $params['requirements'] ?? [],
                'status'       => 'open',
            ]);
        }

        return [
            'job_posting_id' => $posting->id,
            'title'          => $posting->title,
            'status'         => $posting->status,
            'published'      => true,
        ];
    }
}
