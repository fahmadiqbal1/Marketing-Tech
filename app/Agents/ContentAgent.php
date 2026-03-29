<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Models\ContentCalendar;
use App\Models\ContentItem;
use App\Models\ContentVariation;
use App\Models\HashtagSet;
use App\Models\KnowledgeBase;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\AI\OpenAIService;
use App\Services\ApiCredentialService;
use App\Services\CampaignContextService;
use App\Services\IterationEngineService;
use App\Services\Knowledge\VectorStoreService;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentAgent extends BaseAgent
{
    protected string $agentType = 'content';

    public function __construct(
        OpenAIService $openai,
        AnthropicService $anthropic,
        GeminiService $gemini,
        TelegramBotService $telegram,
        VectorStoreService $knowledge,
        ApiCredentialService $credentials,
        IterationEngineService $iterationEngine,
        CampaignContextService $campaignContext,
    ) {
        parent::__construct($openai, $anthropic, $gemini, $telegram, $knowledge, $credentials, $iterationEngine, $campaignContext);
    }

    protected function executeTool(string $name, array $args, AgentJob $job): mixed
    {
        // Social tool gating: check task_type first, fall back to keyword regex for NULL (backward compat)
        $socialTools = ['hashtag_strategy', 'trend_analysis', 'cross_platform_adapt', 'create_content_calendar', 'select_hashtags'];
        if (in_array($name, $socialTools)) {
            $taskType = $job->task_type;
            $instruction = strtolower($job->instruction ?? '');
            $isSocial = $taskType === 'social'
                || ($taskType === null && preg_match('/social|calendar|hashtag|trend|platform|schedule|instagram|tiktok|linkedin|twitter|facebook/', $instruction));

            if (! $isSocial) {
                return $this->toolResult(false, null,
                    "Tool '{$name}' requires task_type=social. ".
                    'Current task_type: '.($taskType ?? 'null (keyword match also failed)').'. '.
                    'Set task_type=social on the agent job, or include social keywords in the instruction.'
                );
            }
        }

        return match ($name) {
            'generate_content' => $this->toolGenerateContent($args, $job),
            'check_seo' => $this->toolCheckSEO($args),
            'save_to_knowledge' => $this->toolSaveToKnowledge($args),
            'search_knowledge' => $this->toolSearchKnowledge($args),
            'publish_content' => $this->toolPublishContent($args),
            'repurpose_content' => $this->toolRepurposeContent($args, $job),
            'analyse_content' => $this->toolAnalyseContent($args),
            'keyword_research' => $this->toolKeywordResearch($args),
            'hashtag_strategy' => $this->toolHashtagStrategy($args),
            'trend_analysis' => $this->toolTrendAnalysis($args),
            'cross_platform_adapt' => $this->toolCrossPlatformAdapt($args),
            'create_content_calendar' => $this->toolCreateContentCalendar($args),
            'select_hashtags'           => $this->toolSelectHashtags($args),
            'generate_platform_variants'=> $this->toolGeneratePlatformVariants($args, $job),
            'find_trending_audio'       => $this->toolFindTrendingAudio($args),
            default => $this->toolResult(false, null, "Unknown tool: {$name}"),
        };
    }

    protected function getToolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_content',
                    'description' => 'Generate high-quality content for a given platform and purpose',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['blog_post', 'social_twitter', 'social_linkedin', 'social_instagram', 'email_newsletter', 'ad_copy', 'product_description', 'video_script', 'press_release']],
                            'topic' => ['type' => 'string'],
                            'tone' => ['type' => 'string', 'enum' => ['professional', 'casual', 'authoritative', 'friendly', 'urgent', 'inspiring']],
                            'keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Target SEO keywords'],
                            'word_count' => ['type' => 'integer', 'description' => 'Target word count (0 = platform default)'],
                            'audience' => ['type' => 'string', 'description' => 'Target audience description'],
                            'brand_voice' => ['type' => 'string', 'description' => 'Brand voice notes or style guidelines'],
                            'cta' => ['type' => 'string', 'description' => 'Call to action to include'],
                        ],
                        'required' => ['type', 'topic'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_seo',
                    'description' => 'Analyse content for SEO quality and provide improvement suggestions',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'string'],
                            'target_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'url_slug' => ['type' => 'string'],
                        ],
                        'required' => ['content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'save_to_knowledge',
                    'description' => 'Save content or facts to the long-term knowledge base for future reference',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'category' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_knowledge',
                    'description' => 'Search the knowledge base for relevant content, brand guidelines, or facts',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'category' => ['type' => 'string'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'publish_content',
                    'description' => 'Save finalised content to the content library with metadata',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'platform' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['draft', 'ready', 'scheduled', 'published']],
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'scheduled_at' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'body', 'type'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'repurpose_content',
                    'description' => 'Repurpose existing content into a different format (e.g. blog post → Twitter thread)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'source_content' => ['type' => 'string'],
                            'source_type' => ['type' => 'string'],
                            'target_type' => ['type' => 'string', 'enum' => ['social_twitter', 'social_linkedin', 'email_newsletter', 'video_script', 'ad_copy']],
                        ],
                        'required' => ['source_content', 'target_type'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'analyse_content',
                    'description' => 'Analyse content for readability, tone, engagement prediction',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'string'],
                            'platform' => ['type' => 'string'],
                        ],
                        'required' => ['content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'keyword_research',
                    'description' => 'Research and analyse keywords for a topic and platform. Stores results to knowledge base.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string', 'description' => 'Main topic to research'],
                            'platform' => ['type' => 'string', 'enum' => ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin', 'google', 'general']],
                            'niche' => ['type' => 'string', 'description' => 'Industry or content niche'],
                        ],
                        'required' => ['topic'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'hashtag_strategy',
                    'description' => 'Generate a platform-specific hashtag strategy with tiered reach. Requires task_type=social.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string'],
                            'platform' => ['type' => 'string', 'enum' => ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin']],
                            'niche' => ['type' => 'string'],
                            'save_set' => ['type' => 'boolean', 'description' => 'Save this hashtag set to the library for reuse'],
                            'set_name' => ['type' => 'string', 'description' => 'Name for the saved hashtag set (required if save_set=true)'],
                        ],
                        'required' => ['topic', 'platform'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'trend_analysis',
                    'description' => 'Analyse patterns from existing knowledge base and performance data. Returns analytical insights only — does NOT fetch live trends. Requires task_type=social.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'platform' => ['type' => 'string', 'enum' => ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin', 'all']],
                            'niche' => ['type' => 'string'],
                            'limit' => ['type' => 'integer', 'description' => 'Max number of insights to return (default 5)'],
                        ],
                        'required' => ['platform'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'cross_platform_adapt',
                    'description' => 'Adapt source content for multiple target platforms, respecting platform constraints. Requires task_type=social.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'source_content' => ['type' => 'string'],
                            'source_platform' => ['type' => 'string'],
                            'target_platforms' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin']]],
                        ],
                        'required' => ['source_content', 'target_platforms'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_content_calendar',
                    'description' => 'Create a 7-day content calendar and save entries to the database as drafts. Validates platform/content_type combos. Requires task_type=social.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'brand_name' => ['type' => 'string'],
                            'platforms' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin']]],
                            'frequency' => ['type' => 'string', 'description' => 'Posting frequency e.g. "daily", "3x per week"'],
                            'content_pillars' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Content themes/pillars'],
                        ],
                        'required' => ['brand_name', 'platforms'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'select_hashtags',
                    'description' => 'Select the best matching hashtag set from the library for a platform and topic. Requires task_type=social.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'platform' => ['type' => 'string', 'enum' => ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin']],
                            'niche' => ['type' => 'string', 'description' => 'Content niche or topic'],
                        ],
                        'required' => ['platform'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'generate_platform_variants',
                    'description' => 'Generate caption + hashtags for all 6 social platforms in one call, respecting platform char limits and format norms',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'campaign_type' => ['type' => 'string', 'description' => 'e.g. opening_soon, product_launch, promotion, event'],
                            'key_message'   => ['type' => 'string', 'description' => 'The core one-sentence message'],
                            'tone'          => ['type' => 'string', 'enum' => ['professional', 'casual', 'exciting', 'urgent', 'warm'], 'default' => 'exciting'],
                            'cta'           => ['type' => 'string', 'description' => 'Call to action e.g. "Shop now", "Follow us"'],
                            'platforms'     => [
                                'type'  => 'array',
                                'items' => ['type' => 'string', 'enum' => ['instagram', 'tiktok', 'linkedin', 'facebook', 'twitter', 'youtube']],
                            ],
                        ],
                        'required'   => ['key_message', 'platforms'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'find_trending_audio',
                    'description' => 'Search the knowledge base for trending audio/sounds for a specific platform, content type, and mood',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'platform'     => ['type' => 'string', 'enum' => ['tiktok', 'instagram', 'youtube', 'facebook']],
                            'content_type' => ['type' => 'string', 'description' => 'e.g. reel, story, shorts, post'],
                            'mood'         => ['type' => 'string', 'description' => 'e.g. upbeat, emotional, energetic, calm, funny'],
                        ],
                        'required'   => ['platform'],
                    ],
                ],
            ],
        ];
    }

    // ─── Tool Implementations ─────────────────────────────────────

    private function toolGenerateContent(array $args, AgentJob $job): string
    {
        $platformDefaults = [
            'blog_post' => 1200,
            'social_twitter' => 280,
            'social_linkedin' => 1300,
            'social_instagram' => 400,
            'email_newsletter' => 600,
            'ad_copy' => 150,
            'product_description' => 300,
            'video_script' => 800,
            'press_release' => 500,
        ];

        $wordCount = $args['word_count'] ?: ($platformDefaults[$args['type']] ?? 500);
        $keywords = ! empty($args['keywords']) ? 'Target SEO keywords: '.implode(', ', $args['keywords']) : '';
        $audience = $args['audience'] ?? 'general professional audience';
        $tone = $args['tone'] ?? 'professional';
        $brandVoice = $args['brand_voice'] ?? '';
        $cta = $args['cta'] ? "Include a natural call-to-action: {$args['cta']}" : '';

        $typeInstructions = match ($args['type']) {
            'social_twitter' => 'Write a Twitter/X thread. Each tweet max 280 chars. Number each tweet. Make it engaging and shareable.',
            'social_linkedin' => 'Write a LinkedIn post. Use line breaks, bold sparingly. Include a hook opening line.',
            'social_instagram' => 'Write an Instagram caption. Engaging, with relevant hashtags at end.',
            'blog_post' => 'Write a full blog post with H2/H3 headings, introduction, body sections, and conclusion.',
            'email_newsletter' => 'Write an email newsletter with subject line, preview text, and body.',
            'video_script' => 'Write a video script with scene directions, on-screen text notes, and spoken dialogue.',
            'press_release' => 'Write a press release in AP style with dateline, lead paragraph, body, and boilerplate.',
            default => "Write professional {$args['type']} content.",
        };

        try {
            $generationPrompt = <<<PROMPT
{$typeInstructions}

Topic: {$args['topic']}
Target word count: ~{$wordCount} words per variation
Target audience: {$audience}
{$keywords}
{$brandVoice}
{$cta}

Generate THREE distinct variations (A, B, C) with different hooks, tones, and structures.
Return ONLY a valid JSON object — no preamble, no markdown fences — in exactly this shape:
{
  "A": {"label":"A","content":"...","tone":"professional","hook_type":"question","structure":"listicle"},
  "B": {"label":"B","content":"...","tone":"casual","hook_type":"statistic","structure":"narrative"},
  "C": {"label":"C","content":"...","tone":"urgent","hook_type":"bold_claim","structure":"problem_solution"}
}
Each "content" value must be the full piece of content.
PROMPT;

            $raw = $this->anthropic->complete(
                $generationPrompt,
                config('agents.anthropic.default_model', 'claude-haiku-4-5-20251001'),
                8192,
                0.8
            );

            // Parse variations JSON
            $parsed = json_decode(trim($raw), true);

            if (is_array($parsed) && isset($parsed['A'], $parsed['B'], $parsed['C'])) {
                // Soft guard: skip if this job already has 5+ variations stored
                $existingCount = ContentVariation::where('agent_job_id', $job->id)->count();

                // Store all three variations atomically (ContentVariation + GeneratedOutput in one transaction)
                foreach (['A', 'B', 'C'] as $label) {
                    if ($existingCount >= 5) {
                        Log::warning('ContentAgent: variation limit reached', ['job_id' => $job->id, 'limit' => 5]);
                        break;
                    }

                    $v = $parsed[$label];
                    $content = $v['content'] ?? '';

                    // Stronger hash: normalise whitespace over first 500 chars
                    $hash = md5(preg_replace('/\s+/', ' ', substr($content, 0, 500)));
                    $exists = ContentVariation::where('agent_job_id', $job->id)
                        ->whereRaw("md5(regexp_replace(substr(content, 1, 500), '\s+', ' ', 'g')) = ?", [$hash])
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    // Atomic: variation + output created together or not at all
                    $this->iterationEngine->storeVariationWithOutput(
                        agentJobId: $job->id,
                        label: $label,
                        content: $content,
                        outputType: 'content',
                        metadata: [
                            'tone' => $v['tone'] ?? null,
                            'hook_type' => $v['hook_type'] ?? null,
                            'structure' => $v['structure'] ?? null,
                            'word_count' => str_word_count($content),
                        ],
                    );
                    $existingCount++;
                }

                $primaryContent = $parsed['A']['content'];
            } else {
                // Fallback: treat raw response as single piece of content
                Log::warning('ContentAgent: variation JSON parse failed, using raw response as variation A', [
                    'job_id' => $job->id,
                    'raw' => substr($raw, 0, 200),
                ]);
                $primaryContent = $raw;
                // Atomic: variation + output in one transaction
                $this->iterationEngine->storeVariationWithOutput(
                    agentJobId: $job->id,
                    label: 'A',
                    content: $raw,
                    outputType: 'content',
                    metadata: [
                        'tone' => $tone,
                        'word_count' => str_word_count($raw),
                    ],
                );
            }

            return $this->toolResult(true, [
                'content' => $primaryContent,
                'type' => $args['type'],
                'word_count' => str_word_count($primaryContent),
                'char_count' => strlen($primaryContent),
                'variations_stored' => is_array($parsed) && isset($parsed['A']) ? 3 : 1,
            ]);

        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCheckSEO(array $args): string
    {
        $content = $args['content'];
        $keywords = $args['target_keywords'] ?? [];

        $prompt = <<<PROMPT
Perform an SEO analysis. Return ONLY JSON.

Content to analyse (first 3000 chars shown):
{$content}

Target keywords: {$this->formatList($keywords)}

Analyse:
1. Keyword presence and density
2. Readability score (Flesch-Kincaid estimate)
3. Content structure (headings, paragraphs)
4. Meta description recommendation (155 chars max)
5. Title tag recommendation (60 chars max)

Return:
{
  "score": 75,
  "readability": "good|ok|poor",
  "keyword_coverage": {"keyword": {"present": true, "density": "1.2%"}},
  "issues": ["list of issues"],
  "improvements": ["actionable suggestions"],
  "meta_description": "recommended meta desc",
  "title_tag": "recommended title tag"
}
PROMPT;

        try {
            $raw = $this->openai->complete($prompt, 'gpt-4o-mini', 1024, 0.1);
            $result = json_decode($raw, true);

            return $this->toolResult(true, $result ?? ['raw' => $raw]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolSaveToKnowledge(array $args): string
    {
        try {
            $id = $this->knowledge->store(
                title: $args['title'],
                content: $args['content'],
                tags: $args['tags'] ?? [],
                category: $args['category'] ?? 'content',
            );

            return $this->toolResult(true, ['knowledge_id' => $id, 'title' => $args['title']]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolSearchKnowledge(array $args): string
    {
        try {
            $results = $this->knowledge->search(
                query: $args['query'],
                topK: $args['limit'] ?? 5,
                category: $args['category'] ?? null,
            );

            return $this->toolResult(true, $results);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolPublishContent(array $args): string
    {
        try {
            $item = ContentItem::create([
                'title' => $args['title'],
                'body' => $args['body'],
                'type' => $args['type'],
                'platform' => $args['platform'] ?? null,
                'status' => $args['status'] ?? 'draft',
                'tags' => $args['tags'] ?? [],
                'scheduled_at' => isset($args['scheduled_at']) ? Carbon::parse($args['scheduled_at']) : null,
                'word_count' => str_word_count($args['body']),
            ]);

            return $this->toolResult(true, ['content_id' => $item->id, 'status' => $item->status]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolRepurposeContent(array $args, AgentJob $job): string
    {
        $result = $this->toolGenerateContent([
            'type' => $args['target_type'],
            'topic' => "Repurpose this content:\n\n{$args['source_content']}",
            'tone' => 'professional',
            'word_count' => 0,
        ], $job);

        return $result;
    }

    private function toolAnalyseContent(array $args): string
    {
        $platform = $args['platform'] ?? 'general';
        $prompt = <<<PROMPT
Analyse this content for quality. Return ONLY JSON.

Content: {$args['content']}
Platform: {$platform}

Return:
{
  "readability_score": 72,
  "grade_level": "8th grade",
  "tone": "professional",
  "engagement_prediction": "high|medium|low",
  "sentiment": "positive|neutral|negative",
  "strengths": ["..."],
  "weaknesses": ["..."],
  "best_time_to_post": "Tuesday 10am EST"
}
PROMPT;

        try {
            $raw = $this->openai->complete($prompt, 'gpt-4o-mini', 1024, 0.1);
            $result = json_decode($raw, true);

            return $this->toolResult(true, $result ?? ['raw' => $raw]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolKeywordResearch(array $args): string
    {
        $topic = $args['topic'];
        $platform = $args['platform'] ?? 'general';
        $niche = $args['niche'] ?? '';

        $prompt = <<<PROMPT
Perform keyword research for the following. Return ONLY valid JSON.

Topic: {$topic}
Platform: {$platform}
Niche: {$niche}

Analyse search intent and return keywords optimised for {$platform} discoverability.

Return:
{
  "primary_keyword": "...",
  "secondary": ["...", "..."],
  "long_tail": ["...", "..."],
  "search_intent": "informational|navigational|transactional|commercial",
  "content_angle": "...",
  "estimated_monthly_searches": "low|medium|high",
  "competition": "low|medium|high"
}
PROMPT;

        try {
            $raw = $this->anthropic->complete($prompt, config('agents.anthropic.default_model', 'claude-haiku-4-5-20251001'), 1024, 0.2);
            $result = json_decode(trim($raw), true) ?? ['raw' => $raw];

            // Store to knowledge base for future reference
            $this->knowledge->store(
                title: "Keyword Research: {$topic} ({$platform})",
                content: json_encode($result),
                tags: ['keywords', $platform, $niche],
                category: 'keyword-research',
            );

            return $this->toolResult(true, $result);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolHashtagStrategy(array $args): string
    {
        $topic = $args['topic'];
        $platform = $args['platform'];
        $niche = $args['niche'] ?? '';

        $platformRules = [
            'tiktok' => '3-5 hashtags total: 1 niche (<100k), 1 medium (100k-1M), 1 broad (1M+)',
            'instagram' => '5-10 hashtags: 60% niche, 30% medium, 10% broad',
            'linkedin' => '3-5 professional hashtags only, no personal/generic',
            'twitter' => '2-3 hashtags maximum; more reduces engagement',
            'facebook' => '3-5 hashtags; focus on community/group-relevant tags',
        ];

        $rules = $platformRules[$platform] ?? '5-10 relevant hashtags';

        $prompt = <<<PROMPT
Generate a hashtag strategy for {$platform}. Return ONLY valid JSON.

Topic: {$topic}
Niche: {$niche}
Platform rule: {$rules}

Return:
{
  "hashtags": [
    {"tag": "#example", "tier": "niche|medium|broad", "estimated_reach": "50k"}
  ],
  "usage_note": "...",
  "total_count": 5
}
PROMPT;

        try {
            $raw = $this->anthropic->complete($prompt, config('agents.anthropic.default_model', 'claude-haiku-4-5-20251001'), 1024, 0.3);
            $result = json_decode(trim($raw), true) ?? ['raw' => $raw];

            // Optionally save to hashtag_sets library
            if (! empty($args['save_set']) && $args['save_set'] && ! empty($args['set_name']) && is_array($result['hashtags'] ?? null)) {
                $tags = array_column($result['hashtags'], 'tag');
                HashtagSet::create([
                    'name' => $args['set_name'],
                    'platform' => $platform,
                    'niche' => $niche ?: null,
                    'tags' => $tags,
                    'reach_tier' => 'medium',
                ]);
                $result['saved_to_library'] = true;
                $result['set_name'] = $args['set_name'];
            }

            return $this->toolResult(true, $result);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolTrendAnalysis(array $args): string
    {
        // Analyses EXISTING data only — no LLM hallucination of "live trends"
        $platform = $args['platform'];
        $niche = $args['niche'] ?? null;
        $limit = min((int) ($args['limit'] ?? 5), 10);

        try {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            // Query recent knowledge base entries for this platform
            $knowledgeQuery = KnowledgeBase::whereNull('deleted_at')
                ->where(function ($q) use ($platform, $niche, $like) {
                    $q->where('content', $like, "%{$platform}%");
                    if ($niche) {
                        $q->orWhere('content', $like, "%{$niche}%");
                    }
                })
                ->latest()
                ->limit(20)
                ->get(['title', 'content', 'category', 'created_at']);

            $patterns = $knowledgeQuery->map(fn ($k) => [
                'title' => $k->title,
                'excerpt' => mb_substr($k->content, 0, 300),
                'category' => $k->category,
                'age_days' => $k->created_at->diffInDays(now()),
            ])->toArray();

            // Build analytical insight from patterns (no external API call)
            $insights = [];
            foreach (array_slice($patterns, 0, $limit) as $i => $pattern) {
                $insights[] = [
                    'type' => 'analytical_insight',
                    'platform' => $platform,
                    'source' => 'knowledge_base',
                    'title' => $pattern['title'],
                    'excerpt' => $pattern['excerpt'],
                    'age_days' => $pattern['age_days'],
                    'confidence' => $pattern['age_days'] < 7 ? 'high' : ($pattern['age_days'] < 30 ? 'medium' : 'low'),
                ];
            }

            return $this->toolResult(true, [
                'type' => 'analytical_insight',
                'platform' => $platform,
                'insights' => $insights,
                'total_found' => count($patterns),
                'note' => 'Insights derived from existing knowledge base entries only. No live data fetched.',
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCrossPlatformAdapt(array $args): string
    {
        $source = $args['source_content'];
        $sourcePlatform = $args['source_platform'] ?? 'general';
        $targetPlatforms = $args['target_platforms'] ?? [];

        $constraints = [
            'tiktok' => 'Max 150 chars caption + 3-5 hashtags. Hook in first line. Vertical video cue.',
            'instagram' => 'Max 2200 chars. 5-10 hashtags at end. First line must hook before "more" truncation.',
            'linkedin' => 'Max 3000 chars. No hashtags in body — add 3-5 at very end. Professional tone.',
            'twitter' => 'Max 280 chars per tweet. Thread of 3-5 tweets. No more than 2-3 hashtags.',
            'facebook' => 'Max 500 chars for best reach. Conversational tone. No more than 3 hashtags.',
        ];

        $platformList = implode(', ', $targetPlatforms);
        $constraintText = collect($targetPlatforms)
            ->map(fn ($p) => "{$p}: ".($constraints[$p] ?? 'standard format'))
            ->implode("\n");

        $prompt = <<<PROMPT
Adapt this content from {$sourcePlatform} to multiple platforms. Return ONLY valid JSON.

Source content:
{$source}

Target platforms: {$platformList}

Platform constraints:
{$constraintText}

Return:
{
  "adaptations": {
    "platform_name": {
      "content": "...",
      "char_count": 150,
      "hashtag_count": 5,
      "notes": "..."
    }
  }
}
PROMPT;

        try {
            $raw = $this->anthropic->complete($prompt, config('agents.anthropic.default_model', 'claude-haiku-4-5-20251001'), 4096, 0.7);
            $result = json_decode(trim($raw), true) ?? ['raw' => $raw];

            return $this->toolResult(true, $result);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCreateContentCalendar(array $args): string
    {
        $brandName = $args['brand_name'];
        $platforms = $args['platforms'] ?? [];
        $frequency = $args['frequency'] ?? 'daily';
        $pillars = $args['content_pillars'] ?? ['educational', 'entertaining', 'promotional'];

        // Validation matrix: invalid platform/content_type combos
        $invalidCombos = [
            'instagram' => ['thread'],
            'twitter' => ['reel', 'story', 'carousel'],
            'linkedin' => ['reel', 'story'],
            'facebook' => ['thread'],
        ];

        $defaultTypes = [
            'tiktok' => ['reel', 'post'],
            'instagram' => ['reel', 'carousel', 'post', 'story'],
            'facebook' => ['post', 'reel'],
            'twitter' => ['post', 'thread'],
            'linkedin' => ['post', 'carousel'],
        ];

        $prompt = <<<PROMPT
Create a 7-day content calendar for {$brandName}. Return ONLY valid JSON.

Platforms: {$this->formatList($platforms)}
Posting frequency: {$frequency}
Content pillars: {$this->formatList($pillars)}

Return:
{
  "calendar": [
    {
      "day": 1,
      "date_offset": "+1 day",
      "platform": "instagram",
      "content_type": "reel",
      "pillar": "educational",
      "title": "...",
      "draft_content": "...",
      "hashtags": ["#tag1", "#tag2"]
    }
  ]
}
PROMPT;

        try {
            $raw = $this->anthropic->complete($prompt, config('agents.anthropic.default_model', 'claude-haiku-4-5-20251001'), 4096, 0.8);
            $plan = json_decode(trim($raw), true);

            if (! is_array($plan) || empty($plan['calendar'])) {
                return $this->toolResult(false, null, 'Failed to generate calendar plan');
            }

            $created = [];
            $skipped = [];

            foreach ($plan['calendar'] as $entry) {
                $platform = $entry['platform'] ?? '';
                $contentType = $entry['content_type'] ?? 'post';

                // Validate combo
                if (isset($invalidCombos[$platform]) && in_array($contentType, $invalidCombos[$platform])) {
                    $skipped[] = "{$platform}/{$contentType} is not a valid combination";
                    // Use default type for this platform instead
                    $contentType = $defaultTypes[$platform][0] ?? 'post';
                }

                $scheduledAt = now()->addDays((int) ($entry['day'] ?? 1));

                $calendar = ContentCalendar::create([
                    'title' => $entry['title'] ?? "Day {$entry['day']} — {$platform}",
                    'platform' => $platform,
                    'content_type' => $contentType,
                    'draft_content' => $entry['draft_content'] ?? null,
                    'status' => 'draft',
                    'scheduled_at' => $scheduledAt,
                    'hashtags' => $entry['hashtags'] ?? [],
                    'metadata' => ['pillar' => $entry['pillar'] ?? null, 'brand' => $brandName],
                ]);

                $created[] = [
                    'id' => $calendar->id,
                    'platform' => $platform,
                    'content_type' => $contentType,
                    'title' => $calendar->title,
                    'scheduled_at' => $scheduledAt->toDateString(),
                ];
            }

            return $this->toolResult(true, [
                'created' => $created,
                'skipped' => $skipped,
                'total' => count($created),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolSelectHashtags(array $args): string
    {
        $platform = $args['platform'];
        $niche = $args['niche'] ?? null;

        try {
            $query = HashtagSet::forPlatform($platform)
                ->orderByDesc('usage_count');

            if ($niche) {
                $query->where(function ($q) use ($niche) {
                    $q->forNiche($niche)->orWhereNull('niche');
                });
            }

            $set = $query->first();

            if (! $set) {
                return $this->toolResult(false, null, "No hashtag sets found for platform={$platform}".($niche ? " niche={$niche}" : '').'. Use hashtag_strategy to create one.');
            }

            // Increment usage count
            $set->incrementUsage();

            return $this->toolResult(true, [
                'set_id' => $set->id,
                'name' => $set->name,
                'platform' => $set->platform,
                'niche' => $set->niche,
                'tags' => $set->tags,
                'reach_tier' => $set->reach_tier,
                'usage_count' => $set->usage_count,
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function formatList(array $items): string
    {
        return implode(', ', $items);
    }

    private function toolGeneratePlatformVariants(array $args, AgentJob $job): string
    {
        $platformLimits = [
            'instagram' => ['chars' => 2200, 'hashtags' => 10, 'format' => 'Reel or Carousel caption'],
            'tiktok'    => ['chars' => 2200, 'hashtags' => 5,  'format' => 'Short video caption'],
            'linkedin'  => ['chars' => 3000, 'hashtags' => 5,  'format' => 'Professional post'],
            'facebook'  => ['chars' => 63206,'hashtags' => 3,  'format' => 'Feed post or Reel'],
            'twitter'   => ['chars' => 280,  'hashtags' => 2,  'format' => 'Tweet or thread start'],
            'youtube'   => ['chars' => 5000, 'hashtags' => 5,  'format' => 'Video description'],
        ];

        $targets   = $args['platforms'] ?? array_keys($platformLimits);
        $message   = $args['key_message'] ?? '';
        $tone      = $args['tone']        ?? 'exciting';
        $cta       = $args['cta']         ?? '';
        $type      = $args['campaign_type'] ?? 'general';

        $platformDetails = '';
        foreach ($targets as $p) {
            $spec = $platformLimits[$p] ?? ['chars' => 280, 'hashtags' => 3, 'format' => 'post'];
            $platformDetails .= "- {$p}: max {$spec['chars']} chars, {$spec['hashtags']} hashtags, format={$spec['format']}\n";
        }

        $prompt = <<<PROMPT
Generate platform-specific social media content variants.

Campaign type: {$type}
Key message: {$message}
Tone: {$tone}
CTA: {$cta}

Create a variant for each platform, strictly respecting its limits:
{$platformDetails}

For EACH platform return:
- caption: the post text (within char limit, including CTAs naturally)
- hashtags: JSON array of hashtag strings, e.g. ["#opening","#grandopening","#food"] (count within the limit, mix of niche/broad)
- char_count: actual character count of caption

Return as JSON object: { "instagram": { "caption": "...", "hashtags": ["#tag1","#tag2"], "char_count": N }, ... }
Return ONLY the JSON, no preamble.
PROMPT;

        try {
            $raw     = $this->openai->complete($prompt, model: 'gpt-4o-mini', maxTokens: 1500, temperature: 0.7);
            $decoded = json_decode(trim($raw), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Try to extract JSON from markdown code block
                preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $m);
                $decoded = isset($m[1]) ? json_decode($m[1], true) : null;
            }

            if (!$decoded) {
                return $this->toolResult(false, null, 'LLM returned invalid JSON for platform variants');
            }

            return $this->toolResult(true, ['variants' => $decoded]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolFindTrendingAudio(array $args): string
    {
        $platform    = $args['platform'];
        $contentType = $args['content_type'] ?? '';
        $mood        = $args['mood']         ?? '';

        $query = "trending audio sounds {$platform}";
        if ($contentType) $query .= " {$contentType}";
        if ($mood)        $query .= " {$mood} mood";

        try {
            $results = $this->knowledge->search($query, topK: 8, categories: ['content', 'general']);

            $audioResults = array_filter($results, fn ($r) =>
                str_contains(strtolower($r['content'] ?? ''), 'audio') ||
                str_contains(strtolower($r['content'] ?? ''), 'sound') ||
                str_contains(strtolower($r['content'] ?? ''), 'music') ||
                str_contains(strtolower($r['title']   ?? ''), 'audio')
            );

            if (empty($audioResults)) {
                return $this->toolResult(true, [
                    'results' => [],
                    'note'    => "No trending audio data found for {$platform}. Consider ingesting a trending audio knowledge base.",
                ]);
            }

            return $this->toolResult(true, [
                'platform' => $platform,
                'results'  => array_values(array_map(fn ($r) => [
                    'title'      => $r['title'],
                    'excerpt'    => mb_substr($r['content'], 0, 300),
                    'similarity' => $r['similarity'],
                ], $audioResults)),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }
}
