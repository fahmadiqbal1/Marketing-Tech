# Autonomous Business Operations Platform — Deployment Guide

## Prerequisites

- Docker ≥ 24.0 and Docker Compose ≥ 2.20
- A server with at least 4 CPU cores and 8 GB RAM (16 GB recommended)
- A registered Telegram bot (from @BotFather)
- OpenAI API key and Anthropic API key
- A domain name (or use localhost for local testing)

---

## Quick Start (5 minutes)

### 1. Clone and configure

```bash
git clone <your-repo> autonomous-ops
cd autonomous-ops
cp .env.example .env
```

### 2. Fill in your `.env`

Open `.env` and set **every** variable marked `CHANGE_ME`:

```bash
# Generate a Laravel app key
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
# Paste output as APP_KEY=

# Required secrets
APP_KEY=base64:...
DB_PASSWORD=choose-a-strong-32-char-password
REDIS_PASSWORD=choose-a-redis-password
MINIO_ROOT_PASSWORD=choose-a-minio-password

# Real API keys
OPENAI_API_KEY=sk-proj-...
ANTHROPIC_API_KEY=sk-ant-...
TELEGRAM_BOT_TOKEN=1234567890:ABC...
TELEGRAM_WEBHOOK_SECRET=choose-a-random-32-char-secret
TELEGRAM_ALLOWED_USERS=your-telegram-user-id  # get from @userinfobot
TELEGRAM_ADMIN_CHAT_ID=your-telegram-user-id

# Your public URL (needed for Telegram webhook)
APP_URL=https://your-domain.com
```

### 3. Start all services

```bash
docker compose up -d
```

This starts: `app`, `nginx`, `horizon`, `scheduler`, `postgres`, `redis`, `minio`, `clamav`

### 4. First-time database setup

```bash
# Run migrations and seed skills registry
docker compose exec app php artisan migrate --seed

# Verify pgvector is active
docker compose exec postgres psql -U opsuser -d opsplatform -c "SELECT extname FROM pg_extension WHERE extname='vector';"
```

### 5. Register the Telegram webhook

```bash
docker compose exec app php artisan telegram:webhook
```

Expected output:
```
Webhook registered: https://your-domain.com/webhook/telegram
```

### 6. Verify everything is running

```bash
# Check container health
docker compose ps

# Test the API
curl https://your-domain.com/up

# Check Horizon dashboard
open https://your-domain.com/horizon

# Send /start to your Telegram bot — it should reply
```

---

## Architecture Overview

```
Telegram Bot
     │
     ▼
Nginx (rate-limited webhook endpoint)
     │
     ▼
TelegramController → CommandHandler
     │
     ▼
WorkflowDispatcher ──► Redis Queue
     │                      │
     ▼                      ▼
WorkflowStateMachine    Horizon Workers
  10-state lifecycle      ├─ marketing (3 workers)
                          ├─ content   (8 workers, auto-scale)
                          ├─ media     (2 workers)
                          ├─ hiring    (2 workers)
                          ├─ growth    (2 workers)
                          └─ knowledge (2 workers)
     │
     ▼
AgentOrchestrator → BaseAgent → AI Router
                                  ├─ OpenAI (GPT-4o / embeddings)
                                  └─ Anthropic (Claude)
     │
     ▼
PostgreSQL + pgvector    MinIO (S3)    ClamAV
  Context graph            Media        Virus scan
  Knowledge base           Storage
  All business data
```

---

## Service Responsibilities

| Service | Container | Purpose |
|---|---|---|
| `app` | `ops_app` | Laravel FPM — handles HTTP requests |
| `nginx` | `ops_nginx` | Reverse proxy, rate limiting, static files |
| `horizon` | `ops_horizon` | Queue workers for all agent/workflow jobs |
| `scheduler` | `ops_scheduler` | Cron: supervisor tick, campaign sends, experiment analysis |
| `postgres` | `ops_postgres` | Primary database with pgvector extension |
| `redis` | `ops_redis` | Job queues, cache, sessions |
| `minio` | `ops_minio` | Object storage for media files |
| `clamav` | `ops_clamav` | Malware scanning daemon |

