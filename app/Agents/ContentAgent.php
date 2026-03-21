<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Models\ContentItem;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\AI\OpenAIService;
use App\Services\ApiCredentialService;
use App\Services\CampaignContextService;
use App\Services\IterationEngineService;
use App\Services\Knowledge\VectorStoreService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Log;

class ContentAgent extends BaseAgent
{
    protected string $agentType = 'content';

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
            'generate_content'  => $this->toolGenerateContent($args, $job),
            'check_seo'         => $this->toolCheckSEO($args),
            'save_to_knowledge' => $this->toolSaveToKnowledge($args),
            'search_knowledge'  => $this->toolSearchKnowledge($args),
            'publish_content'   => $this->toolPublishContent($args),
            'repurpose_content' => $this->toolRepurposeContent($args, $job),
            'analyse_content'   => $this->toolAnalyseContent($args),
            default             => $this->toolResult(false, null, "Unknown tool: {$name}"),
        };
    }

    protected function getToolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'generate_content',
                    'description' => 'Generate high-quality content for a given platform and purpose',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'type'        => ['type' => 'string', 'enum' => ['blog_post', 'social_twitter', 'social_linkedin', 'social_instagram', 'email_newsletter', 'ad_copy', 'product_description', 'video_script', 'press_release']],
                            'topic'       => ['type' => 'string'],
                            'tone'        => ['type' => 'string', 'enum' => ['professional', 'casual', 'authoritative', 'friendly', 'urgent', 'inspiring']],
                            'keywords'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Target SEO keywords'],
                            'word_count'  => ['type' => 'integer', 'description' => 'Target word count (0 = platform default)'],
                            'audience'    => ['type' => 'string', 'description' => 'Target audience description'],
                            'brand_voice' => ['type' => 'string', 'description' => 'Brand voice notes or style guidelines'],
                            'cta'         => ['type' => 'string', 'description' => 'Call to action to include'],
                        ],
                        'required'   => ['type', 'topic'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'check_seo',
                    'description' => 'Analyse content for SEO quality and provide improvement suggestions',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'content'     => ['type' => 'string'],
                            'target_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'url_slug'    => ['type' => 'string'],
                        ],
                        'required'   => ['content'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'save_to_knowledge',
                    'description' => 'Save content or facts to the long-term knowledge base for future reference',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'title'    => ['type' => 'string'],
                            'content'  => ['type' => 'string'],
                            'tags'     => ['type' => 'array', 'items' => ['type' => 'string']],
                            'category' => ['type' => 'string'],
                        ],
                        'required'   => ['title', 'content'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_knowledge',
                    'description' => 'Search the knowledge base for relevant content, brand guidelines, or facts',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query'  => ['type' => 'string'],
                            'limit'  => ['type' => 'integer'],
                            'category' => ['type' => 'string'],
                        ],
                        'required'   => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'publish_content',
                    'description' => 'Save finalised content to the content library with metadata',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'title'       => ['type' => 'string'],
                            'body'        => ['type' => 'string'],
                            'type'        => ['type' => 'string'],
                            'platform'    => ['type' => 'string'],
                            'status'      => ['type' => 'string', 'enum' => ['draft', 'ready', 'scheduled', 'published']],
                            'tags'        => ['type' => 'array', 'items' => ['type' => 'string']],
                            'scheduled_at' => ['type' => 'string'],
                        ],
                        'required'   => ['title', 'body', 'type'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'repurpose_content',
                    'description' => 'Repurpose existing content into a different format (e.g. blog post → Twitter thread)',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'source_content' => ['type' => 'string'],
                            'source_type'    => ['type' => 'string'],
                            'target_type'    => ['type' => 'string', 'enum' => ['social_twitter', 'social_linkedin', 'email_newsletter', 'video_script', 'ad_copy']],
                        ],
                        'required'   => ['source_content', 'target_type'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'analyse_content',
                    'description' => 'Analyse content for readability, tone, engagement prediction',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'content' => ['type' => 'string'],
                            'platform' => ['type' => 'string'],
                        ],
                        'required'   => ['content'],
                    ],
                ],
            ],
        ];
    }

    // ─── Tool Implementations ─────────────────────────────────────

    private function toolGenerateContent(array $args, AgentJob $job): string
    {
        $platformDefaults = [
            'blog_post'           => 1200,
            'social_twitter'      => 280,
            'social_linkedin'     => 1300,
            'social_instagram'    => 400,
            'email_newsletter'    => 600,
            'ad_copy'             => 150,
            'product_description' => 300,
            'video_script'        => 800,
            'press_release'       => 500,
        ];

        $wordCount  = $args['word_count'] ?: ($platformDefaults[$args['type']] ?? 500);
        $keywords   = ! empty($args['keywords']) ? 'Target SEO keywords: ' . implode(', ', $args['keywords']) : '';
        $audience   = $args['audience']    ?? 'general professional audience';
        $tone       = $args['tone']        ?? 'professional';
        $brandVoice = $args['brand_voice'] ?? '';
        $cta        = $args['cta']         ? "Include a natural call-to-action: {$args['cta']}" : '';

        $typeInstructions = match ($args['type']) {
            'social_twitter'   => "Write a Twitter/X thread. Each tweet max 280 chars. Number each tweet. Make it engaging and shareable.",
            'social_linkedin'  => "Write a LinkedIn post. Use line breaks, bold sparingly. Include a hook opening line.",
            'social_instagram' => "Write an Instagram caption. Engaging, with relevant hashtags at end.",
            'blog_post'        => "Write a full blog post with H2/H3 headings, introduction, body sections, and conclusion.",
            'email_newsletter' => "Write an email newsletter with subject line, preview text, and body.",
            'video_script'     => "Write a video script with scene directions, on-screen text notes, and spoken dialogue.",
            'press_release'    => "Write a press release in AP style with dateline, lead paragraph, body, and boilerplate.",
            default            => "Write professional {$args['type']} content.",
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
                // Store all three variations
                foreach (['A', 'B', 'C'] as $label) {
                    $v = $parsed[$label];
                    $this->iterationEngine->storeVariation(
                        agentJobId: $job->id,
                        label:      $label,
                        content:    $v['content'] ?? '',
                        metadata:   [
                            'tone'       => $v['tone']       ?? null,
                            'hook_type'  => $v['hook_type']  ?? null,
                            'structure'  => $v['structure']  ?? null,
                            'word_count' => str_word_count($v['content'] ?? ''),
                        ],
                    );
                }

                $primaryContent = $parsed['A']['content'];
            } else {
                // Fallback: treat raw response as single piece of content
                Log::warning('ContentAgent: variation JSON parse failed, using raw response as variation A', [
                    'job_id' => $job->id,
                    'raw'    => substr($raw, 0, 200),
                ]);
                $primaryContent = $raw;
                $this->iterationEngine->storeVariation(
                    agentJobId: $job->id,
                    label:      'A',
                    content:    $raw,
                    metadata:   [
                        'tone'       => $tone,
                        'word_count' => str_word_count($raw),
                    ],
                );
            }

            return $this->toolResult(true, [
                'content'         => $primaryContent,
                'type'            => $args['type'],
                'word_count'      => str_word_count($primaryContent),
                'char_count'      => strlen($primaryContent),
                'variations_stored' => is_array($parsed) && isset($parsed['A']) ? 3 : 1,
            ]);

        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCheckSEO(array $args): string
    {
        $content  = $args['content'];
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
            $raw    = $this->openai->complete($prompt, 'gpt-4o-mini', 1024, 0.1);
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
                title:    $args['title'],
                content:  $args['content'],
                tags:     $args['tags']     ?? [],
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
                query:    $args['query'],
                topK:     $args['limit']    ?? 5,
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
                'title'        => $args['title'],
                'body'         => $args['body'],
                'type'         => $args['type'],
                'platform'     => $args['platform']     ?? null,
                'status'       => $args['status']       ?? 'draft',
                'tags'         => $args['tags']          ?? [],
                'scheduled_at' => isset($args['scheduled_at']) ? \Carbon\Carbon::parse($args['scheduled_at']) : null,
                'word_count'   => str_word_count($args['body']),
            ]);

            return $this->toolResult(true, ['content_id' => $item->id, 'status' => $item->status]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolRepurposeContent(array $args, AgentJob $job): string
    {
        $result = $this->toolGenerateContent([
            'type'           => $args['target_type'],
            'topic'          => "Repurpose this content:\n\n{$args['source_content']}",
            'tone'           => 'professional',
            'word_count'     => 0,
        ], $job);

        return $result;
    }

    private function toolAnalyseContent(array $args): string
    {
        $prompt = <<<PROMPT
Analyse this content for quality. Return ONLY JSON.

Content: {$args['content']}
Platform: {$args['platform'] ?? 'general'}

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
            $raw    = $this->openai->complete($prompt, 'gpt-4o-mini', 1024, 0.1);
            $result = json_decode($raw, true);
            return $this->toolResult(true, $result ?? ['raw' => $raw]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function formatList(array $items): string
    {
        return implode(', ', $items);
    }
}
