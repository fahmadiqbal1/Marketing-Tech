<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Models\Candidate;
use App\Models\JobPosting;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\AI\OpenAIService;
use App\Services\ApiCredentialService;
use App\Services\CampaignContextService;
use App\Services\IterationEngineService;
use App\Services\Knowledge\VectorStoreService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Log;

class HiringAgent extends BaseAgent
{
    protected string $agentType = 'hiring';

    public function __construct(
        OpenAIService          $openai,
        AnthropicService       $anthropic,
        GeminiService          $gemini,
        TelegramBotService     $telegram,
        VectorStoreService     $knowledge,
        ApiCredentialService   $credentials,
        IterationEngineService $iterationEngine,
        CampaignContextService $campaignContext,
    ) {
        parent::__construct($openai, $anthropic, $gemini, $telegram, $knowledge, $credentials, $iterationEngine, $campaignContext);
    }

    protected function executeTool(string $name, array $args, AgentJob $job): mixed
    {
        return match ($name) {
            'parse_cv'          => $this->toolParseCV($args),
            'score_candidate'   => $this->toolScoreCandidate($args),
            'draft_outreach'    => $this->toolDraftOutreach($args),
            'create_job_post'   => $this->toolCreateJobPost($args, $job),
            'update_pipeline'   => $this->toolUpdatePipeline($args),
            'search_candidates' => $this->toolSearchCandidates($args),
            'list_job_postings' => $this->toolListJobPostings($args),
            'get_pipeline_summary' => $this->toolGetPipelineSummary($args),
            default             => $this->toolResult(false, null, "Unknown tool: {$name}"),
        };
    }

