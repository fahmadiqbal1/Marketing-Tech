# CLAUDE.md — Marketing-Tech Platform

Read at every session start. Domain-specific rules live in subdirectory files (see Self-Correction Protocol).

---

## Project Overview

Laravel 11 AI marketing platform:
- **Agents**: `BaseAgent` → specialised agents (Content, Marketing, Media, Hiring, Growth, Knowledge) via Horizon queues. Tool dispatch: `getAllToolDefinitions()` (agent tools + universal `mcp_tool`) → `dispatchTool()` → `executeToolWithReliability()` (circuit breaker + schema validation). History compressed every 8 steps. Session learnings auto-persisted to `knowledge_base`.
- **Workflow DAG**: `AgentOrchestrator::dispatchWorkflow()` creates `Workflow` + `WorkflowTask` records. `RunAgentJob::advanceWorkflowDag()` dispatches dependent steps on completion. API: `POST /agent/workflows`, `GET /agent/workflows/{id}`.
- **RAGFlow**: Semantic RAG via `RAGFlowService` (port 9380). `VectorStoreService::search()` tries RAGFlow first, falls back to PageIndex → ILIKE. Dual-write on `store()`. Run `docker compose --profile ragflow up -d` to start. Migrate existing KB: `php artisan knowledge:migrate-to-ragflow`.
- **IterationEngineService**: feedback loop — patterns, winner selection, tool reliability, circuit breaker
- **MCP Tool Execution**: `mcp_servers` DB table → `McpServer` model → `McpToolService` → universal `mcp_tool` in every agent. Supports stdio (JSON-RPC subprocess) and HTTP/SSE transports.
- **Prompt Templates**: `PromptTemplateService::render()` replaces `{date}`, `{business_name}`, `{agent_type}` in all system prompts at job start. Add vars in `BaseAgent::run()`.
- **JSON Mode**: `OpenAIService::chat($jsonMode=true)` adds `response_format: json_object`. `AnthropicService::chat($jsonSchema=[...])` adds structured output. `AIRouter::chat()` proxies both. Used in `classifyInstruction()` and swarm synthesis.
- **Rate Limit Handling**: `RateLimitException` thrown from AI services (not `sleep()`). Caught in `RunAgentJob` → `$this->release($retryAfter)`. Workers never block.
- **pgvector**: PostgreSQL vector extension available for embeddings (currently unused in hot path — RAGFlow handles semantic search)
- **Alpine.js + Tailwind CSS**: dark slate-950 palette frontend
- **Chart.js 4.4.8** (CDN): pinned — 4.4.0 had a `fullSize` crash
- **Laravel Horizon**: SPA at `resources/views/vendor/horizon/layout.blade.php` with dark-theme override
- **Social Layer (Phase 9F+)**: `SocialPlatformService` (factory) → 6 real platform services, no stubs. Instagram (Graph API v19), Twitter (API v2 + PKCE), LinkedIn (ugcPosts v2), Facebook (Graph API v19 Page token, multi-page), TikTok (Content Posting API v2 + PKCE, async `PollTikTokPublishStatus`), YouTube (Data API v3 resumable upload with session recovery). Models: `ContentCalendar`, `HashtagSet`, `SocialAccount` (tokens encrypted at rest, + `connection_healthy` + `last_tested_at`). Jobs on `social`/`low` queues — use `$this->onQueue()` in constructor (NOT `$queue` property — conflicts with `Queueable` trait). Feature flags: `SOCIAL_AUTO_POST_ENABLED=false`, `SOCIAL_DRY_RUN=false`. CSRF state validated for all 6 OAuth flows. Moderation gate: `scheduledNow()` requires `moderation_status IN (approved, auto_approved)` (indexed). `ensurePublicUrl()` converts local paths → S3 temporaryUrl. **Credential bridge**: `SocialCredentialServiceProvider::boot()` overwrites `config('services.*')` from `ApiCredentialService` DB values — registered in `bootstrap/providers.php`. App credentials verified live (OAuth client_credentials grant) on save via `apiVerifySocialCredentials()`. Account health checked hourly via `CheckAllSocialAccountHealth` → `TestSocialConnectionJob` (staggered 3s each). Rate-limit dispatcher: daily Redis quota keys `social:quota:{platform}:{date}`, Retry-After Redis key `social:retry_after:{platform}`, priority sort (overdue>30min=0, <5min=1, normal=2).
- **Campaigns**: `POST /api/campaigns` → `apiCreateCampaign()` (name, type, audience, subject, schedule_at). Pause/Resume endpoints exist. Detail chart uses live `apiCampaignDetail` data (agent runs + outputs per day), not random data.
- **Telegram Bot**: `POST /webhook/telegram` (HMAC verified) → `TelegramController` → `TelegramBotService` → `CommandHandler` → `AgentOrchestrator::dispatch()`. Full round-trip: start ACK → agent runs → result/failure notification via `BaseAgent::notifyUser()`.
- **Horizon**: `supervisor-social` (3 processes) required for `DispatchScheduledPosts` (every-minute job). All supervisors: default, marketing, media, hiring, content, growth, knowledge, agents, low, social.
- **ETCSLV Agent Harness**: `BaseAgent` uses `HookableTrait` (4 hooks: `before_run`, `after_run`, `before_tool`, `after_tool`) + `ValidatesOutput` trait. Register hooks via `$agent->on('before_tool', fn($name, $args) => ...)`. `AgentOutputContract` interface for final answer schema validation — implement and pass to `$agent->withOutputContract(...)`. Dead-letter on `maxSteps` exhaustion: written to `agent_dead_letters` table, job fails visibly in Horizon.

