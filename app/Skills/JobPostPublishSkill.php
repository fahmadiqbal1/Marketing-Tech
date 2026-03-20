<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\Media\FFmpegService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
