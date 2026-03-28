# Production Launch Checklist — Marketing-Tech Platform

## Infrastructure

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] `LOG_LEVEL=warning`
- [ ] `LOG_CHANNEL=daily`
- [ ] `SESSION_DRIVER=redis`
- [ ] `CACHE_STORE=redis`
- [ ] `CACHE_DRIVER=redis`
- [ ] `QUEUE_CONNECTION=redis`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `SESSION_SAME_SITE=lax`
- [ ] All `CHANGE_ME` env vars replaced with real values
- [ ] `APP_KEY` generated: `php artisan key:generate`

## Required Secrets (all must be non-empty, non-placeholder)

- [ ] `DB_PASSWORD` — PostgreSQL password
- [ ] `OPENAI_API_KEY` — at minimum one AI key must be set
- [ ] `ANTHROPIC_API_KEY`
- [ ] `TELEGRAM_BOT_TOKEN`
- [ ] `TELEGRAM_WEBHOOK_SECRET` — used for HMAC verification
- [ ] `TELEGRAM_ALLOWED_USERS` — comma-separated chat IDs
- [ ] `TELEGRAM_ADMIN_CHAT_ID`
- [ ] `DASHBOARD_PASSWORD` — Basic Auth password (not default)

## Database

- [ ] `php artisan migrate --force` (run against production DB)
- [ ] pgvector extension enabled: `CREATE EXTENSION IF NOT EXISTS vector;`
- [ ] pg_trgm enabled: `CREATE EXTENSION IF NOT EXISTS pg_trgm;`
- [ ] Verify migration ran cleanly: check `migrations` table
- [ ] Database backup scheduled (before first deploy)

## Queue & Workers

- [ ] Redis is reachable from app container: `redis-cli -h redis ping`
- [ ] Horizon starts: `php artisan horizon:status`
- [ ] `php artisan horizon:terminate` → container restart to pick up new code
- [ ] All 10 supervisors online in Horizon dashboard (`/horizon`)
- [ ] `DispatchScheduledPosts` scheduled job appears in Horizon
- [ ] `SOCIAL_AUTO_POST_ENABLED` — deliberately set (not left as false by accident)
- [ ] `SOCIAL_DRY_RUN=false` for real publishing (set `true` for staging)

## Storage

- [ ] MinIO / S3 bucket created and writable
- [ ] `MINIO_BUCKET` (or `AWS_BUCKET`) set correctly
- [ ] `php artisan storage:link` (if using local disk)
- [ ] Storage write test passes: `GET /health` → `checks.storage.ok = true`

## Social Platform OAuth

- [ ] Each platform's client_id + client_secret stored via dashboard Settings
- [ ] Redirect URIs registered in each platform's developer console:
  - Instagram: `APP_URL/dashboard/social/auth/instagram/callback`
  - Twitter: `APP_URL/dashboard/social/auth/twitter/callback`
  - LinkedIn: `APP_URL/dashboard/social/auth/linkedin/callback`
  - Facebook: `APP_URL/dashboard/social/auth/facebook/callback`
  - TikTok: `APP_URL/dashboard/social/auth/tiktok/callback`
  - YouTube: `APP_URL/dashboard/social/auth/youtube/callback`

## Telegram Bot

- [ ] Webhook registered: `php artisan telegram:register-webhook`
- [ ] Verify webhook active: Telegram getWebhookInfo API
- [ ] Bot added to the correct chat / private messages enabled

## Caching & Performance

- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`
- [ ] `php artisan event:cache`
- [ ] After any config/route change: `php artisan optimize:clear && php artisan optimize`

## Nginx / SSL

- [ ] HTTPS certificate installed (Let's Encrypt or other)
- [ ] HTTP → HTTPS redirect active
- [ ] `HSTS` header present in response: `Strict-Transport-Security`
- [ ] CSP header present: `Content-Security-Policy`
- [ ] `X-Frame-Options: DENY` header present
- [ ] `server_tokens off` in nginx.conf

## Health Check

- [ ] `GET /health` returns `{ ok: true, status: "healthy" }`
- [ ] All 4 checks green: `database`, `redis`, `storage`, `cache`
- [ ] pgvector check: `checks.database.pgvector = "enabled"`
- [ ] Queue lag < 300s: `checks.database.worker_healthy = true`

## Tests

- [ ] `php artisan test` — all 63 tests pass
- [ ] No failed tests before deploy

## Monitoring

- [ ] Horizon dashboard accessible at `/horizon` (BasicAuth protected)
- [ ] Log rotation confirmed: daily logs with 14-day retention
- [ ] Alert on `LOG_LEVEL=error` events (Telegram notification via SystemEvent)
- [ ] Failed jobs alert: check `failed_jobs` table is empty post-deploy

## Final Sign-off

- [ ] Smoke test all 8 API endpoints return 200:
  - `GET /health`
  - `GET /dashboard/api/stats`
  - `GET /dashboard/api/jobs`
  - `GET /dashboard/api/campaigns`
  - `GET /dashboard/api/social/health`
  - `GET /dashboard/api/candidates`
  - `GET /dashboard/api/workflows`
  - `GET /dashboard/api/content`
- [ ] Agent page `/agent` loads and can run a test task
- [ ] Social credentials page shows platform status
- [ ] Mobile layout tested at 375px viewport width
- [ ] All 5 custom error pages render correctly (403, 404, 419, 500, 503)
