# CLAUDE.md — Marketing-Tech Platform

This file is read at every session start. It documents the project's conventions,
recurring pitfalls, and the feedback loop Claude must follow.

---

## Project Overview

Laravel 11 AI marketing platform with:
- **Agents**: `BaseAgent` → specialised agents (Content, SEO, Email…) run via Laravel Horizon queues
- **IterationEngineService**: feedback loop — patterns, winner selection, tool reliability, circuit breaker
- **pgvector**: PostgreSQL vector extension for RAG (`embedding <=>` cosine distance)
- **Alpine.js + Tailwind CSS**: frontend reactivity and styling (dark slate-950 palette)
- **Chart.js 4.4.8** (CDN): pinned version — 4.4.0 had a `fullSize` crash
- **Laravel Horizon**: SPA published to `resources/views/vendor/horizon/layout.blade.php` with dark-theme CSS override

---

## Mandatory Feedback Loop (Claude Must Follow Every Task)

```
1. PLAN   — write todos with TodoWrite before touching any file
2. READ   — always read relevant files before editing (never edit blind)
3. EXECUTE — one todo at a time, mark in_progress before starting
4. VERIFY  — after each change, re-read the edited section to confirm correctness
5. COMMIT  — commit as soon as a logical unit is done (do not batch across features)
6. PUSH    — push immediately after commit; if local proxy (127.0.0.1:43313) returns 403,
             fall back to GitHub token remote on the FIRST retry (not after 4 failures)
7. CLEAN   — mark todo completed; check `git status` is clean before declaring done
```

If any step fails: stop, diagnose root cause, adjust — do NOT brute-force retry the same action.

---

## Git Rules

### Branch
Always work on: `claude/master-agent-system-vjyTs`

### Push
```bash
# Primary (local proxy)
git push -u origin claude/master-agent-system-vjyTs

# If 403 on first attempt — switch to token immediately (do not retry proxy 4 times)
git remote set-url origin https://<GITHUB_TOKEN>@github.com/fahmadiqbal1/Marketing-Tech.git
git push -u origin claude/master-agent-system-vjyTs
```

### Commit checklist (before every commit)
```bash
git status          # must show nothing untracked that belongs in the repo
git diff --cached   # review staged diff — no debug code, no .env, no vendor changes
```

### Files to never commit
- `.env`, `.env.*`
- `vendor/` (Composer managed)
- `bootstrap/cache/*.php` — this file is auto-generated; only commit if it was explicitly
  regenerated as part of the task. If it appears in `git status` incidentally, add to `.gitignore`
  or commit it in a separate "chore: regenerate bootstrap cache" commit.

---

## Lessons Learned — Recurring Mistakes to Avoid

### 1. Staging incomplete — stop hook will fail
Always run `git status` after the last edit before committing. The stop hook checks for
untracked files and unstaged changes. `bootstrap/cache/services.php` has caught us twice.

### 2. Local proxy 403 — fall back to token on first retry
The 127.0.0.1:43313 proxy returns 403 intermittently. After ONE failed push attempt, set
the remote URL to the GitHub HTTPS token URL. Do not retry the broken proxy 4+ times.

### 3. Knowledge category mismatch
`AgentSkillsSeeder` stores entries under category `agent-skills`, NOT the agent's own name.
When querying knowledge counts per agent, always check BOTH:
- `category = 'agent-skills'` WHERE `title LIKE '%agentname%'`
- `category = agentname` directly

### 4. Variation label assumptions
Never assume variation label `A` exists. Always query the earliest/any variation for a job:
```php
ContentVariation::where('agent_job_id', $job->id)->orderBy('created_at')->value('id')
```

### 5. Model vs migration alignment
Before touching a model, check if the migration already has a column the model doesn't use
(e.g., `deleted_at` existed in DB for `knowledge_base` but model never had `SoftDeletes`).
Run: `grep -r 'deleted_at\|softDeletes' database/migrations/ app/Models/`

### 6. SQL injection in raw DB queries
All pgvector queries use raw SQL (`selectRaw`, `whereRaw`, `orderByRaw`).
**Always** sanitize embedding arrays with `array_map('floatval', $embedding)` before use.
**Always** use `?` PDO bindings — never string-interpolate user or external data.

### 7. Chart.js plugin config
When using Chart.js 4.x, always explicitly set `title: { display: false }` and
`subtitle: { display: false }` in the plugins config even when not using those plugins.
Omitting them can cause `Cannot set properties of undefined (setting 'fullSize')`.

### 8. Horizon layout override
Horizon's SPA uses its own stylesheet. To match platform design:
- File: `resources/views/vendor/horizon/layout.blade.php`
- If `vendor:publish --tag=horizon-views` returns "No publishable resources", manually copy:
  `cp vendor/laravel/horizon/resources/views/layout.blade.php resources/views/vendor/horizon/layout.blade.php`
