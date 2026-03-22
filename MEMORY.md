# MEMORY.md — Continuous Learning Log

> Auto-updated after every significant task. Check BEFORE starting any new work.
> Active lessons are short — if you need full context, see the subdirectory CLAUDE.md files.

---

## Active Lessons (scan before every task)

| # | Scope | Lesson | Date | Source |
|---|-------|--------|------|--------|
| 1 | git | Local proxy 127.0.0.1:43313 returns 403 intermittently — switch to GitHub token remote on FIRST retry, never after 4 attempts | 2026-03-22 | Phase 8 |
| 2 | pgvector | Always sanitize: `array_map('floatval', $embedding)` + PDO `?` bindings — never string-interpolate into raw SQL | 2026-03-22 | Phase 7 |
| 3 | frontend | Chart.js 4.x requires explicit `title: {display:false}` and `subtitle: {display:false}` in plugins config | 2026-03-22 | Phase 6 |
| 4 | seeder | AgentSkillsSeeder category is `'agent-skills'` not the agent's own name — check BOTH when querying counts | 2026-03-22 | Phase 5 |
| 5 | controller | Always grep `use App\Models` before adding a controller method that uses a Model directly | 2026-03-22 | Phase 7 |
| 6 | git | `bootstrap/cache/services.php` appears in git status after composer ops — add to .gitignore or commit separately | 2026-03-22 | Phase 7 |
| 7 | frontend | Alpine.js `orWhere` without a closure escapes preceding AND filters — always use grouped closure for multi-column search | 2026-03-22 | Phase 8 |
| 8 | jobs | ProcessTrends / AutoReplenish / RepurposeContent must log BEFORE acting and use cache-based cooldown keys to prevent spam | 2026-03-22 | Phase 9 |
| 9 | agents | Tool gating: check `task_type` column first, fall back to keyword regex when NULL (backward compat for existing jobs) | 2026-03-22 | Phase 9 |
| 10 | social | `SOCIAL_AUTO_POST_ENABLED=false` is the default — never auto-post without this flag explicitly set to true | 2026-03-22 | Phase 9 |
| 11 | social | Instagram Graph API: create container first (POST /{user-id}/media) then publish (POST /{user-id}/media_publish) — two-step | 2026-03-22 | Phase 9 |
| 12 | migrations | Every new migration must have a `down()` method that is the exact reverse of `up()` | 2026-03-22 | Phase 9 |
| 13 | social | `apiSocialHealth` returns `connected_accounts`/`scheduled_this_week` — frontend must map to `platforms`/`scheduled_count` | 2026-03-22 | Phase 9C |
| 14 | frontend | Alpine `x-show` on nested absolute modal inside slide-over requires the parent to be `relative` positioned | 2026-03-22 | Phase 9C |
| 15 | social | Rate limiter signal: `RuntimeException("RATE_LIMITED:{seconds}")` — catch by prefix in DispatchScheduledPosts, not generic catch | 2026-03-22 | Phase 9B |
| 16 | git | Local proxy 403 — no GITHUB_TOKEN in environment; all Phase 9 commits are local only until user supplies token | 2026-03-22 | Phase 9 |

---

## Resolved / Archived Lessons

| # | Scope | What was fixed | Fixed in | Commit |
|---|-------|---------------|----------|--------|
| R1 | pgvector | `KnowledgeBase::semanticSearch()` SQL injection — string interpolation | Phase 7 | — |
| R2 | pgvector | `Candidate::search()` SQL injection — same pattern as R1 | Phase 7 | 5e038fa |
| R3 | frontend | localStorage filter persistence — all 5 pages (jobs, candidates, content, system, knowledge) | Phase 8E | dd03541 |
| R4 | api | GitHub import URL normalization mismatch between PHP and JS | Phase 8D | f929be5 |
| R5 | api | Rate limiting split into 3 tiers on dashboard API routes | Phase 8 | 2b64940 |

---

## Session Log

### 2026-03-22 — Phase 9.0 (Memory Modularization)
- Slimmed root CLAUDE.md from 291 → ~140 lines
- Created 4 subdirectory CLAUDE.md files: database/, resources/views/, app/Http/Controllers/, app/Agents/
- Added Self-Correction Protocol to root CLAUDE.md
- Created this MEMORY.md with 12 active lessons seeded from Phase 5–9 knowledge
- Incorporated 18 improvement points into Phase 9 plan (11 added, 7 deferred to Phase 10)

### 2026-03-22 — Phase 9B/9C (Social Media Intelligence & Automation)
- 4 migrations: content_calendar, hashtag_sets, social_accounts, task_type on agent_jobs
- SocialPlatformService: InstagramService (full Graph API v19.0) + StubPlatformService ([SIMULATED])
- 6 automation jobs: DispatchScheduledPosts, FetchSocialMetrics, AutoReplenishContent, RepurposeContent, ProcessTrends, RefreshSocialTokens
- ContentAgent: 6 tools incl. select_hashtags; task_type gating with keyword regex fallback
- DashboardController: 18 new methods covering social CRUD, approve/reject/publish, health
- Frontend: social.blade.php (4 Alpine sub-components), campaigns.blade.php (rebuilt), content.blade.php (preview + add-to-calendar + hashtags), overview.blade.php (Social Intelligence + Content Velocity widgets)
- SOCIAL_AUTO_POST_ENABLED=false guards all auto-posting
- All 3 Phase 9 commits local only — push pending GITHUB_TOKEN from user
