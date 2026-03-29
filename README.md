# Marketing-Tech — Autonomous Business Operations Platform

A production-ready Laravel 11 platform that automates marketing, content creation, hiring, growth analytics, and social media publishing through a fleet of AI agents — all controllable from a Telegram bot.

---

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [AI Agents](#ai-agents)
- [Social Media Layer](#social-media-layer)
- [Telegram Bot](#telegram-bot)
- [Campaign Pipeline](#campaign-pipeline)
- [Hiring Pipeline](#hiring-pipeline)
- [Knowledge Base & RAG](#knowledge-base--rag)
- [Tech Stack](#tech-stack)
- [Infrastructure](#infrastructure)
- [Installation](#installation)
- [Configuration](#configuration)
- [Running the Application](#running-the-application)
- [Queue Workers](#queue-workers)
- [Feature Flags](#feature-flags)
- [Testing](#testing)
- [Project Structure](#project-structure)

---

## Overview

Marketing-Tech is an autonomous business operations platform built on Laravel 11. You send a plain-English instruction to a Telegram bot — or type it into the dashboard — and specialised AI agents execute it end-to-end: writing copy, generating images, publishing to 6 social platforms, screening job candidates, running growth experiments, and storing institutional knowledge in a vector database for future retrieval.

### Key capabilities

| Domain | What it automates |
|--------|-------------------|
| **Campaigns** | End-to-end social media campaigns — brief analysis → multi-platform content → approval → scheduled publishing |
| **Content** | Blog posts, social captions, ad copy, video scripts, email newsletters |
| **Media** | Video transcoding, image resizing, background removal, OCR, voiceover generation, caption burning |
| **Hiring** | CV scoring, job description generation, candidate outreach, pipeline tracking |
| **Growth** | A/B experiments, funnel analysis, weekly reports, trend monitoring |
| **Knowledge** | RAG-powered document store — ingest GitHub repos, store brand guidelines, retrieve via semantic search |
| **Social Publishing** | Auto-post to Instagram, Twitter/X, Facebook, LinkedIn, TikTok, YouTube at optimal times |

---

## Architecture

```
Telegram Bot (HMAC-verified webhook)
        │
        ▼
TelegramBotService
 ├── CommandHandler          (/campaign, /hire, /content, /media, /growth, /knowledge, /agent)
 ├── CampaignApprovalService (approve / regen / edit inline buttons)
 └── HiringApprovalService   (approve / regen / edit / cancel inline buttons)
        │
        ▼
AgentOrchestrator
 └── Dispatches to Laravel Horizon queued agents
        │
        ├── MarketingAgent   → campaign strategy, email, A/B tests
        ├── ContentAgent     → copywriting, hashtag strategy, platform variants
        ├── MediaAgent       → FFmpeg, ImageMagick, Tesseract OCR, DALL-E 3
        ├── HiringAgent      → CV scoring, JD generation, outreach
        ├── GrowthAgent      → experiments, funnel analysis, trend processing
        └── KnowledgeAgent   → vector store ingest, semantic search, RAG context
                │
                ▼
         BaseAgent (shared loop)
          ├── buildPromptContext()   — RAG from knowledge_base + pgvector
          ├── AI tool-call loop      — OpenAI GPT-4o / Claude Haiku / DALL-E 3
          ├── IterationEngineService — circuit breaker, winner selection, feedback loop
          └── notifyUser()           — Telegram result delivery
```

### Feedback Loop (IterationEngineService)

Every tool call is tracked for reliability. After 5 consecutive failures a circuit breaker trips for 120 seconds. Winners are selected based on historical performance patterns. The system continuously improves its tool selection without manual intervention.

---

## AI Agents

All agents extend `BaseAgent` and are dispatched as Laravel queue jobs via `RunAgentJob`.

### MarketingAgent
- Campaign strategy and copywriting
- Email campaign generation and A/B subject line testing
- Campaign performance analysis
- Tools: `analyse_campaign`, `generate_email_campaign`, `create_ab_test`, `send_email_campaign`

### ContentAgent
- Blog posts, social captions, video scripts, ad copy
- Multi-platform variant generation (6 platforms in one call)
- Hashtag strategy with niche/medium/broad mix
- Cross-platform content adaptation
- Content calendar management
- Tools: `generate_content`, `generate_platform_variants`, `hashtag_strategy`, `select_hashtags`, `create_content_calendar`, `publish_content`, `repurpose_content`

### MediaAgent
- Video transcoding to web-optimised MP4 (FFmpeg)
- Image resizing and background removal (ImageMagick)
- OCR text extraction from PDFs and images (Tesseract)
- AI image generation (DALL-E 3)
- Platform-specific image variant creation (1:1, 9:16, 16:9, 4:5)
- Voiceover synthesis (OpenAI TTS)
- Caption burning and audio merging
- Slideshow creation from image sequences
- Tools: `process_video`, `process_image`, `extract_text_ocr`, `generate_image`, `create_platform_variants`, `remove_background`, `generate_voiceover`, `add_captions`, `add_audio_to_video`, `generate_video_from_images`

### HiringAgent
- CV/resume scoring against job requirements
- Job description generation
- Candidate outreach email drafting
- Pipeline status reporting
- Tools: `score_cv`, `generate_jd`, `draft_outreach`, `pipeline_summary`

### GrowthAgent
- Growth experiment creation and tracking
- Conversion funnel analysis
- Trend monitoring and automated content triggers
- Weekly growth report generation
- Tools: `create_experiment`, `analyse_funnel`, `process_trends`, `growth_report`

### KnowledgeAgent
- Semantic document storage and retrieval
- GitHub repository ingestion (auto-categorised into 6 domains)
- Smart knowledge merge (Jaccard overlap + LLM synthesis — no blind overwrites)
- Agent skill manifest management
- Tools: `store_knowledge`, `search_knowledge`, `ingest_github_repo`, `list_knowledge`

---

## Social Media Layer

Full OAuth 2.0 integration for 6 platforms. All tokens are encrypted at rest.

| Platform | API | Auth | Key features |
|----------|-----|------|--------------|
| **Instagram** | Graph API v19 | OAuth 2.0 | Reels, carousels, stories |
| **Twitter/X** | API v2 | OAuth 2.0 PKCE | Threads, tweets |
| **LinkedIn** | ugcPosts v2 | OAuth 2.0 | Articles, posts |
| **Facebook** | Graph API v19 | OAuth 2.0 | Pages, Reels, multi-page support |
| **TikTok** | Content Posting API v2 | OAuth 2.0 PKCE | Async publish with status polling |
| **YouTube** | Data API v3 | OAuth 2.0 | Resumable upload with session recovery |

### Auto-publishing
- `DispatchScheduledPosts` runs every minute via `supervisor-social` (3 processes)
- Moderation gate: only posts with `moderation_status IN (approved, auto_approved)` go live
- Scheduling conflict detection: ±15 min check on create/update
- `ensurePublicUrl()` converts local paths to S3 temporary URLs before upload
- Feature flags: `SOCIAL_AUTO_POST_ENABLED`, `SOCIAL_DRY_RUN`

### Hashtag Library
Platform-specific hashtag sets stored in `hashtag_sets` table. ContentAgent selects the best matching set via `select_hashtags` tool. Hashtags stored as JSONB arrays for native `collect()` / `array_map()` compatibility.

---

## Telegram Bot

HMAC-verified webhook at `POST /webhook/telegram`.

### Commands

| Command | Description |
|---------|-------------|
| `/start` | Welcome message and platform overview |
| `/help` | List all available commands |
| `/status` | Queue health, Redis/Postgres status, job counts |
| `/jobs` | Active jobs with cancel buttons |
| `/campaign <instruction>` | Create or analyse a marketing campaign |
| `/content <instruction>` | Generate any type of content |
| `/media <instruction>` | Process media files |
| `/hire <instruction>` | Hiring pipeline actions |
| `/growth <instruction>` | Growth experiments and analytics |
| `/knowledge <query>` | Store or retrieve knowledge base entries |
| `/agent <task>` | Direct free-form agent dispatch |
| `/cancel <job_id>` | Cancel a running job |
| `/logs` | Recent errors from agent jobs |

### Smart routing
- Voice notes → Whisper transcription → hiring or campaign pipeline (keyword detection, no API call)
- Photos/videos with captions → campaign pipeline
- Free-form text → hiring intent check → campaign/agent pipeline
- Inline approval buttons for campaigns and job posts

---

## Campaign Pipeline

Fully autonomous campaign creation triggered from Telegram.

```
User sends: "Launch a summer sale campaign for our new product line"
                │
                ▼
CampaignIntentAnalyzer (gpt-4o-mini, ~200ms)
  → CampaignBrief: {keyMessage, targetPlatforms, tone, campaignType, cta, ...}
                │
                ▼
AutonomousCampaignService (parallel fan-out)
  ├── ContentAgent job  → captions + hashtag arrays per platform
  ├── MarketingAgent job → campaign strategy + optimal post times
  └── GrowthAgent job   → growth tips + scheduling recommendations
                │
                ▼
CampaignPreviewBuilder
  → Structured preview: {headline, summary, variants per platform, schedule, growth_tip}
                │
                ▼
Redis (24h TTL) + Telegram preview with 4 buttons:
  [✅ Approve & Schedule] [🔄 Regenerate] [✏️ Edit Brief] [❌ Cancel]
                │
                ▼
On approval: ContentCalendar entries created per platform
  status=scheduled, moderation_status=auto_approved
  → DispatchScheduledPosts picks up at optimal time
```

---

## Hiring Pipeline

Autonomous job post generation triggered from Telegram.

```
User sends: "We need to hire a senior cardiologist for our Karachi clinic"
                │
                ▼
ProcessHiringRequest (agents queue, 2 retries, 180s timeout)
  Step 1: gpt-4o-mini → structured brief
          {title, department, level, location, employment_type, requirements}
  Step 2: claude-haiku → 400-600 word job description
  Step 3: Redis store (24h TTL)
  Step 4: Telegram preview + 4 buttons:
          [✅ Publish Job Post] [🔄 Regenerate] [✏️ Edit Details] [❌ Cancel]
                │
                ▼
On approval: JobPosting::create(status='active')
  + SystemEvent emitted → 'job_posting_created'
```

Hiring intent is detected via keyword matching (`hire`, `recruit`, `vacancy`, `looking for a`, etc.) with no API call overhead.

---

## Knowledge Base & RAG

`knowledge_base` table with `pgvector` extension for cosine similarity search.

### Storage
- Embeddings generated via OpenAI `text-embedding-3-small` (1536 dimensions)
- Async embedding via `EmbedKnowledgeChunk` job on `low` queue
- Smart merge on re-ingest: Jaccard word-set overlap triage → `replace` / `update` / `merged` strategies via `KnowledgeMergeService`
- 50k cap with soft-delete pruning (preserves training data)

### Retrieval
```php
KnowledgeBase::semanticSearch($embedding, $limit, $threshold = 0.65, $categories = []);
// Uses: embedding <=> ?::vector (cosine distance, PDO binding — SQL injection safe)
```

### GitHub ingestion
`IngestGitHubRepo` job auto-categorises repository files into 6 domains:
`marketing`, `content`, `media`, `hiring`, `growth`, `knowledge`

Re-ingestion triggers smart merge instead of blind overwrite, then dispatches `RefreshAgentSkills` to update agent manifests.

---

## Tech Stack

| Component | Version | Notes |
|-----------|---------|-------|
| PHP | 8.2+ | |
| Laravel | 11.x | |
| PostgreSQL | 15+ | pgvector extension required |
| Redis | 7.x | Cache + Horizon queues |
| Laravel Horizon | Latest | Queue dashboard at `/horizon` |
| Alpine.js | 3.x | CDN, reactive UI |
| Tailwind CSS | 3.x | Dark slate-950 palette |
| Chart.js | 4.4.8 | Pinned (4.4.0 has fullSize crash) |
| OpenAI | GPT-4o, GPT-4o-mini, DALL-E 3, Whisper, TTS | |
| Anthropic | Claude Haiku 4.5 | Job descriptions, content generation |
| FFmpeg | System | Video processing |
| ImageMagick | System | Image processing |
| Tesseract | System | OCR |
| MinIO / S3 | — | Media storage |

---

## Infrastructure

### Docker Compose services
- `app` — Laravel PHP-FPM (port 8080)
- `postgres` — PostgreSQL 16 + pgvector
- `redis` — Redis 7
- `horizon` — Laravel Horizon supervisor

### Horizon supervisors

| Supervisor | Queue(s) | Processes | Purpose |
|------------|---------|-----------|---------|
| `default` | default | 2 | General jobs |
| `marketing` | marketing | 2 | MarketingAgent jobs |
| `content` | content | 2 | ContentAgent jobs |
| `media` | media | 2 | MediaAgent jobs (FFmpeg, ImageMagick) |
| `hiring` | hiring | 2 | HiringAgent jobs |
| `growth` | growth | 2 | GrowthAgent + ProcessTrends |
| `knowledge` | knowledge | 2 | KnowledgeAgent + embedding |
| `agents` | agents | 3 | Campaign/Hiring pipelines |
| `social` | social | 3 | DispatchScheduledPosts (every minute) |
| `low` | low | 1 | EmbedKnowledgeChunk, RefreshAgentSkills |

---

## Installation

### Prerequisites
- Docker + Docker Compose
- Git

### Steps

```bash
# 1. Clone
git clone https://github.com/fahmadiqbal1/Marketing-Tech.git
cd Marketing-Tech

# 2. Copy environment file
cp .env.example .env

# 3. Fill in required credentials (see Configuration section)
nano .env

# 4. Start containers
docker compose up -d

# 5. Install PHP dependencies
docker exec marketing-tech-app-1 composer install --no-dev --optimize-autoloader

# 6. Generate app key
docker exec marketing-tech-app-1 php artisan key:generate

# 7. Run migrations
docker exec marketing-tech-app-1 php artisan migrate --force

# 8. Seed agent skills and knowledge base
docker exec marketing-tech-app-1 php artisan db:seed --class=AgentSkillsSeeder
docker exec marketing-tech-app-1 php artisan db:seed --class=AgentKnowledgeSeeder

# 9. Process initial embeddings (low queue)
docker exec marketing-tech-app-1 php artisan horizon

# 10. Register Telegram webhook
docker exec marketing-tech-app-1 php artisan telegram:register-webhook
```

---

## Configuration

All configuration is via environment variables. Copy `.env.example` to `.env` and fill in:

### Required

```env
# Application
APP_KEY=                        # Generated by artisan key:generate
APP_URL=https://yourdomain.com

# Database
DB_HOST=postgres
DB_DATABASE=marketing_tech
DB_USERNAME=postgres
DB_PASSWORD=your_password

# Redis
REDIS_HOST=redis

# AI Providers
OPENAI_API_KEY=sk-...           # GPT-4o, DALL-E 3, Whisper, TTS
ANTHROPIC_API_KEY=sk-ant-...    # Claude Haiku for hiring/content

# Telegram
TELEGRAM_BOT_TOKEN=...          # @BotFather token
TELEGRAM_WEBHOOK_SECRET=...     # Random 32+ char string (HMAC verification)
TELEGRAM_ALLOWED_USERS=         # Comma-separated Telegram user IDs (empty = allow all)
```

### Social Platform OAuth

```env
# Instagram (Graph API v19)
INSTAGRAM_CLIENT_ID=
INSTAGRAM_CLIENT_SECRET=

# Twitter/X (OAuth 2.0 PKCE)
TWITTER_CLIENT_ID=
TWITTER_CLIENT_SECRET=

# LinkedIn (ugcPosts v2)
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=

# Facebook (Graph API v19)
FACEBOOK_APP_ID=
FACEBOOK_APP_SECRET=

# TikTok (Content Posting API v2)
TIKTOK_CLIENT_KEY=
TIKTOK_CLIENT_SECRET=

# YouTube (Data API v3)
YOUTUBE_CLIENT_ID=
YOUTUBE_CLIENT_SECRET=
```

### Media Storage

```env
# MinIO / S3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=marketing-tech
AWS_ENDPOINT=http://minio:9000   # For MinIO
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Feature Flags

```env
SOCIAL_AUTO_POST_ENABLED=false   # Set true to enable live publishing
SOCIAL_DRY_RUN=false             # Set true to simulate without posting
```

---

## Running the Application

```bash
# Start all services
docker compose up -d

# Start Horizon queue manager (in foreground for monitoring)
docker exec marketing-tech-app-1 php artisan horizon

# Or run a specific queue
docker exec marketing-tech-app-1 php artisan queue:work --queue=agents,social,marketing,content,media,hiring,growth,knowledge,low

# Clear all queues (use with caution)
docker exec marketing-tech-app-1 php artisan horizon:clear
```

### Dashboard
Navigate to `http://yourdomain.com/dashboard` (Basic Auth protected).

### Horizon
Navigate to `http://yourdomain.com/horizon` to monitor all queues and jobs.

### Telegram
Message your bot or use the inline commands once the webhook is registered.

---

## Queue Workers

The platform uses dedicated queues per domain to prevent slow media jobs from blocking fast content jobs:

```
agents    → Campaign and hiring pipeline orchestration (high priority)
social    → DispatchScheduledPosts — runs every minute, needs 3 processes
marketing → MarketingAgent jobs
content   → ContentAgent jobs
media     → MediaAgent jobs (FFmpeg can be slow)
hiring    → HiringAgent jobs
growth    → GrowthAgent + ProcessTrends
knowledge → KnowledgeAgent + IngestGitHubRepo
low       → EmbedKnowledgeChunk, RefreshAgentSkills (background, non-urgent)
```

---

## Feature Flags

| Flag | Default | Effect |
|------|---------|--------|
| `SOCIAL_AUTO_POST_ENABLED` | `false` | Enables live publishing to social platforms |
| `SOCIAL_DRY_RUN` | `false` | Runs through publish logic but skips actual API calls |

With both flags off, the platform generates content and schedules it but does not post until you explicitly enable `SOCIAL_AUTO_POST_ENABLED`.

---

## Testing

```bash
# Run full test suite (must run inside Docker — requires Postgres + Redis)
docker exec marketing-tech-app-1 php vendor/bin/phpunit --colors=never --no-coverage

# Run specific test file
docker exec marketing-tech-app-1 php vendor/bin/phpunit tests/Feature/TelegramWebhookTest.php

# Run with coverage (requires Xdebug)
docker exec marketing-tech-app-1 php vendor/bin/phpunit --coverage-text
```

Expected: **68 tests, 137 assertions, 0 failures**.

### Test architecture
- `TestCase` disables `VerifyCsrfToken` for API tests
- `TelegramBotService` is mocked in all Telegram tests (no real API calls)
- Real PostgreSQL + Redis used for integration tests (no in-memory substitutes)
- Circuit breaker state is reset between tests

---

## Project Structure

```
app/
├── Agents/
│   ├── BaseAgent.php              # Shared AI loop, RAG context, tool reliability
│   ├── AgentOrchestrator.php      # Dispatches agents from Telegram and API
│   ├── MarketingAgent.php
│   ├── ContentAgent.php           # 14 tools incl. generate_platform_variants
│   ├── MediaAgent.php             # 12 tools incl. FFmpeg, DALL-E 3, TTS
│   ├── HiringAgent.php
│   ├── GrowthAgent.php
│   └── KnowledgeAgent.php
│
├── Http/Controllers/
│   └── DashboardController.php    # All API + OAuth endpoints (~1500 lines)
│
├── Jobs/
│   ├── ProcessCampaignRequest.php # Autonomous campaign pipeline
│   ├── ProcessHiringRequest.php   # Autonomous hiring pipeline
│   ├── DispatchScheduledPosts.php # Every-minute social publisher
│   ├── EmbedKnowledgeChunk.php    # Async vector embedding
│   ├── IngestGitHubRepo.php       # GitHub repo → knowledge base
│   └── RefreshAgentSkills.php     # Selective manifest refresh
│
├── Models/
│   ├── AgentJob.php
│   ├── ContentCalendar.php        # JSONB hashtags, pgvector-linked
│   ├── SocialAccount.php          # Encrypted tokens at rest
│   ├── KnowledgeBase.php          # pgvector embeddings + SoftDeletes
│   ├── JobPosting.php
│   ├── HashtagSet.php
│   └── SystemEvent.php
│
├── Services/
│   ├── AI/
│   │   ├── AIRouter.php           # Provider routing with cost tracking + fallback
│   │   └── OpenAIService.php      # GPT, DALL-E 3, Whisper, TTS
│   ├── Campaign/
│   │   ├── CampaignIntentAnalyzer.php
│   │   ├── AutonomousCampaignService.php
│   │   ├── CampaignPreviewBuilder.php
│   │   └── CampaignApprovalService.php
│   ├── Hiring/
│   │   └── HiringApprovalService.php
│   ├── Knowledge/
│   │   ├── VectorStoreService.php
│   │   └── KnowledgeMergeService.php  # Jaccard + LLM smart merge
│   ├── Media/
│   │   ├── FFmpegService.php
│   │   ├── ImageService.php
│   │   └── OCRService.php
│   ├── Social/
│   │   ├── SocialPlatformService.php  # Factory
│   │   ├── InstagramService.php
│   │   ├── TwitterService.php
│   │   ├── LinkedInService.php
│   │   ├── FacebookService.php
│   │   ├── TikTokService.php
│   │   └── YouTubeService.php
│   └── Telegram/
│       ├── TelegramBotService.php
│       └── CommandHandler.php
│
database/
├── migrations/                    # Full schema history
└── seeders/
    ├── AgentSkillsSeeder.php      # VERSION 3 manifests for all 6 agents
    └── AgentKnowledgeSeeder.php   # 16 domain knowledge items

resources/views/
├── dashboard/                     # Alpine.js + Chart.js SPA tabs
├── social.blade.php               # Social calendar + connections + hashtag library
└── vendor/horizon/                # Dark-theme Horizon override

config/
├── agents.php                     # Telegram, AI provider, queue config
└── agent_skills.php               # Skill-to-agent registry with RAG categories
```

---

## License

Private — all rights reserved.