- Key palette: `body: #020617`, sidebar: `#0f172a`, cards: `#1e293b`, accent: `#7c3aed/#8b5cf6`

### 9. CLAUDE.md must be updated proactively
After completing any significant feature, bug fix, or audit — update this file.
Do NOT wait for the user to ask. Treat it as the last step of every session.

### 10. Frontend design — always check the actual rendered output
When editing Blade templates, Alpine.js, or Tailwind classes:
- Check the running app at `http://localhost:8080` (docker) after changes
- Verify dark theme consistency: bg-slate-950/900 backgrounds, violet accent, slate text
- Amber warnings for missing data (`bg-amber-500/20 text-amber-400`)
- Test Alpine.js `x-show` and `x-for` in browser devtools (not just PHP rendering)

### 11. DashboardStatsService — pagination hybrid pattern
When a service method needs BOTH paginated data AND summary aggregates (e.g. `getJobs`, `getCandidates`):
- Use `rescueArray` (not `rescuePaginated`) so you can return extra keys alongside pagination
- Call `$query->paginate($perPage)` inside the callback, then `array_merge($paginator->toArray(), [...])`
- Compute global counts in separate unfiltered queries so filter cards always show totals
- Fallback array must include `data: [], current_page: 1, last_page: 1, total: 0`

### 12. Candidate::search() SQL injection — already fixed
`Candidate::search()` had the same `'{$vec}'::vector` interpolation bug as `KnowledgeBase::semanticSearch()`.
Fixed in commit 5e038fa — uses `array_map('floatval')` + PDO `?` bindings. Pattern to follow for any new vector queries.

### 13. Controller model imports — always check before adding new endpoints
Before adding a new controller method that uses a Model directly (e.g. `ContentItem::findOrFail`),
check if the Model is already imported at the top of the controller. `Candidate` and `ContentItem`
were missing from `DashboardController`'s use statements and had to be added.

### 14. AgentSkillsSeeder idempotency — now content-hash aware
The seeder previously skipped any title+category match. It now computes `md5` of first 1000 chars
and only skips if `content_hash` matches. Changed manifests trigger soft-delete of old entry+chunks
and re-store. This is safe to run multiple times.

### 15. GitHub import progress tracking pattern
Cache key: `'github-import:' . md5($repoUrl)` — TTL 1hr.
Payload: `{status: running|completed|failed, ingested: N, total: N, repo: "owner/repo"}`.
Frontend polls `/dashboard/api/knowledge/import-status?repo_url=...` every 3 seconds via `setInterval`.
Stop polling when status is `completed` or `failed`. Always clear `importPollTimer` before starting a new import.

### 16. GitHub import URL normalization — normalize before md5 in BOTH PHP and JS
Cache keys must be identical regardless of how user types the URL.
PHP (`IngestGitHubRepo::normalizeRepoUrl` + `DashboardController::apiKnowledgeImportStatus`):
```php
$url = strtolower(trim($url));
$url = preg_replace('#\.git$#', '', $url);
return rtrim($url, '/');
```
JS (`knowledge.blade.php` before `apiPost` + `pollImportProgress`):
```js
const repoUrl = raw.trim().toLowerCase().replace(/\.git$/, '').replace(/\/$/, '');
```
Cache TTL should be `now()->addHours(6)` — not 3600 — so progress survives long imports.

### 17. apiKnowledge() search must use grouped closure
Ungrouped `orWhere` escapes any preceding `AND` conditions (e.g. category filter):
```php
// WRONG — orWhere escapes the category filter
$query->where('title', 'ilike', "%{$s}%")->orWhere('content', 'ilike', "%{$s}%");

// CORRECT — closure keeps OR inside the AND
$query->where(function ($q) use ($s) {
    $q->where('title', 'ilike', "%{$s}%")->orWhere('content', 'ilike', "%{$s}%");
});
```
Apply the same closure pattern to any multi-column search that combines with other filters.

### 18. Rate limiting pattern for dashboard API routes
Split the `Route::prefix('api')` group into three throttle tiers (see `routes/web.php`):
- `throttle:60,1` — all GET/polling endpoints (safe for 2 open tabs + 12s auto-refresh)
- `throttle:10,1` — write/action endpoints (approvals, settings, KB CRUD)
- `throttle:5,1` — heavy ops (GitHub import dispatch)
Outer `DashboardBasicAuth` middleware is unchanged. Throttle middleware goes on inner groups only.

