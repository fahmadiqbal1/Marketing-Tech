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
| 17 | social | Twitter + TikTok use PKCE — store code_verifier in session during redirect, retrieve in callback; never skip state check | 2026-03-22 | Phase 9E |
| 18 | social | Facebook: exchange code→short token→long token→page token (3 steps); store user_access_token in metadata for refresh | 2026-03-22 | Phase 9E |
| 19 | social | YouTube tokens expire every 3600s — always check isTokenExpired() before publish; refresh_token only returned on first auth (prompt=consent) | 2026-03-22 | Phase 9E |
| 20 | social | TikTok response wraps in data.{} — use ->json('data') not ->json() directly; publish_id is async, not a final post ID | 2026-03-22 | Phase 9E |
| 21 | social | SocialPlatformService::driver() now throws InvalidArgumentException for unknown platforms — callers must use platforms() helper to validate | 2026-03-22 | Phase 9E |
| 22 | social | CSRF state: LinkedIn/Facebook/YouTube getAuthorizationUrl() returns array{url,state}; store state in session, validate in callback, forget after use | 2026-03-23 | Phase 9F |
| 23 | social | DispatchScheduledPosts simulated fallback removed — entries without real account stay `scheduled`; IterationEngine only fed on real publishes | 2026-03-23 | Phase 9F |
| 24 | social | TikTok publish is async: init returns publish_id not video_id; PollTikTokPublishStatus polls up to 5×30s until PUBLISH_COMPLETE | 2026-03-23 | Phase 9F |
| 25 | social | YouTube resumable upload URI stored in metadata['youtube_upload_uri'] immediately after Step 1; reused on retry; cleared on success | 2026-03-23 | Phase 9F |
| 26 | social | ensurePublicUrl() converts local storage paths to S3 temporaryUrl (2h); falls back to disk->url() for local disk | 2026-03-23 | Phase 9F |
| 27 | social | SocialAccount.access_token + refresh_token use 'encrypted' cast — stored as Laravel Crypt ciphertext, never plaintext in DB | 2026-03-23 | Phase 9F |
| 28 | controller | PATCH /api/social-accounts/{id} updates only metadata (merge, not replace) — use apiPatch() JS helper | 2026-03-23 | Phase 9F |
| 29 | horizon | Always add supervisor-{queue} for every named queue jobs use — missing supervisor-social caused DispatchScheduledPosts to silently stall | 2026-03-23 | Audit |
| 30 | controller | POST /api/campaigns route was missing despite UI calling it — always verify route exists before marking feature done | 2026-03-23 | Audit |
| 31 | social | Instagram OAuth lacked CSRF state — getAuthorizationUrl() must return array{url,state} for ALL 6 platforms (Instagram was returning string) | 2026-03-23 | Audit |
| 32 | jobs | Social jobs need public string \$queue declared on the class; relying solely on Schedule::job($j, 'queue') means direct dispatch falls to 'default' | 2026-03-23 | Audit |
| 33 | frontend | Never use Math.random() for chart data — replace with real API call; viewDetail() must fetch /campaigns/{id}/detail to drive chart | 2026-03-23 | Audit |
| 34 | agents | BaseAgent.$criticalTools defines tools that trigger Telegram notification on exhausted retries — override per agent subclass as needed | 2026-03-23 | Phase 10 |
| 35 | social | SocialCredential.client_id/client_secret use 'encrypted' cast — validateCredentials() must decrypt via model attribute, never raw DB value | 2026-03-23 | Phase 10 |
| 36 | hiring | PruneRejectedCandidates uses stage_updated_at (not updated_at) for 30-day retention — ensure stage_updated_at is always set on pipeline transitions | 2026-03-23 | Phase 10 |
| 37 | media | Runway Gen-3 video generation is synchronous polling — max 60s (12×5s) within tool; long videos need async job instead | 2026-03-23 | Phase 10 |
| 38 | controller | mb_strlen($content, 'UTF-8') for platform char limits — emojis count as 2 bytes in strlen() but 1 char in mb_strlen; always use mb variant | 2026-03-23 | Phase 10 |

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

### 2026-03-23 — Launch Readiness Audit (3 commits)
- C1: POST /api/campaigns route + apiCreateCampaign/Pause/Resume; Instagram CSRF state; supervisor-social in Horizon
- C2: Campaign detail chart → real agent-runs/outputs data (apiCampaignDetail); Delete+Reject buttons in calendar modal; video/short/live content types; resumeCampaign() JS method
- C3: approveEntry() try/catch; moderation_status index migration; $queue on all 5 social jobs
- Audit identified 3 critical, 3 high, 4 medium, 3 low issues; all C/H/M resolved except RBAC (deferred)

