<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\Media\FFmpegService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
Skills: {implode(', ', $candidate['skills'] ?? [])}
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