---

## Telegram Commands

| Command | What it does |
|---|---|
| `/start` | Show welcome and capabilities |
| `/help` | List all commands |
| `/status` | System health: queues, DB, running jobs |
| `/jobs` | List active/pending agent jobs |
| `/campaign <instruction>` | Create or manage marketing campaigns |
| `/content <instruction>` | Generate content (posts, blogs, scripts) |
| `/media <instruction>` | Process uploaded files |
| `/hire <instruction>` | CV screening, job posts, pipeline |
| `/growth <instruction>` | Experiments, metrics, reports |
| `/knowledge <query>` | Search or add to knowledge base |
| `/agent <task>` | Free-form agent task (auto-routes) |
| `/cancel <job_id>` | Cancel a running job |
| `/approve <workflow_id>` | Approve a workflow awaiting sign-off |
| `/logs` | Recent error log entries |

---

## Workflow Lifecycle (10 states)

```
INTAKE → CONTEXT_RETRIEVAL → PLANNING → TASK_EXECUTION
  → REVIEW → OWNER_APPROVAL → EXECUTION → OBSERVATION
  → LEARNING → COMPLETED

FAILED → INTAKE  (auto-retry with exponential backoff, max 3 attempts)
```

**Approval-required workflows** (campaigns, job posts) pause at `OWNER_APPROVAL`
and resume when you send `/approve <id>` in Telegram.

---

## Queue Priority

| Queue | Workers | Priority | Use |
|---|---|---|---|
| `marketing` | 3 | high | Campaign creation, ad generation |
| `content` | 1–8 (auto-scale) | high | All content generation |
| `media` | 2 | medium | FFmpeg, ImageMagick, ClamAV |
| `hiring` | 2 | medium | CV parsing, scoring |
| `growth` | 2 | low | Experiments, analytics |
| `knowledge` | 2 | low | Context graph updates |
| `supervisor` | 1 | — | Dead-letter, health monitoring |

---

## AI Model Routing

The `AIRouter` service routes inference requests with automatic fallback:

| Model | Provider | Used for |
|---|---|---|
| `gpt-4o` | OpenAI | Agent planning, marketing, growth |
| `gpt-4o-mini` | OpenAI | Classification, routing, short tasks |
| `claude-opus-4-5` | Anthropic | Content writing, hiring, complex reasoning |
| `claude-haiku-4-5-20251001` | Anthropic | CV parsing, scoring (fast + cheap) |
| `text-embedding-3-large` | OpenAI | Knowledge graph, semantic search |

Fallback chain: `gpt-4o → gpt-4o-mini → claude-haiku-4-5-20251001`

---

## Media Pipeline (8 steps)

When you send a file to the Telegram bot:

1. **Quarantine** — stored at `ops-uploads/quarantine/` in MinIO
2. **MIME validation** — actual content type checked (prevents spoofing)
3. **ClamAV scan** — TCP stream scan via clamd
4. **EXIF stripping** — removes GPS and personal metadata (images)
5. **Normalisation** — resize to max 4K, convert HEIC → JPEG
6. **Re-encoding** — video transcoded to web-optimised H.264 MP4
7. **OCR extraction** — Tesseract pulls text from images/PDFs
8. **AI classification** — GPT-4o-mini classifies content type

Infected files are deleted immediately and never stored.

---

## Context Graph Memory

Every completed workflow stores learnings as vector-embedded nodes in the
`context_graph_nodes` table. Future workflows query semantically similar nodes
using pgvector cosine similarity before generating their plan, giving agents
persistent business memory across sessions.

Decay: node `relevance_decay` scores reduce by 5% weekly so older knowledge
gradually de-prioritises without being deleted.