### 2026-03-23 — Phase 10: Final Launch Implementation
- Social credential UI (Settings tab) with per-platform cards, rollback-safe save+verify, health badges — complete
- Pipeline agent cards enhanced: current_job field, progress bar, idle state, 5-step feed
- HiringAgent: platform metadata on job posts, PruneRejectedCandidates job (30-day retention), apiCandidateApply endpoint
- MediaAgent: generate_image (Stability AI), remove_background (Remove.bg), enhance_image (Stability AI upscale), generate_video (Runway Gen-3)
- Pipeline integrity: PLATFORM_CHAR_LIMITS with mb_strlen, TikTok poll timeout marks entry failed, BaseAgent critical tool Telegram notify
- ProcessTrends: source_agent_job_id linkage in calendar metadata; TelegramController: user error notification in catch block
- Calendar modal: last_error red error box for failed entries
- Credential health: RefreshCredentialStatus nightly job; token_expires_soon field in apiSocialAccounts; amber badge in accounts tab
- GeneratedOutput::contentVariation() BelongsTo relationship added

### 2026-03-23 — Phase 9F (Social Platform Hardening — 6 commits)
- Moderation gate: ContentCalendar.scheduledNow() requires moderation_status IN (approved, auto_approved)
- Removed simulated fallback from DispatchScheduledPosts — no fake metrics fed to IterationEngine
- Flash banners in social.blade.php: success/error/info with Alpine auto-dismiss (6s)
- CSRF state validation for LinkedIn, Facebook, YouTube OAuth callbacks
- Dry-run mode: SOCIAL_DRY_RUN env flag — logs [DRY_RUN] SystemEvent, skips real API
- Rate-limit requeue now creates SystemEvent(warning) for audit trail
- Scheduling conflict prevention: ±15 min check in apiCreate/UpdateCalendarEntry
- PollTikTokPublishStatus job: async poller (5×30s), updates external_post_id on PUBLISH_COMPLETE
- YouTube Shorts validation: SystemEvent(warning) if duration_seconds > 60
- YouTube resumable session recovery: metadata['youtube_upload_uri'] stored/reused/cleared
- ensurePublicUrl() static helper: local storage path → S3 temporaryUrl (2h)
- RepurposeContent: added youtube→video to content type match
- RefreshSocialTokens: fixed "stub" log message
- LinkedIn org selection: socialLinkedInCallback fetches orgs, stores in metadata; UI dropdown
- Facebook multi-page: one SocialAccount per page via updateOrCreate
- PATCH /api/social-accounts/{id}: metadata-merge endpoint + apiPatch() JS helper
- SocialAccount encrypted casts: access_token + refresh_token stored as Crypt ciphertext
- Migration 2026_03_23_100001: re-encrypts existing plaintext tokens idempotently

### 2026-03-22 — Phase 9E (Real Social API Integrations — all 6 platforms)
- TwitterService: OAuth 2.0 PKCE, tweet + thread, organic_metrics, refresh_token grant
- LinkedInService: ugcPosts, image/article support, org share statistics, 60-day tokens
- FacebookService: 3-step token exchange (short→long→page), post_insights, fb_exchange_token refresh
- TikTokService: PKCE, PULL_FROM_URL video init + PHOTO_STORY, video.list metrics, async publish_id
- YouTubeService: Google OAuth (prompt=consent), resumable upload, #Shorts, 1h token expiry
- StubPlatformService deleted — no simulations anywhere in the pipeline
- social.blade.php: all 6 platforms use Connect via OAuth (no token modal), YouTube added
- SocialPlatformService factory throws on unknown platform; per-platform rate limits

### 2026-03-22 — Phase 9B/9C (Social Media Intelligence & Automation)
- 4 migrations: content_calendar, hashtag_sets, social_accounts, task_type on agent_jobs
- SocialPlatformService: InstagramService (full Graph API v19.0) + StubPlatformService ([SIMULATED])
- 6 automation jobs: DispatchScheduledPosts, FetchSocialMetrics, AutoReplenishContent, RepurposeContent, ProcessTrends, RefreshSocialTokens
- ContentAgent: 6 tools incl. select_hashtags; task_type gating with keyword regex fallback
- DashboardController: 18 new methods covering social CRUD, approve/reject/publish, health
- Frontend: social.blade.php (4 Alpine sub-components), campaigns.blade.php (rebuilt), content.blade.php (preview + add-to-calendar + hashtags), overview.blade.php (Social Intelligence + Content Velocity widgets)
- SOCIAL_AUTO_POST_ENABLED=false guards all auto-posting
- All 3 Phase 9 commits local only — push pending GITHUB_TOKEN from user