### 19. Client-side insights pattern (overview.blade.php)
- Use dynamic threshold: compare top value to `1.5× average` across peers — not a hardcoded %
- Health endpoint: fetch `/health` with `AbortController` 3s timeout — silent catch so UI degrades gracefully
- Sort insights by severity: `{ error: 0, warning: 1, info: 2 }` — errors always surface first
- Cap at 3 insights with `.slice(0, 3)` — prevents UI noise when multiple conditions trigger
- `top_failure_reason` comes from `apiJobs` response (aggregated server-side) — attach to `this.stats` in `loadCosts()`

### 20. Filter persistence via localStorage — key per page, validate on restore
Pattern (applied to jobs, candidates, content, system, knowledge):
```js
// init(): restore + validate
const saved = JSON.parse(localStorage.getItem('filters_jobs') ?? '{}');
const validStatuses = ['', 'pending', 'running', 'completed', 'failed'];
this.statusFilter = validStatuses.includes(saved.statusFilter ?? '') ? (saved.statusFilter ?? '') : '';
// free-form keys validated post-load against backend data
if (this.agentTypeFilter && !Object.keys(this.byAgentType).includes(this.agentTypeFilter)) {
    this.agentTypeFilter = '';
}

// load(): persist before fetch
localStorage.setItem('filters_jobs', JSON.stringify({ statusFilter: this.statusFilter, ... }));
```
Always reset `currentPage = 1` on restore. Never persist page numbers — only filter values.

### 21. top_failure_reason aggregation pattern
Efficiently find the most common error message from recent failures without a GROUP BY query:
```php
$topReason = AgentJob::where('status', 'failed')
    ->whereNotNull('error_message')
    ->latest()->limit(20)
    ->pluck('error_message')
    ->groupBy(fn ($m) => Str::limit($m, 60))   // group by first 60 chars
    ->sortByDesc(fn ($g) => $g->count())
    ->keys()->first();
```
Add `use Illuminate\Support\Str;` to controller. Returns null if no failures — always guard with `?? null`.

---

## Architecture Quick Reference

### Agent execution flow
```
Queue Job → BaseAgent::handle()
  → buildPromptContext()       (RAG + iteration patterns)
  → AI loop (tool calls)
    → executeToolWithReliability()
        → isToolBlocked()      (circuit breaker check)
        → executeTool()
        → recordToolOutcome()  (updates failure streak)
  → storeOutput()              (only if variation exists)
  → syncOutputWinner()
```

### Circuit breaker (IterationEngineService)
- `isToolBlocked($name)` — cache key `tool:blocked:{name}`
- `recordToolOutcome($name, $success)` — increments `tool:fail_streak:{name}`
- Trips after **5 consecutive failures**, blocks for **120 seconds**

### KnowledgeBase soft deletes
- `deleted_at` column exists since original migration
- Model uses `SoftDeletes` trait — all `->delete()` calls set `deleted_at`
- 50k cap pruning now archives (soft deletes) instead of hard deleting training data

### Vector search (KnowledgeBase::semanticSearch)
- Cosine similarity threshold: **0.65**
- Always sanitize: `array_map('floatval', $embedding)` before building `[f1,f2,...]` string
- PDO bindings: `selectRaw("...", [$vec])` — never string interpolate

### DB integrity constraints (Phase F migrations)
- `agent_steps.agent_job_id` — NOT NULL + FK → `agent_jobs` CASCADE DELETE
- `generated_outputs.content_variation_id` — NOT NULL + FK → `content_variations` CASCADE DELETE

---

## Project Stack Versions

| Component | Version | Notes |
|-----------|---------|-------|
| PHP | 8.2+ | |
| Laravel | 11.x | |
| Laravel Horizon | latest | Published layout override |
| Chart.js | **4.4.8** | Pinned — 4.4.0 has fullSize crash |
| Alpine.js | 3.x | CDN |
| Tailwind CSS | 3.x | |
| PostgreSQL | 15+ | pgvector extension required |
| Redis | 7.x | Cache + Horizon queue |

---

## Settings & Hooks

Claude settings at: `~/.claude/settings.json`

**Stop hook** (`~/.claude/stop-hook-git-check.sh`): fires after every response and blocks
if there are uncommitted changes or unpushed commits. If it fires, resolve immediately —
do not continue to the next task with a dirty working tree.

---

## Pre-Task Checklist (run mentally before every coding task)

- [ ] Have I read the files I'm about to change?
- [ ] Have I checked `git status` — is the tree clean?
- [ ] Do I have a TodoWrite list for multi-step work?
- [ ] Are there any database constraints or model traits that differ from the migration schema?
- [ ] Will this change affect any raw SQL / pgvector queries (injection risk)?
- [ ] Does this change any Blade template — does it need a design check against the platform palette?
