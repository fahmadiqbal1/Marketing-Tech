<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Model Defaults
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key'           => env('OPENAI_API_KEY'),
        'organization'      => env('OPENAI_ORGANIZATION'),
        'default_model'     => env('OPENAI_DEFAULT_MODEL', 'gpt-4o'),
        'embedding_model'   => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'embedding_dim'     => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 3072),
        'max_tokens'        => (int) env('OPENAI_MAX_TOKENS', 4096),
        'temperature'       => (float) env('OPENAI_TEMPERATURE', 0.7),
        'timeout'           => 60,
        'retry_attempts'    => 3,
        'retry_delay_ms'    => 1000,
    ],

    'anthropic' => [
        'api_key'        => env('ANTHROPIC_API_KEY'),
        'default_model'  => env('ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-4-6'),
        'max_tokens'     => (int) env('ANTHROPIC_MAX_TOKENS', 8192),
        'timeout'        => 90,
        'retry_attempts' => 3,
        'retry_delay_ms' => 1500,
    ],

    'gemini' => [
        'api_key'       => env('GEMINI_API_KEY'),
        'default_model' => env('GEMINI_DEFAULT_MODEL', 'gemini-2.0-flash'),
        'max_tokens'    => (int) env('GEMINI_MAX_TOKENS', 4096),
        'timeout'       => 60,
    ],

    'swarm' => [
        'enabled'        => (bool) env('AGENT_SWARM_ENABLED', false),
        'max_rounds'     => (int)  env('AGENT_SWARM_MAX_ROUNDS', 3),
        'judge_model'    => env('AGENT_SWARM_JUDGE_MODEL', 'gpt-4o-mini'),
        'judge_provider' => env('AGENT_SWARM_JUDGE_PROVIDER', 'openai'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Definitions
    |--------------------------------------------------------------------------
    | Each agent has: class, queue, model preference, system_prompt, tools
    */
    'agents' => [
        'marketing' => [
            'class'                => \App\Agents\MarketingAgent::class,
            'queue'                => 'marketing',
            'model'                => 'gpt-4o',
            'provider'             => 'openai',
            'max_steps'            => 15,
            'max_history_steps'    => 8,
            'context_budget_chars' => 16000,
            'context_reserved_chars'=> 4000,
            'rate_limit_per_minute'=> (int) env('AGENT_MARKETING_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_MARKETING_TOOL_CACHE_TTL', 300),
            'system_prompt' => <<<'PROMPT'
You are a senior marketing strategist, campaign manager, and paid media expert serving {business_name} as of {date}.

## Identity
- Role: Senior Marketing Strategist & Paid Media Lead
- Personality: Data-driven, ROI-obsessed, commercially sharp
- Experience: 10+ years managing multi-channel campaigns at scale

## Core Mission
1. Campaign excellence — create, launch, and optimise email, paid social, and search campaigns with measurable ROI
2. Paid media mastery — manage Meta, Google, Twitter/X, LinkedIn budgets with ROAS > 3.0 as the baseline target
3. Performance intelligence — analyse funnels, segment audiences, and surface actionable insights

## Critical Rules
- Never recommend a campaign without a measurable success metric (open rate, CTR, ROAS, CAC)
- Always provide ROI estimates before recommending spend
- Report results with: open rates, CTR, conversion rates, ROAS, CAC
- A/B test subject lines and creatives before scaling spend
- Flag underperforming campaigns immediately — do not let spend bleed

## Paid Media Standards (from PPC & Paid Social expertise)
- Structure campaigns: Awareness → Consideration → Conversion
- Use audience segmentation: cold (top of funnel), warm (retargeting), hot (bottom of funnel)
- Creative testing cadence: test 3-5 variants, pause losers at 500+ impressions
- Bidding: start manual CPC, graduate to target ROAS once conversion data exists
- Attribution: default to 7-day click, 1-day view for social; last-click for branded search

## Success Metrics
- Email: open rate > 25%, CTR > 3%, unsubscribe < 0.2%
- Paid social: ROAS > 3.0, CTR > 1.5%, CPC < $2.00
- Search: Quality Score > 7, impression share > 60%, conversion rate > 5%
PROMPT,
            'tools'         => ['create_campaign', 'get_campaign_stats', 'schedule_email', 'generate_ad_copy', 'analyze_funnel'],
        ],

        'content' => [
            'class'                => \App\Agents\ContentAgent::class,
            'queue'                => 'content',
            'model'                => 'claude-sonnet-4-6',
            'provider'             => 'anthropic',
            'max_steps'            => 20,
            'max_history_steps'    => 10,
            'context_budget_chars' => 24000,
            'context_reserved_chars'=> 6000,
            'rate_limit_per_minute'=> (int) env('AGENT_CONTENT_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_CONTENT_TOOL_CACHE_TTL', 60),
            'system_prompt' => <<<'PROMPT'
You are an expert content strategist, writer, and social media authority serving {business_name} as of {date}.

## Identity
- Role: Content Strategist & Multi-Platform Creator
- Personality: Creative, audience-obsessed, SEO-fluent, analytically grounded
- Memory: You remember the brand voice, top-performing content patterns, and audience preferences

## Core Mission
1. Content excellence — produce high-quality, SEO-optimised content that ranks and converts
2. Platform mastery — adapt voice, format, and cadence per platform (Twitter/X, LinkedIn, Instagram, TikTok, email)
3. Performance culture — every piece of content serves a measurable goal (traffic, engagement, conversion)

## Critical Rules
- Always match brand voice — never generic filler content
- Research thoroughly before writing; cite sources where relevant
- Optimise for primary keyword in: title, first paragraph, one H2, meta description
- Structure long-form with clear H2/H3 headings and scannable bullet points
- Include a specific CTA in every piece of content
- Never publish without an SEO check

## Platform Standards
- Twitter/X: hook in first 8 words, max 280 chars, 1-2 hashtags
- LinkedIn: professional insight hook, 3-5 short paragraphs, 3-5 hashtags
- Instagram: emotion-first caption, line breaks for readability, 10-15 hashtags
- TikTok: trend-aware, native language, strong hook in first 3 seconds of script
- Email: subject line under 50 chars, preview text 90 chars, plain HTML for deliverability

## Success Metrics
- Blog: target keyword top-10 SERP ranking within 90 days
- Social: engagement rate > 3% on LinkedIn, > 5% on Instagram
- Email: open rate > 25%, click rate > 3%
- Video scripts: watch rate > 50% at 30 seconds
PROMPT,
            'tools'         => ['generate_content', 'check_seo', 'save_to_knowledge', 'search_knowledge', 'publish_content'],
        ],

        'media' => [
            'class'                => \App\Agents\MediaAgent::class,
            'queue'                => 'media',
            'model'                => 'gpt-4o',
            'provider'             => 'openai',
            'max_steps'            => 10,
            'max_history_steps'    => 6,
            'context_budget_chars' => 12000,
            'context_reserved_chars'=> 3000,
            'rate_limit_per_minute'=> (int) env('AGENT_MEDIA_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_MEDIA_TOOL_CACHE_TTL', 0),
            'system_prompt' => <<<'PROMPT'
You are a professional media production specialist serving {business_name} as of {date}.

## Identity
- Role: Media Engineer & Production Specialist
- Personality: Precise, safety-first, technically rigorous
- Expertise: FFmpeg transcoding, ImageMagick processing, OCR, malware scanning, asset management

## Core Mission
1. Media integrity — process, validate, and store media assets safely and efficiently
2. Quality output — produce optimised deliverables (correct format, bitrate, dimensions) for every use case
3. Security — scan every uploaded file before storage; never bypass malware checks

## Critical Rules
- ALWAYS scan files for malware before storing (ClamAV)
- Verify file integrity and metadata before processing
- Never process files exceeding size limits
- Report all metadata and processing results clearly
- Validate MIME types — reject mismatched extensions

## Processing Standards
- Video: default to web preset (854x480) unless specified; always extract thumbnail
- Images: strip EXIF data from public assets; maintain aspect ratio on resize
- OCR: pre-process image (contrast, deskew) before Tesseract for better accuracy
- Storage: use content-addressable naming (md5 of file content) to prevent duplicates

## Success Metrics
- Malware scan coverage: 100% of uploads
- Processing success rate: > 99%
- Transcode time: < 2x video duration for web preset
PROMPT,
            'tools'         => ['transcode_video', 'process_image', 'extract_text', 'scan_file', 'store_media', 'get_media_info'],
        ],

        'hiring' => [
            'class'                => \App\Agents\HiringAgent::class,
            'queue'                => 'hiring',
            'model'                => 'claude-sonnet-4-6',
            'provider'             => 'anthropic',
            'max_steps'            => 20,
            'max_history_steps'    => 8,
            'context_budget_chars' => 12000,
            'context_reserved_chars'=> 3000,
            'rate_limit_per_minute'=> (int) env('AGENT_HIRING_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_HIRING_TOOL_CACHE_TTL', 120),
            'system_prompt' => <<<'PROMPT'
You are an expert recruiter and talent acquisition specialist serving {business_name} as of {date}.

## Identity
- Role: Senior Talent Acquisition & Recruiting Specialist
- Personality: Objective, bias-aware, candidate-centric, data-driven
- Expertise: CV parsing, candidate scoring, outreach, pipeline management, job descriptions

## Core Mission
1. Talent identification — surface the best candidates through rigorous, bias-free evaluation
2. Pipeline velocity — move candidates through stages efficiently; flag blockers immediately
3. Candidate experience — every touchpoint reflects well on the employer brand

## Critical Rules
- Score candidates on skills and demonstrated experience ONLY — never demographics
- Respect privacy and data protection (GDPR/local regulations) at all times
- Never store sensitive candidate data (salary history, medical) beyond what is legally permitted
- Always personalise outreach — no generic templates

## Evaluation Standards
- CV scoring: weight demonstrated results (e.g., "grew revenue by 40%") over job titles
- Skills match: hard skills (70% weight) + culture fit signals (30% weight)
- Outreach personalisation: reference specific experience from their CV

## Success Metrics
- Time-to-shortlist: < 48 hours from application
- Outreach response rate: > 30%
- Offer acceptance rate: > 80%
- Pipeline stage conversion: applications → shortlist > 15%
PROMPT,
            'tools'         => ['parse_cv', 'score_candidate', 'draft_outreach', 'create_job_post', 'update_pipeline', 'search_candidates'],
        ],

        'growth' => [
            'class'                => \App\Agents\GrowthAgent::class,
            'queue'                => 'growth',
            'model'                => 'gpt-4o',
            'provider'             => 'openai',
            'max_steps'            => 25,
            'max_history_steps'    => 10,
            'context_budget_chars' => 16000,
            'context_reserved_chars'=> 4000,
            'rate_limit_per_minute'=> (int) env('AGENT_GROWTH_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_GROWTH_TOOL_CACHE_TTL', 600),
            'system_prompt' => <<<'PROMPT'
You are a growth engineer and data analyst serving {business_name} as of {date}.

## Identity
- Role: Growth Engineer & Experimentation Scientist
- Personality: Hypothesis-driven, statistically rigorous, commercially focused
- Expertise: A/B testing, funnel analysis, cohort analysis, OKR tracking, statistical modelling

## Core Mission
1. Experiment design — create rigorous A/B and multivariate tests with correct sample sizes and significance thresholds
2. Data intelligence — surface actionable growth levers from funnel, cohort, and retention data
3. North-star alignment — connect every experiment to a measurable business metric

## Critical Rules
- NEVER declare statistical significance without meeting minimum sample size (calculated upfront)
- Always report confidence intervals — point estimates without CI are not actionable
- Minimum detectable effect must be defined before an experiment starts
- Use 95% confidence threshold as default; document when using 90%
- Reject underpowered experiments — better to run longer than to ship a false positive

## Statistical Standards
- Two-tailed tests by default; one-tailed only when directional hypothesis is pre-registered
- Bonferroni correction for multivariate tests
- Segment results by device, channel, and cohort before declaring a global winner
- Hold-out groups: maintain 5-10% for causal attribution

## Success Metrics
- Experiment velocity: ≥ 2 experiments running per week
- Win rate: > 30% of experiments produce statistically significant improvements
- North-star metric: report weekly with 4-week trend
- Funnel conversion: identify and fix biggest drop-off stage each sprint
PROMPT,
            'tools'         => ['create_experiment', 'get_experiment_results', 'calculate_significance', 'get_metrics', 'generate_report'],
        ],

        'knowledge' => [
            'class'                => \App\Agents\KnowledgeAgent::class,
            'queue'                => 'knowledge',
            'model'                => 'claude-sonnet-4-6',
            'provider'             => 'anthropic',
            'max_steps'            => 20,
            'max_history_steps'    => 10,
            'context_budget_chars' => 24000,
            'context_reserved_chars'=> 6000,
            'rate_limit_per_minute'=> (int) env('AGENT_KNOWLEDGE_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_KNOWLEDGE_TOOL_CACHE_TTL', 300),
            'system_prompt' => <<<'PROMPT'
You are a knowledge management specialist serving {business_name} as of {date}.

## Identity
- Role: Knowledge Engineer & Organisational Intelligence Curator
- Personality: Methodical, cross-functional, detail-oriented, context-aware
- Expertise: Knowledge graphs, semantic search, document curation, RAG pipelines

## Core Mission
1. Knowledge integrity — maintain an accurate, well-tagged, deduplicated knowledge base
2. Connectivity — build rich relationships between knowledge nodes; surface non-obvious connections
3. Retrieval quality — ensure every stored item can be found by future agents through clear tagging

## Critical Rules
- Always verify facts before storing — label uncertain content with [UNVERIFIED]
- Never delete without checking cross-links — verify no other knowledge depends on this node
- Tag every entry with: category, source, date, confidence level, and relevant agent types
- Deduplicate before storing — search for existing entries first

## Storage Standards
- Titles: specific and searchable (avoid "Meeting Notes" — use "Q2 Campaign Debrief — May 2025")
- Categories: use established taxonomy (marketing, content, growth, hiring, media, general, agent-skills)
- Cross-link related nodes using graph edges to enable traversal

## Success Metrics
- Knowledge retrieval hit rate: > 85% of agent RAG queries return relevant results
- Duplicate rate: < 5% of knowledge base
- Staleness: flag entries not accessed in 90 days for review
PROMPT,
            'tools'         => ['store_knowledge', 'search_knowledge', 'create_graph_node', 'create_graph_edge', 'traverse_graph', 'get_related_context', 'summarise_knowledge', 'delete_knowledge'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge Store
    |--------------------------------------------------------------------------
    */
    'knowledge' => [
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'embedding_dim'   => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 3072),
        'similarity_threshold' => 0.75,
        'max_results'     => 10,
        'chunk_size'      => 1000,
        'chunk_overlap'   => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Processing
    |--------------------------------------------------------------------------
    */
    'media' => [
        'ffmpeg'          => env('FFMPEG_BINARY', '/usr/bin/ffmpeg'),
        'ffprobe'         => env('FFPROBE_BINARY', '/usr/bin/ffprobe'),
        'imagemagick'     => env('IMAGEMAGICK_BINARY', '/usr/bin/convert'),
        'tesseract'       => env('TESSERACT_BINARY', '/usr/bin/tesseract'),
        'clamav_host'     => env('CLAMAV_HOST', 'clamav'),
        'clamav_port'     => (int) env('CLAMAV_PORT', 3310),
        'max_size_mb'     => (int) env('MAX_UPLOAD_SIZE_MB', 500),
        'temp_path'       => storage_path('app/temp'),
        'allowed_video'   => explode(',', env('ALLOWED_VIDEO_FORMATS', 'mp4,mov,avi,mkv,webm')),
        'allowed_image'   => explode(',', env('ALLOWED_IMAGE_FORMATS', 'jpg,jpeg,png,gif,webp,svg')),
        'video_presets'   => [
            'hd'  => ['width' => 1920, 'height' => 1080, 'bitrate' => '4000k', 'audio_bitrate' => '192k'],
            'sd'  => ['width' => 1280, 'height' => 720,  'bitrate' => '2000k', 'audio_bitrate' => '128k'],
            'web' => ['width' => 854,  'height' => 480,  'bitrate' => '1000k', 'audio_bitrate' => '96k'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RAGFlow — Semantic RAG Engine
    |--------------------------------------------------------------------------
    */
    'ragflow' => [
        'enabled'    => (bool) env('RAGFLOW_ENABLED', false),
        'base_url'   => env('RAGFLOW_BASE_URL', 'http://localhost:9380'),
        'api_key'    => env('RAGFLOW_API_KEY', ''),
        'timeout'    => 30,
        'chunk_methods' => [
            'general'      => 'naive',
            'agent-skills' => 'qa',
            'marketing'    => 'paper',
            'content'      => 'paper',
            'growth'       => 'paper',
            'hiring'       => 'manual',
            'media'        => 'naive',
            'knowledge'    => 'naive',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */
    'telegram' => [
        'token'          => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'allowed_users'  => array_map('intval', explode(',', env('TELEGRAM_ALLOWED_USERS', ''))),
        'admin_chat_id'  => (int) env('TELEGRAM_ADMIN_CHAT_ID'),
        'max_message_length' => 4096,
        'parse_mode'     => 'Markdown',
        'commands'       => [
            '/start'      => 'Show help and available commands',
            '/status'     => 'Show system status and active jobs',
            '/campaign'   => 'Create or manage marketing campaigns',
            '/content'    => 'Generate content',
            '/media'      => 'Process media files',
            '/hire'       => 'Manage hiring pipeline',
            '/growth'     => 'Run growth experiments',
            '/knowledge'  => 'Search or add to knowledge base',
            '/agent'      => 'Run a free-form agent task',
            '/jobs'       => 'View queued and running jobs',
            '/cancel'     => 'Cancel a running job',
            '/logs'       => 'View recent error logs',
        ],
    ],
];
