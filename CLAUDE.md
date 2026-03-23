# CLAUDE.md — Marketing-Tech Platform

Read at every session start. Domain-specific rules live in subdirectory files (see Self-Correction Protocol).

---

## Project Overview

Laravel 11 AI marketing platform:
- **Agents**: `BaseAgent` → specialised agents (Content, SEO, Email…) via Laravel Horizon queues
- **IterationEngineService**: feedback loop — patterns, winner selection, tool reliability, circuit breaker
- **pgvector**: PostgreSQL vector extension for RAG (`embedding <=>` cosine distance)
- **Alpine.js + Tailwind CSS**: dark slate-950 palette frontend
- **Chart.js 4.4.8** (CDN): pinned — 4.4.0 had a `fullSize` crash
- **Laravel Horizon**: SPA at `resources/views/vendor/horizon/layout.blade.php` with dark-theme override
- **Social Layer (Phase 9F)**: `SocialPlatformService` (factory) → 6 real platform services, no stubs. Instagram (Graph API v19), Twitter (API v2 + PKCE), LinkedIn (ugcPosts v2), Facebook (Graph API v19 Page token, multi-page), TikTok (Content Posting API v2 + PKCE, async `PollTikTokPublishStatus`), YouTube (Data API v3 resumable upload with session recovery). Models: `ContentCalendar`, `HashtagSet`, `SocialAccount` (tokens encrypted at rest). 6 automation jobs on `social`/`low` queues with explicit `$queue` class properties. Feature flags: `SOCIAL_AUTO_POST_ENABLED=false`, `SOCIAL_DRY_RUN=false`. CSRF state validated for all 6 OAuth flows (incl. Instagram). Moderation gate: `scheduledNow()` requires `moderation_status IN (approved, auto_approved)` (indexed). Scheduling conflict: ±15 min check on create/update. `ensurePublicUrl()` converts local paths → S3 temporaryUrl.
- **Campaigns**: `POST /api/campaigns` → `apiCreateCampaign()` (name, type, audience, subject, schedule_at). Pause/Resume endpoints exist. Detail chart uses live `apiCampaignDetail` data (agent runs + outputs per day), not random data.
- **Hiring Pipeline**: `POST /dashboard/api/candidates/apply` (rate-limited 5/min) → creates Candidate + dispatches HiringAgent screening job. `PruneRejectedCandidates` job runs daily 1am on `low` queue (30-day GDPR retention). Job postings auto-published to rozee.pk, indeed.pk, linkedin with `status=active`.
- **Media Generation** (Phase 10): `MediaAgent` tools: `generate_image` (Stability AI v2beta core), `remove_background` (Remove.bg), `enhance_image` (Stability AI upscale conservative), `generate_video` (Runway Gen-3, sync poll max 60s). API keys: `STABILITY_AI_API_KEY`, `REMOVEBG_API_KEY`, `RUNWAY_API_KEY` in `config/services.php`.
- **Credential Health**: `RefreshCredentialStatus` nightly at 2am (low queue) — validates all active `SocialCredential` records, deactivates stale ones. `apiSocialAccounts()` includes `token_expires_soon` field (3-day threshold). Amber badge in accounts tab UI.
- **Platform Char Limits**: `PLATFORM_CHAR_LIMITS` constant in `DashboardController` enforced with `mb_strlen` on create/update calendar entries.
- **Agent Cards**: Pipeline page shows `current_job` (instruction + progress bar) and idle state per agent. `apiPipeline()` includes `current_job` field — single query, no N+1.
- **Telegram Bot**: `POST /webhook/telegram` (HMAC verified) → `TelegramController` → `TelegramBotService` → `CommandHandler` → `AgentOrchestrator::dispatch()`. Full round-trip: start ACK → agent runs → result/failure notification via `BaseAgent::notifyUser()`.
- **Horizon**: `supervisor-social` (3 processes) required for `DispatchScheduledPosts` (every-minute job). All supervisors: default, marketing, media, hiring, content, growth, knowledge, agents, low, social.

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