---

## Mandatory Feedback Loop

```
1. PLAN    — TodoWrite before touching any file
2. READ    — read relevant files before editing (never edit blind)
3. EXECUTE — one todo at a time, mark in_progress before starting
4. VERIFY  — re-read edited section to confirm correctness
5. COMMIT  — commit when a logical unit is done (do not batch across features)
6. PUSH    — push immediately; if proxy 403, fall back to token on FIRST retry
7. CLEAN   — mark todo completed; git status must be clean before declaring done
```

If any step fails: stop, diagnose, adjust — do NOT brute-force retry.

---

## Self-Correction Protocol

**Before every task:**
1. Check `MEMORY.md` — scan Active Lessons for scope overlap with current task
2. Read the relevant subdirectory `CLAUDE.md` for domain-specific rules:
   - Database / pgvector / migrations → `@database/CLAUDE.md`
   - Blade templates / Alpine.js / UI → `@resources/views/CLAUDE.md`
   - Controllers / API endpoints → `@app/Http/Controllers/CLAUDE.md`
   - Agent tools / IterationEngine → `@app/Agents/CLAUDE.md`
3. If task touches multiple domains, read ALL relevant subdirectory files

**After every task:**
1. Reflect: what was missed, what broke, what took longer than expected?
2. Update `MEMORY.md` Active Lessons with any new learning
3. Move permanently-fixed lessons to Resolved/Archived in `MEMORY.md`
4. Add a 1-line entry to `MEMORY.md` Session Log
5. Update this file if architectural patterns changed

---

## Git Rules

**Branch:** always `claude/master-agent-system-vjyTs`

**Push:**
```bash
# Primary
git push -u origin claude/master-agent-system-vjyTs
# If 403 on first attempt — switch to token IMMEDIATELY (do not retry proxy)
git remote set-url origin https://<GITHUB_TOKEN>@github.com/fahmadiqbal1/Marketing-Tech.git
git push -u origin claude/master-agent-system-vjyTs
```

**Before every commit:**
```bash
git status       # nothing untracked that belongs in repo
git diff --cached  # no debug code, no .env, no vendor changes
```

**Never commit:** `.env`, `.env.*`, `vendor/`, `bootstrap/cache/*.php` (unless explicitly regenerated)

---

## Agency Agents (Installed 2026-04-27)

20 specialist Claude Code agents installed to `~/.claude/agents/`. Invoke via `/agent` in any Claude Code session.

| Division | Agents |
|---|---|
| Design | `design-brand-guardian`, `design-ui-designer`, `design-ux-architect`, `design-image-prompt-engineer` |
| Marketing | `marketing-content-creator`, `marketing-social-media-strategist`, `marketing-growth-hacker`, `marketing-seo-specialist`, `marketing-agentic-search-optimizer`, `marketing-instagram-curator`, `marketing-linkedin-content-creator`, `marketing-tiktok-strategist` |
| Paid Media | `paid-media-auditor`, `paid-media-creative-strategist`, `paid-media-paid-social-strategist`, `paid-media-ppc-strategist`, `paid-media-programmatic-buyer`, `paid-media-search-query-analyst`, `paid-media-tracking-specialist` |
| Strategy | `strategy-nexus` |

**When to use:** invoke the matching specialist agent when planning content, auditing campaigns, designing UI, running paid media analysis, or coordinating multi-agent workflows via `strategy-nexus`.

---

## Lessons (Cross-Cutting)

### Git: Staging incomplete — stop hook will fail
Always run `git status` after the last edit. The stop hook blocks on untracked/unstaged files.
`bootstrap/cache/services.php` has triggered this twice.

### Git: Local proxy 403 — fall back to token on first retry
After ONE failed push, set remote URL to GitHub HTTPS token URL. Do not retry the broken proxy.

### Cross-cutting: CLAUDE.md must be updated proactively
After any significant feature, bug fix, or audit — update this file AND relevant subdirectory CLAUDE.md.
Do NOT wait to be asked.

### Cross-cutting: GitHub import URL normalization
Normalize in BOTH PHP and JS before computing md5 cache key:
```php
$url = strtolower(trim($url));
$url = preg_replace('#\.git$#', '', $url);
return rtrim($url, '/');
```
```js
const repoUrl = raw.trim().toLowerCase().replace(/\.git$/, '').replace(/\/$/, '');
```
Cache TTL: `now()->addHours(6)` (not 3600).

