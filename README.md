<div align="center">

# 📡 Marketing Tech

### An AI-Powered Multi-Agent Marketing Automation Platform

*Laravel 11 · Multi-Agent Orchestration · 6 Social Platforms · RAG Knowledge Base*

<br>

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15+-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgresql.org)
[![Redis](https://img.shields.io/badge/Redis-7.x-DC382D?style=flat-square&logo=redis&logoColor=white)](https://redis.io)
[![License](https://img.shields.io/badge/License-Private-6B7280?style=flat-square)](#license)

<br>

> Marketing Tech is a production-grade AI marketing platform that orchestrates specialist agents — Content, Marketing, Media, Hiring, Growth, and Knowledge — across Horizon queues, with RAG-powered knowledge retrieval, a social publishing engine for 6 platforms, and an intelligence layer that learns from every campaign.

<br>

[Quick Start](#-quick-start) · [Agents](#-agent-system) · [Social Layer](#-social-layer) · [Intelligence](#-intelligence-layer) · [RAG & Knowledge](#-rag--knowledge-base)

</div>

---

<br>

## ✨ Platform Capabilities

<br>

| | Capability | Description |
|---|---|---|
| 🤖 | **Multi-Agent Orchestration** | Specialist agents dispatched via Horizon queues with DAG-based workflow execution |
| 🧠 | **Intelligence Layer** | UCB1 bandit routing, feedback loops, circuit breakers, and winner selection |
| 📚 | **RAG Knowledge Base** | RAGFlow semantic search with PageIndex fallback and dual-write on store |
| 📱 | **Social Publishing** | Real API integrations for Instagram, Twitter, LinkedIn, Facebook, TikTok, YouTube |
| 📅 | **Campaign Engine** | Create, schedule, pause, and resume campaigns with live analytics |
| 🔌 | **MCP Tool Execution** | Agents dispatch MCP tools via stdio (JSON-RPC) and HTTP/SSE transports |
| 📊 | **Brand Brain** | Strategic insights and brand memory extracted from campaign performance |
| 🤖 | **Telegram Bot** | Full round-trip: command → agent run → result notification |
| 🔬 | **Prompt Templates** | Dynamic variable injection (`{date}`, `{business_name}`, `{agent_type}`) at job start |
| 📉 | **Rate Limit Handling** | `RateLimitException` → `$this->release($retryAfter)` — workers never block |

<br>

---

<br>

## 🏗️ Architecture

<br>

### Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | Laravel 11 · PHP 8.2+ |
| **Frontend** | Blade Templates · Alpine.js · Tailwind CSS (dark slate-950) |
| **Database** | PostgreSQL 15+ with pgvector extension |
| **Cache & Queues** | Redis 7.x + Laravel Horizon |
| **Charts** | Chart.js 4.4.8 *(pinned — 4.4.0 had a fullSize crash)* |
| **RAG** | RAGFlow (port 9380) + PageIndex fallback |
| **AI Providers** | OpenAI · Anthropic (via `AIRouter`) |
| **Social APIs** | Instagram Graph v19 · Twitter v2 · LinkedIn ugcPosts v2 · Facebook Graph v19 · TikTok Content Posting v2 · YouTube Data v3 |

<br>

### Platform Workflow

```
┌─────────────────────────────────────────────────────────────────────┐
│                        MARKETING TECH                               │
└─────────────────────────────────────────────────────────────────────┘

  AGENT ORCHESTRATION                    INTELLIGENCE LAYER
  ─────────────────────                  ───────────────────────────

  POST /agent/workflows                  IterationEngineService
         │                                       │
         ▼                              ┌────────┴─────────┐
  AgentOrchestrator                     │                  │
  ::dispatchWorkflow()            Feedback Loop      UCB1 Bandit
         │                              │            (tool routing)
         ▼                              │                  │
  Workflow + WorkflowTask               ▼                  ▼
  records created              Winner Selection   Circuit Breaker
         │                              │                  │
         ▼                              └────────┬─────────┘
  Horizon Queue                                  │
  (10 supervisors)                               ▼
         │                              Tool Reliability Score
         ▼                              + Brand Brain Insights
  RunAgentJob::advanceWorkflowDag()
  dispatches dependent steps
  on task completion
```

<br>

### Agent Tool Dispatch

```
BaseAgent::run()
      │
      ▼
getAllToolDefinitions()          ← agent tools + universal mcp_tool
      │
      ▼
dispatchTool()
      │
      ▼
executeToolWithReliability()    ← circuit breaker + schema validation
      │
      ├─ MCP Tool  →  McpToolService → stdio (JSON-RPC) or HTTP/SSE
      └─ Native    →  Agent-specific tool handler
```

*History compressed every 8 steps. Session learnings auto-persisted to `knowledge_base`.*

<br>

---

<br>

## 🤖 Agent System

<br>

### Specialist Agents

| Agent | Domain |
|---|---|
| **ContentAgent** | Blog posts, copy, creative writing |
| **MarketingAgent** | Campaign strategy, audience targeting |
| **MediaAgent** | Image, video, and media asset generation |
| **HiringAgent** | Job descriptions, candidate evaluation |
| **GrowthAgent** | Analytics insights, growth experiments |
| **KnowledgeAgent** | Knowledge base ingestion and retrieval |

<br>

### Horizon Supervisors

All agents run on dedicated Horizon supervisors:

`default` · `marketing` · `media` · `hiring` · `content` · `growth` · `knowledge` · `agents` · `low` · `social`

> `supervisor-social` requires **3 processes** for `DispatchScheduledPosts` (runs every minute).

<br>

---

<br>

## 📱 Social Layer

<br>

### Platform Integrations

| Platform | API | Auth |
|---|---|---|
| **Instagram** | Graph API v19 | App token |
| **Twitter / X** | API v2 | PKCE OAuth |
| **LinkedIn** | ugcPosts v2 | OAuth 2.0 |
| **Facebook** | Graph API v19 | Page token · multi-page |
| **TikTok** | Content Posting v2 | PKCE OAuth + async polling |
| **YouTube** | Data API v3 | Resumable upload + session recovery |

<br>

### Publishing Workflow

```
DispatchScheduledPosts (every minute)
      │
      ▼
ContentCalendar.scheduledNow()
  filter: moderation_status IN (approved, auto_approved)
      │
      ▼
SocialPlatformService (factory)
      │
  per platform:
  ├── ensurePublicUrl()       local paths → S3 temporaryUrl
  ├── Rate limit check        Redis quota: social:quota:{platform}:{date}
  │                           Retry-After key: social:retry_after:{platform}
  ├── Priority sort           overdue >30min=0 · <5min=1 · normal=2
  └── Publish → platform API
```

<br>

### Feature Flags

```env
SOCIAL_AUTO_POST_ENABLED=false   # enable auto-publishing
SOCIAL_DRY_RUN=false             # log without actually posting
```

<br>

### Account Health

`CheckAllSocialAccountHealth` runs hourly → spawns `TestSocialConnectionJob` (staggered 3 s per account). Connection status stored on `SocialAccount` (`connection_healthy` + `last_tested_at`).

**Credential bridge:** `SocialCredentialServiceProvider::boot()` overwrites `config('services.*')` from DB values at boot — no env restart needed when credentials change.

<br>

---

<br>

## 🧠 Intelligence Layer

<br>

`IterationEngineService` closes the feedback loop on every campaign run:

| Component | Role |
|---|---|
| **Feedback Loop** | Ingests performance signals after each agent run |
| **UCB1 Bandit Router** | Routes tool calls to the best-performing provider |
| **Winner Selection** | Surfaces highest-performing content variants |
| **Tool Reliability Scoring** | Tracks per-tool success rate and latency |
| **Circuit Breaker** | Trips on repeated failures, auto-resets on recovery |
| **Budget Allocator** | Redistributes spend toward winning channels |
| **Brand Brain** | Persists strategic insights across campaigns |

<br>

---

<br>

## 📚 RAG & Knowledge Base

<br>

```
VectorStoreService::search()
      │
      ├─ RAGFlow first    (port 9380, semantic search)
      │        │
      │      fallback on unavailable
      │        │
      └─ PageIndex → ILIKE (SQL keyword search)

VectorStoreService::store()
      │
      ├─ Write to RAGFlow
      └─ Write to local DB   (dual-write for resilience)
```

**Start RAGFlow:**

```bash
docker compose --profile ragflow up -d
```

**Migrate existing KB to RAGFlow:**

```bash
php artisan knowledge:migrate-to-ragflow
```

<br>

---

<br>

## 📅 Campaign Engine

<br>

**Create a campaign:**

```
POST /api/campaigns
{
  "name": "Q2 Launch",
  "type": "email",
  "audience": "leads",
  "subject": "Introducing...",
  "schedule_at": "2026-05-01T09:00:00Z"
}
```

**Pause / Resume:** dedicated endpoints available.

**Campaign detail chart:** uses live `apiCampaignDetail` data — agent runs + outputs per day — not synthetic data.

<br>

---

<br>

## 🤖 Telegram Bot

<br>

```
POST /webhook/telegram (HMAC verified)
      │
      ▼
TelegramController → TelegramBotService → CommandHandler
      │
      ▼
AgentOrchestrator::dispatch()
      │
      ├── Start ACK sent to user
      ├── Agent executes on Horizon queue
      └── Result / failure → BaseAgent::notifyUser()
```

<br>

---

<br>

## 🔌 MCP Tool Execution

<br>

```
mcp_servers DB table
      │
      ▼
McpServer model → McpToolService
      │
      ├── stdio transport    JSON-RPC subprocess
      └── HTTP/SSE transport  streaming remote tools
```

Every agent has the universal `mcp_tool` available alongside its own tool set.

<br>

---

<br>

## 🚀 Quick Start

<br>

### Prerequisites

- PHP **8.2+** · Composer **2.x** · Node.js **18+**
- PostgreSQL **15+** with pgvector extension
- Redis **7.x**
- Docker *(for RAGFlow)*

<br>

### Setup

```bash
# 1. Install dependencies
composer install
npm install && npm run build

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database
php artisan migrate --seed

# 4. Start Horizon
php artisan horizon

# 5. Start RAGFlow (optional — semantic search)
docker compose --profile ragflow up -d

# 6. Dev server
php artisan serve
```

<br>

### Horizon Supervisors

Ensure all 10 supervisors are running, including `supervisor-social` with 3 processes for scheduled social posts.

<br>

---

<br>

## 🔒 Security Notes

- **Tokens encrypted at rest** — `SocialAccount` OAuth tokens use Laravel encryption
- **CSRF validated** — all 6 OAuth callback flows
- **Moderation gate** — `scheduledNow()` only serves `approved` or `auto_approved` content
- **JSON Mode** — `AIRouter` enforces `response_format: json_object` for structured agent output
- **Rate limit exceptions** — never `sleep()` in workers; use `$this->release($retryAfter)`

<br>

---

<br>

## 📊 API Reference

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/agent/workflows` | Create and dispatch a workflow DAG |
| `GET` | `/agent/workflows/{id}` | Poll workflow status |
| `POST` | `/api/campaigns` | Create a campaign |
| `POST` | `/api/campaigns/{id}/pause` | Pause a campaign |
| `POST` | `/api/campaigns/{id}/resume` | Resume a campaign |
| `POST` | `/webhook/telegram` | Telegram bot webhook (HMAC verified) |

<br>

---

<br>

<div align="center">

**Marketing Tech** · AI-native marketing automation

*Laravel 11 · PostgreSQL + pgvector · Redis · RAGFlow · Horizon*

</div>
