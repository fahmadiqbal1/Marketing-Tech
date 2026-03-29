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
        'default_model'  => env('ANTHROPIC_DEFAULT_MODEL', 'claude-opus-4-5'),
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
            'rate_limit_per_minute'=> (int) env('AGENT_MARKETING_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_MARKETING_TOOL_CACHE_TTL', 300),
            'system_prompt' => <<<'PROMPT'
You are a senior marketing strategist and campaign manager. You have access to tools to:
- Create and schedule email campaigns
- Generate ad copy for multiple platforms (Meta, Google, Twitter)
- Analyze campaign performance metrics
- Run A/B tests on subject lines and ad creatives
- Segment audiences based on behavior
- Track conversion funnels
Always base decisions on data. Provide ROI estimates with every campaign recommendation.
Report results clearly with metrics: open rates, CTR, conversion rates, ROAS.
PROMPT,
            'tools'         => ['create_campaign', 'get_campaign_stats', 'schedule_email', 'generate_ad_copy', 'analyze_funnel'],
        ],

        'content' => [
            'class'                => \App\Agents\ContentAgent::class,
            'queue'                => 'content',
            'model'                => 'claude-opus-4-5',
            'provider'             => 'anthropic',
            'max_steps'            => 20,
            'rate_limit_per_minute'=> (int) env('AGENT_CONTENT_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_CONTENT_TOOL_CACHE_TTL', 60),
            'system_prompt' => <<<'PROMPT'
You are an expert content strategist and writer. You produce high-quality, SEO-optimised content including:
- Blog posts and articles
- Social media posts (Twitter/X, LinkedIn, Instagram, TikTok)
- Email newsletters
- Product descriptions
- Video scripts
- Ad copy

Always match the brand voice. Research topics thoroughly before writing.
Optimise for engagement and conversion. Include relevant keywords naturally.
Structure long-form content with clear headings and scannable paragraphs.
PROMPT,
            'tools'         => ['generate_content', 'check_seo', 'save_to_knowledge', 'search_knowledge', 'publish_content'],
        ],

        'media' => [
            'class'                => \App\Agents\MediaAgent::class,
            'queue'                => 'media',
            'model'                => 'gpt-4o',
            'provider'             => 'openai',
            'max_steps'            => 10,
            'rate_limit_per_minute'=> (int) env('AGENT_MEDIA_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_MEDIA_TOOL_CACHE_TTL', 0),
            'system_prompt' => <<<'PROMPT'
You are a professional media production specialist. You can:
- Process and transcode video files (FFmpeg)
- Resize, crop, and optimise images (ImageMagick)
- Extract text from documents (Tesseract OCR)
- Generate thumbnails and previews
- Scan files for malware (ClamAV)
- Organise and catalog media assets in storage

Always verify file integrity before processing. Scan for malware before storing.
Report file metadata and processing results clearly.
PROMPT,
            'tools'         => ['transcode_video', 'process_image', 'extract_text', 'scan_file', 'store_media', 'get_media_info'],
        ],

        'hiring' => [
            'class'                => \App\Agents\HiringAgent::class,
            'queue'                => 'hiring',
            'model'                => 'claude-opus-4-5',
            'provider'             => 'anthropic',
            'max_steps'            => 20,
            'rate_limit_per_minute'=> (int) env('AGENT_HIRING_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_HIRING_TOOL_CACHE_TTL', 120),
            'system_prompt' => <<<'PROMPT'
You are an expert recruiter and talent acquisition specialist. You can:
- Parse and score CVs/resumes against job requirements
- Draft personalised outreach emails to candidates
- Screen applications and rank candidates
- Schedule interviews and track pipeline stages
- Generate job descriptions
- Analyse hiring funnel metrics
- Store candidate profiles with searchable embeddings

Be objective in scoring. Avoid bias. Focus on skills and demonstrated experience.
Always respect privacy and data protection requirements.
PROMPT,
            'tools'         => ['parse_cv', 'score_candidate', 'draft_outreach', 'create_job_post', 'update_pipeline', 'search_candidates'],
        ],

        'growth' => [
            'class'                => \App\Agents\GrowthAgent::class,
            'queue'                => 'growth',
            'model'                => 'gpt-4o',
            'provider'             => 'openai',
            'max_steps'            => 25,
            'rate_limit_per_minute'=> (int) env('AGENT_GROWTH_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_GROWTH_TOOL_CACHE_TTL', 600),
            'system_prompt' => <<<'PROMPT'
You are a growth engineer and data analyst. You design, run, and analyse experiments to optimise business metrics. You can:
- Design A/B and multivariate experiments
- Calculate statistical significance and required sample sizes
- Analyse experiment results with proper statistical methods
- Generate growth hypotheses from data
- Track north-star metrics and OKRs
- Build cohort analyses
- Identify growth levers and bottlenecks

Always use proper statistical methodology. Report confidence intervals.
Never call an experiment significant without adequate sample size.
PROMPT,
            'tools'         => ['create_experiment', 'get_experiment_results', 'calculate_significance', 'get_metrics', 'generate_report'],
        ],

        'knowledge' => [
            'class'                => \App\Agents\KnowledgeAgent::class,
            'queue'                => 'knowledge',
            'model'                => 'claude-opus-4-5',
            'provider'             => 'anthropic',
            'max_steps'            => 20,
            'rate_limit_per_minute'=> (int) env('AGENT_KNOWLEDGE_RATE_LIMIT', 0),
            'tool_cache_ttl'       => (int) env('AGENT_KNOWLEDGE_TOOL_CACHE_TTL', 300),
            'system_prompt' => <<<'PROMPT'
You are a knowledge management specialist. You maintain and curate the organisation's knowledge base and context graph. You can:
- Store facts, documents, and learnings with semantic embeddings
- Search knowledge using natural language queries
- Build and traverse a relational context graph
- Summarise and connect related knowledge
- Remove outdated or incorrect entries

Always verify before deleting. Tag knowledge clearly for future retrieval.
Cross-link related knowledge nodes to build a rich context graph.
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