---

## Experimentation Engine

Marketing campaigns automatically generate A/B variants. The engine:

1. Calls `ExperimentationEngine::generateForCampaign()` to produce variants
2. Distributes traffic 50/50 via `experiment_events` records
3. Runs two-proportion z-test every 15 minutes via scheduler
4. Concludes when `p < 0.05` AND `current_sample_size ≥ min_sample_size`
5. Stores winning strategy as a knowledge graph node for future campaigns

---

## Monitoring

**Horizon dashboard**: `https://your-domain.com/horizon`

**Health endpoint**: `GET /up` returns 200 when app is ready

**System events**: Every error/warning is written to `system_events` table
and sent to `TELEGRAM_ADMIN_CHAT_ID` via the supervisor tick (runs every minute).

**AI cost tracking**: Every API call logged to `ai_requests` with token counts
and USD cost. Query: `SELECT provider, model, SUM(cost_usd) FROM ai_requests GROUP BY 1,2`

---

## Scaling

**Horizontal worker scaling** (add more Horizon processes):

```bash
# In docker-compose.yml, add replicas to horizon service:
deploy:
  replicas: 3
```

Or adjust `config/horizon.php` `maxProcesses` per queue supervisor.

**Database read replicas**: Set `DB_READ_HOST` (add to `.env` and `config/database.php`).

**Redis Cluster**: Replace single Redis with `redis-cluster:` in docker-compose.

---

## Adding a New Agent

1. Create `app/Agents/MyNewAgent.php` extending `BaseAgent`
2. Set `protected string $agentType = 'my_agent'`
3. Implement `executeTool()` and `getToolDefinitions()`
4. Add entry to `config/agents.php` under `agents`
5. Add queue supervisor in `config/horizon.php`
6. Register in `AppServiceProvider::register()`
7. Add command handler in `CommandHandler::handle()`

---

## Adding a New Skill

1. Create `app/Skills/MySkill.php` implementing `SkillInterface`
2. Add to `config/skills.php` `registered` array
3. Run `php artisan skills:sync`

---

## Production Checklist

- [ ] `APP_DEBUG=false` in `.env`
- [ ] `APP_ENV=production` in `.env`
- [ ] All `CHANGE_ME` values replaced with real secrets
- [ ] SSL certificate on nginx (or use Cloudflare proxy)
- [ ] `php artisan config:cache` run after `.env` changes
- [ ] `php artisan telegram:webhook` run after domain change
- [ ] `docker compose exec app php artisan migrate --seed` run on first deploy
- [ ] MinIO buckets created (handled automatically by `minio_init` container)
- [ ] ClamAV virus database updated (`docker compose exec clamav freshclam`)
- [ ] PostgreSQL backups scheduled (use `pg_dump` via cron on host)
- [ ] Redis AOF persistence enabled (already configured in docker-compose.yml)

---

## Updating the Platform

```bash
git pull
docker compose build app horizon scheduler
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan event:cache
docker compose exec app php artisan skills:sync
```

---

## Environment Variable Reference

See `.env.example` for all variables with descriptions.

Critical required variables:
- `APP_KEY` — Laravel encryption key (generate with `php artisan key:generate`)
- `DB_PASSWORD` — PostgreSQL password
- `REDIS_PASSWORD` — Redis auth password
- `OPENAI_API_KEY` — OpenAI API access
- `ANTHROPIC_API_KEY` — Anthropic/Claude API access
- `TELEGRAM_BOT_TOKEN` — Bot token from @BotFather
- `TELEGRAM_WEBHOOK_SECRET` — Random secret for webhook HMAC verification
- `TELEGRAM_ALLOWED_USERS` — Comma-separated Telegram user IDs (your user ID)
- `TELEGRAM_ADMIN_CHAT_ID` — Chat ID for error notifications
- `MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD` — MinIO credentials