### Cross-cutting: GitHub import progress tracking
Cache key: `'github-import:' . md5($repoUrl)` — TTL 6hr.
Payload: `{status: running|completed|failed, ingested: N, total: N}`.
Stop polling when status is `completed` or `failed`.

---

## Project Stack Versions

| Component | Version | Notes |
|-----------|---------|-------|
| PHP | 8.2+ | |
| Laravel | 11.x | |
| Chart.js | **4.4.8** | Pinned — 4.4.0 fullSize crash |
| Alpine.js | 3.x | CDN |
| Tailwind CSS | 3.x | |
| PostgreSQL | 15+ | pgvector required |
| Redis | 7.x | Cache + Horizon queues |

---

## Settings & Hooks

Stop hook (`~/.claude/stop-hook-git-check.sh`): fires after every response — blocks on uncommitted
changes or unpushed commits. Resolve immediately; do not continue with a dirty tree.

---

## Pre-Task Checklist

- [ ] Read `MEMORY.md` — any active lesson relevant to this task?
- [ ] Read relevant subdirectory `CLAUDE.md` (database / views / controllers / agents)?
- [ ] `git status` — is the tree clean?
- [ ] TodoWrite list created for multi-step work?
- [ ] Any DB constraints or model traits that differ from migration schema?
- [ ] Raw SQL / pgvector queries involved — injection risk checked?
- [ ] Blade template changes — will need browser verification after edits?
- [ ] Feature flags needed (e.g., `SOCIAL_AUTO_POST_ENABLED`) for safe rollout?

---

## Performance Management

- Use `/compact` when conversation exceeds ~80 messages
- Use `/clear` between unrelated tasks to keep context fresh
- Subdirectory CLAUDE.md files reduce per-session token load significantly
- MEMORY.md is intentionally compact — table format, no prose

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **Marketing-Tech** (2984 symbols, 8347 relationships, 243 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## When Debugging

1. `gitnexus_query({query: "<error or symptom>"})` — find execution flows related to the issue
2. `gitnexus_context({name: "<suspect function>"})` — see all callers, callees, and process participation
3. `READ gitnexus://repo/Marketing-Tech/process/{processName}` — trace the full execution flow step by step
4. For regressions: `gitnexus_detect_changes({scope: "compare", base_ref: "main"})` — see what your branch changed

## When Refactoring

- **Renaming**: MUST use `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` first. Review the preview — graph edits are safe, text_search edits need manual review. Then run with `dry_run: false`.
- **Extracting/Splitting**: MUST run `gitnexus_context({name: "target"})` to see all incoming/outgoing refs, then `gitnexus_impact({target: "target", direction: "upstream"})` to find all external callers before moving code.
- After any refactor: run `gitnexus_detect_changes({scope: "all"})` to verify only expected files changed.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Tools Quick Reference

| Tool | When to use | Command |
|------|-------------|---------|
| `query` | Find code by concept | `gitnexus_query({query: "auth validation"})` |
| `context` | 360-degree view of one symbol | `gitnexus_context({name: "validateUser"})` |
| `impact` | Blast radius before editing | `gitnexus_impact({target: "X", direction: "upstream"})` |
| `detect_changes` | Pre-commit scope check | `gitnexus_detect_changes({scope: "staged"})` |
| `rename` | Safe multi-file rename | `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` |
| `cypher` | Custom graph queries | `gitnexus_cypher({query: "MATCH ..."})` |

## Impact Risk Levels

| Depth | Meaning | Action |
|-------|---------|--------|
| d=1 | WILL BREAK — direct callers/importers | MUST update these |
| d=2 | LIKELY AFFECTED — indirect deps | Should test |
| d=3 | MAY NEED TESTING — transitive | Test if critical path |

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/Marketing-Tech/context` | Codebase overview, check index freshness |
| `gitnexus://repo/Marketing-Tech/clusters` | All functional areas |
| `gitnexus://repo/Marketing-Tech/processes` | All execution flows |
| `gitnexus://repo/Marketing-Tech/process/{name}` | Step-by-step execution trace |

## Self-Check Before Finishing

Before completing any code modification task, verify:
1. `gitnexus_impact` was run for all modified symbols
2. No HIGH/CRITICAL risk warnings were ignored
3. `gitnexus_detect_changes()` confirms changes match expected scope
4. All d=1 (WILL BREAK) dependents were updated

## Keeping the Index Fresh

After committing code changes, the GitNexus index becomes stale. Re-run analyze to update it:

```bash
npx gitnexus analyze
```

If the index previously included embeddings, preserve them by adding `--embeddings`:

```bash
npx gitnexus analyze --embeddings
```

To check whether embeddings exist, inspect `.gitnexus/meta.json` — the `stats.embeddings` field shows the count (0 means no embeddings). **Running analyze without `--embeddings` will delete any previously generated embeddings.**

> Claude Code users: A PostToolUse hook handles this automatically after `git commit` and `git merge`.

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