    protected function getToolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'parse_cv',
                    'description' => 'Parse a CV/resume from text or extracted document and return structured candidate data',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'cv_text'    => ['type' => 'string', 'description' => 'Raw CV text content'],
                            'candidate_name' => ['type' => 'string'],
                            'source'     => ['type' => 'string', 'description' => 'Where candidate came from (e.g. LinkedIn, referral, job board)'],
                            'job_id'     => ['type' => 'string', 'description' => 'Job posting ID they applied for'],
                        ],
                        'required'   => ['cv_text'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'score_candidate',
                    'description' => 'Score a candidate against job requirements on multiple dimensions',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'candidate_id' => ['type' => 'string'],
                            'job_id'       => ['type' => 'string'],
                            'criteria'     => [
                                'type'        => 'array',
                                'description' => 'Custom scoring criteria',
                                'items'       => ['type' => 'string'],
                            ],
                        ],
                        'required'   => ['candidate_id', 'job_id'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'draft_outreach',
                    'description' => 'Draft a personalised outreach or rejection email for a candidate',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'candidate_id' => ['type' => 'string'],
                            'type'         => ['type' => 'string', 'enum' => ['invite_interview', 'reject', 'offer', 'follow_up', 'general']],
                            'tone'         => ['type' => 'string', 'enum' => ['warm', 'professional', 'brief']],
                            'notes'        => ['type' => 'string', 'description' => 'Specific notes to include'],
                        ],
                        'required'   => ['candidate_id', 'type'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'create_job_post',
                    'description' => 'Create a new job posting',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'title'           => ['type' => 'string'],
                            'department'      => ['type' => 'string'],
                            'location'        => ['type' => 'string'],
                            'employment_type' => ['type' => 'string', 'enum' => ['full_time', 'part_time', 'contract', 'freelance']],
                            'level'           => ['type' => 'string', 'enum' => ['junior', 'mid', 'senior', 'lead', 'director']],
                            'description'     => ['type' => 'string'],
                            'requirements'    => ['type' => 'array', 'items' => ['type' => 'string']],
                            'nice_to_have'    => ['type' => 'array', 'items' => ['type' => 'string']],
                            'salary_range'    => ['type' => 'string'],
                        ],
                        'required'   => ['title', 'department', 'description', 'requirements'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'update_pipeline',
                    'description' => 'Move a candidate to a different pipeline stage',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'candidate_id' => ['type' => 'string'],
                            'stage'        => ['type' => 'string', 'enum' => ['applied', 'screening', 'interview_1', 'interview_2', 'technical', 'offer', 'hired', 'rejected']],
                            'notes'        => ['type' => 'string'],
                        ],
                        'required'   => ['candidate_id', 'stage'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_candidates',
                    'description' => 'Search candidates by skills, experience, or semantic similarity',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query'   => ['type' => 'string', 'description' => 'Natural language search query'],
                            'job_id'  => ['type' => 'string'],
                            'stage'   => ['type' => 'string'],
                            'min_score' => ['type' => 'number', 'description' => '0-100 minimum score filter'],
                            'limit'   => ['type' => 'integer'],
                        ],
                        'required'   => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'list_job_postings',
                    'description' => 'List all open job postings with applicant counts',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['open', 'closed', 'draft', 'all']],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_pipeline_summary',
                    'description' => 'Get a summary of the hiring pipeline for a specific job or all jobs',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'job_id' => ['type' => 'string', 'description' => 'Specific job ID or "all"'],
                        ],
                        'required'   => ['job_id'],
                    ],
                ],
            ],
        ];
    }

    // ─── Tool Implementations ─────────────────────────────────────

    private function toolParseCV(array $args): string
    {
        try {
            $extractionPrompt = <<<PROMPT
Parse this CV/resume and return ONLY a JSON object with these fields:
{
  "name": "Full name",
  "email": "email@example.com",
  "phone": "+1234567890",
  "location": "City, Country",
  "linkedin": "URL or null",
  "github": "URL or null",
  "summary": "2-3 sentence professional summary",
  "years_experience": 5,
  "current_title": "Current or most recent job title",
  "current_company": "Current or most recent employer",
  "skills": ["skill1", "skill2"],
  "languages": ["English", "Spanish"],
  "education": [{"degree": "BSc CS", "institution": "MIT", "year": 2018}],
  "experience": [{"title": "...", "company": "...", "from": "2021-01", "to": "2024-03", "summary": "..."}],
  "certifications": ["AWS Solutions Architect"]
}

CV Text:
{$args['cv_text']}
PROMPT;

            $raw     = $this->anthropic->complete($extractionPrompt, 'claude-haiku-4-5-20251001', 2048, 0.0);
            $parsed  = json_decode($raw, true);

            if (! $parsed) {
                return $this->toolResult(false, null, "Failed to parse CV JSON");
            }

            // Store candidate with embedding of their skills + experience
            $embeddingText = implode(' ', array_filter([
                $parsed['summary']         ?? '',
                $parsed['current_title']   ?? '',
                implode(', ', $parsed['skills'] ?? []),
                collect($parsed['experience'] ?? [])->pluck('summary')->implode('. '),
            ]));

            $embedding = $this->openai->embed($embeddingText);

            $candidate = Candidate::create([
                'name'              => $parsed['name']        ?? $args['candidate_name'] ?? 'Unknown',
                'email'             => $parsed['email']       ?? null,
                'phone'             => $parsed['phone']       ?? null,
                'location'          => $parsed['location']    ?? null,
                'linkedin_url'      => $parsed['linkedin']    ?? null,
                'github_url'        => $parsed['github']      ?? null,
                'summary'           => $parsed['summary']     ?? null,
                'years_experience'  => $parsed['years_experience'] ?? 0,
                'current_title'     => $parsed['current_title'] ?? null,
                'current_company'   => $parsed['current_company'] ?? null,
                'skills'            => $parsed['skills']      ?? [],
                'education'         => $parsed['education']   ?? [],
                'experience'        => $parsed['experience']  ?? [],
                'certifications'    => $parsed['certifications'] ?? [],
                'languages'         => $parsed['languages']   ?? ['English'],
                'source'            => $args['source']        ?? 'direct',
                'applied_job_id'    => $args['job_id']        ?? null,
                'cv_raw'            => $args['cv_text'],
                'embedding'         => json_encode($embedding),
                'pipeline_stage'    => 'applied',
            ]);

            return $this->toolResult(true, [
                'candidate_id'    => $candidate->id,
                'name'            => $candidate->name,
                'email'           => $candidate->email,
                'current_title'   => $candidate->current_title,
                'years_experience' => $candidate->years_experience,
                'skills_count'    => count($candidate->skills),
            ]);

        } catch (\Throwable $e) {
            Log::error("CV parsing failed", ['error' => $e->getMessage()]);
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolScoreCandidate(array $args): string
    {
        try {
            $candidate = Candidate::findOrFail($args['candidate_id']);
            $job       = JobPosting::findOrFail($args['job_id']);

            $customCriteria = ! empty($args['criteria'])
                ? "Additional scoring criteria:\n" . implode("\n", $args['criteria'])
                : '';

            $prompt = <<<PROMPT
Score this candidate for the job position. Return ONLY JSON.

JOB REQUIREMENTS:
Title: {$job->title}
Level: {$job->level}
Requirements: {$job->requirements_text}
Nice to have: {$job->nice_to_have_text}

CANDIDATE:
Name: {$candidate->name}
Current title: {$candidate->current_title}
Years experience: {$candidate->years_experience}
Skills: {$candidate->skills_text}
Summary: {$candidate->summary}
Experience: {$candidate->experience_text}

{$customCriteria}

Score each dimension 0-100 and provide brief reasoning.
Return:
{
  "overall_score": 82,
  "skills_match": {"score": 85, "reasoning": "..."},
  "experience_match": {"score": 80, "reasoning": "..."},
  "level_match": {"score": 90, "reasoning": "..."},
  "growth_potential": {"score": 75, "reasoning": "..."},
  "red_flags": ["list any concerns"],
  "strengths": ["list key strengths"],
  "recommendation": "strong_yes|yes|maybe|no",
  "summary": "2-3 sentence hiring recommendation"
}
PROMPT;

            $raw   = $this->anthropic->complete($prompt, 'claude-haiku-4-5-20251001', 1500, 0.2);
            $score = json_decode($raw, true);

            if (! $score) {
                return $this->toolResult(false, null, "Score parsing failed");
            }

            // Update candidate record with score
            $candidate->update([
                'score'          => $score['overall_score'],
                'score_details'  => $score,
                'scored_for_job' => $job->id,
            ]);

            return $this->toolResult(true, array_merge($score, ['candidate_id' => $candidate->id]));

        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolDraftOutreach(array $args): string
    {
        try {
            $candidate = Candidate::findOrFail($args['candidate_id']);
            $tone      = $args['tone'] ?? 'professional';
            $type      = $args['type'];

            $typeInstructions = match ($type) {
                'invite_interview' => "Invite the candidate for an interview. Be specific about next steps.",
                'reject'           => "Send a polite rejection that's respectful and leaves the door open for future roles.",
                'offer'            => "Draft an offer letter with enthusiasm and key role details.",
                'follow_up'        => "Follow up on the candidate's status with a brief, friendly check-in.",
                'general'          => "Send a general outreach about a role opportunity.",
                default            => "Write a professional email."
            };

            $notes   = $args['notes'] ?? '';
            $prompt  = <<<PROMPT
Write a {$tone} {$type} email for this candidate.
{$typeInstructions}

Candidate: {$candidate->name}
Current Role: {$candidate->current_title} at {$candidate->current_company}
{$notes}

Return ONLY JSON: {"subject": "...", "body": "..."}
The body should be 150-250 words, use their first name, be personalised.
No placeholders like [Company Name] — use generic natural language instead.
PROMPT;

            $raw  = $this->anthropic->complete($prompt, 'claude-haiku-4-5-20251001', 800, 0.8);
            $email = json_decode($raw, true);

            return $this->toolResult(true, [
                'candidate_id' => $candidate->id,
                'subject'      => $email['subject'] ?? '',
                'body'         => $email['body']    ?? '',
                'type'         => $type,
            ]);

        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCreateJobPost(array $args, AgentJob $job): string
    {
        try {
            // Auto-generate full JD if only basics provided
            if (strlen($args['description'] ?? '') < 200) {
                $prompt = <<<PROMPT
Write a compelling job description for:
Title: {$args['title']}
Department: {$args['department']}
Level: {$args['level']}
Requirements: {$this->formatList($args['requirements'])}

Return a 400-600 word job description covering: role overview, responsibilities, what success looks like, and culture.
PROMPT;
                $args['description'] = $this->anthropic->complete($prompt, 'claude-haiku-4-5-20251001', 1000);
            }

            $posting = JobPosting::create([
                'title'           => $args['title'],
                'department'      => $args['department'],
                'location'        => $args['location']        ?? 'Remote',
                'employment_type' => $args['employment_type'] ?? 'full_time',
                'level'           => $args['level']           ?? 'mid',
                'description'     => $args['description'],
                'requirements'    => $args['requirements']    ?? [],
                'nice_to_have'    => $args['nice_to_have']    ?? [],
                'salary_range'    => $args['salary_range']    ?? null,
                'status'          => 'active',
                'agent_run_id'    => $job->id,
                'metadata'        => [
                    'platforms' => ['rozee.pk', 'indeed.pk', 'linkedin'],
                    'auto_published' => true,
                ],
            ]);

            \App\Models\SystemEvent::emit(
                'job_posting_created',
                "Job posting '{$posting->title}' created and published to platforms",
                'info',
                'hiring_agent',
                $posting->id,
                'JobPosting',
                ['platforms' => ['rozee.pk', 'indeed.pk', 'linkedin']]
            );

            return $this->toolResult(true, [
                'job_id'     => $posting->id,
                'title'      => $posting->title,
                'status'     => $posting->status,
            ]);

        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolUpdatePipeline(array $args): string
    {
        try {
            $candidate = Candidate::findOrFail($args['candidate_id']);
            $candidate->update([
                'pipeline_stage'   => $args['stage'],
                'pipeline_notes'   => $args['notes'] ?? null,
                'stage_updated_at' => now(),
            ]);

            return $this->toolResult(true, [
                'candidate_id' => $candidate->id,
                'name'         => $candidate->name,
                'stage'        => $candidate->pipeline_stage,
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolSearchCandidates(array $args): string
    {
        try {
            $queryEmbedding = $this->openai->embed($args['query']);

            $candidates = Candidate::search(
                embedding:  $queryEmbedding,
                jobId:      $args['job_id']    ?? null,
                stage:      $args['stage']     ?? null,
                minScore:   $args['min_score'] ?? 0,
                limit:      $args['limit']     ?? 10,
            );

            return $this->toolResult(true, $candidates->toArray());
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolListJobPostings(array $args): string
    {
        $status = $args['status'] ?? 'open';
        $query  = JobPosting::query()->withCount('candidates');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $jobs = $query->orderBy('created_at', 'desc')
            ->get(['id', 'title', 'department', 'level', 'status', 'created_at'])
            ->toArray();

        return $this->toolResult(true, $jobs);
    }

    private function toolGetPipelineSummary(array $args): string
    {
        $query = Candidate::query();

        if ($args['job_id'] !== 'all') {
            $query->where('applied_job_id', $args['job_id']);
        }

        $stages = $query->selectRaw('pipeline_stage, COUNT(*) as count, AVG(score) as avg_score')
            ->groupBy('pipeline_stage')
            ->get()
            ->keyBy('pipeline_stage')
            ->toArray();

        return $this->toolResult(true, ['stages' => $stages]);
    }

    private function formatList(array $items): string
    {
        return implode(', ', $items);
    }
}
