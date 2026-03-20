<?php

namespace Database\Seeders;

use App\Models\SkillRegistry;
use Illuminate\Database\Seeder;

class SkillsRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            [
                'name'        => 'image_enhance',
                'class'       => \App\Skills\ImageEnhanceSkill::class,
                'category'    => 'media',
                'description' => 'Sharpen, denoise, and auto-correct levels on an image using ImageMagick',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'storage_key' => ['type' => 'string'],
                        'sharpen'     => ['type' => 'boolean'],
                        'denoise'     => ['type' => 'boolean'],
                        'auto_levels' => ['type' => 'boolean'],
                        'quality'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    ],
                    'required' => ['storage_key'],
                ],
                'required_services' => ['imagemagick'],
                'queue'             => 'media',
                'timeout_seconds'   => 60,
            ],
            [
                'name'        => 'video_extract_frames',
                'class'       => \App\Skills\VideoExtractFramesSkill::class,
                'category'    => 'media',
                'description' => 'Extract frames from video at specified intervals using FFmpeg',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'storage_key' => ['type' => 'string'],
                        'fps'         => ['type' => 'number'],
                        'max_frames'  => ['type' => 'integer'],
                        'format'      => ['type' => 'string', 'enum' => ['jpg','png','webp']],
                    ],
                    'required' => ['storage_key'],
                ],
                'required_services' => ['ffmpeg'],
                'queue'             => 'media',
                'timeout_seconds'   => 120,
            ],
            [
                'name'        => 'background_remove',
                'class'       => \App\Skills\BackgroundRemoveSkill::class,
                'category'    => 'media',
                'description' => 'Remove background from image using AI segmentation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'storage_key'  => ['type' => 'string'],
                        'output_format' => ['type' => 'string', 'enum' => ['png','webp']],
                    ],
                    'required' => ['storage_key'],
                ],
                'required_services' => ['imagemagick'],
                'queue'             => 'media',
                'timeout_seconds'   => 90,
            ],
            [
                'name'        => 'ocr_extract',
                'class'       => \App\Skills\OcrExtractSkill::class,
                'category'    => 'media',
                'description' => 'Extract text from images or PDFs using Tesseract OCR',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'storage_key' => ['type' => 'string'],
                        'language'    => ['type' => 'string'],
                        'page_range'  => ['type' => 'string'],
                    ],
                    'required' => ['storage_key'],
                ],
                'required_services' => ['tesseract'],
                'queue'             => 'media',
                'timeout_seconds'   => 120,
            ],
            [
                'name'        => 'llm_generate_text',
                'class'       => \App\Skills\LlmGenerateTextSkill::class,
                'category'    => 'ai',
                'description' => 'Generate text using the AI router with configurable model and prompt',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt'      => ['type' => 'string'],
                        'system'      => ['type' => 'string'],
                        'model'       => ['type' => 'string'],
                        'max_tokens'  => ['type' => 'integer'],
                        'temperature' => ['type' => 'number'],
                    ],
                    'required' => ['prompt'],
                ],
                'required_services' => [],
                'queue'             => 'content',
                'timeout_seconds'   => 120,
            ],
            [
                'name'        => 'resume_parse',
                'class'       => \App\Skills\ResumeParseSkill::class,
                'category'    => 'hiring',
                'description' => 'Parse a CV/resume from raw text into structured candidate data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'cv_text'    => ['type' => 'string'],
                        'source'     => ['type' => 'string'],
                        'job_id'     => ['type' => 'string'],
                    ],
                    'required' => ['cv_text'],
                ],
                'required_services' => [],
                'queue'             => 'hiring',
                'timeout_seconds'   => 90,
            ],
            [
                'name'        => 'candidate_score',
                'class'       => \App\Skills\CandidateScoreSkill::class,
                'category'    => 'hiring',
                'description' => 'Score a candidate against job requirements on multiple dimensions',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'candidate_id' => ['type' => 'string'],
                        'job_id'       => ['type' => 'string'],
                    ],
                    'required' => ['candidate_id', 'job_id'],
                ],
                'required_services' => [],
                'queue'             => 'hiring',
                'timeout_seconds'   => 60,
            ],
            [
                'name'        => 'job_post_publish',
                'class'       => \App\Skills\JobPostPublishSkill::class,
                'category'    => 'hiring',
                'description' => 'Publish a job posting to configured job boards',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'job_posting_id' => ['type' => 'string'],
                        'boards'         => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['job_posting_id'],
                ],
                'required_services' => [],
                'queue'             => 'hiring',
                'timeout_seconds'   => 60,
            ],
        ];

        foreach ($skills as $skill) {
            SkillRegistry::updateOrCreate(
                ['name' => $skill['name']],
                array_merge($skill, ['is_active' => true])
            );
            $this->command->info("  Registered skill: {$skill['name']}");
        }
    }
}
