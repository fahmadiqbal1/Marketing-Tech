# Controller & API Rules — Marketing-Tech

> Domain-specific rules for DashboardController and API endpoints.
> Root rules: @../../CLAUDE.md

---

## Controller Model Imports
Before adding a method that uses a Model directly, check the `use` statements at the top.
`Candidate` and `ContentItem` were missing from `DashboardController` and caused fatal errors.
```bash
grep 'use App\\Models' app/Http/Controllers/DashboardController.php
```

## Knowledge Category Mismatch
`AgentSkillsSeeder` stores entries under category `agent-skills`, NOT the agent's own name.
When querying knowledge counts per agent, check BOTH:
- `category = 'agent-skills'` WHERE `title LIKE '%agentname%'`
- `category = agentname` directly

## DashboardStatsService — Pagination Hybrid Pattern
When a method needs BOTH paginated data AND summary aggregates:
- Use `rescueArray` (not `rescuePaginated`) so you can return extra keys alongside pagination
- Call `$query->paginate($perPage)` inside the callback, then `array_merge($paginator->toArray(), [...])`
- Compute global counts in separate unfiltered queries so filter cards always show totals
- Fallback array must include `data: [], current_page: 1, last_page: 1, total: 0`

## Search Must Use Grouped Closure
Ungrouped `orWhere` escapes any preceding `AND` conditions:
```php
// WRONG — orWhere escapes the category filter
$query->where('title', 'ilike', "%{$s}%")->orWhere('content', 'ilike', "%{$s}%");

// CORRECT — closure keeps OR inside the AND
$query->where(function ($q) use ($s) {
    $q->where('title', 'ilike', "%{$s}%")->orWhere('content', 'ilike', "%{$s}%");
});
```
Apply this pattern to any multi-column search that combines with other filters.

## Rate Limiting Tiers (routes/web.php)
Split `Route::prefix('api')` into three throttle groups:
- `throttle:60,1` — all GET/polling endpoints
- `throttle:10,1` — write/action endpoints (CRUD, approvals, settings)
- `throttle:5,1` — heavy ops (GitHub import, social post dispatch)
Outer `DashboardBasicAuth` middleware is unchanged. Throttle goes on inner groups only.

## top_failure_reason Aggregation Pattern
```php
$topReason = AgentJob::where('status', 'failed')
    ->whereNotNull('error_message')
    ->latest()->limit(20)
    ->pluck('error_message')
    ->groupBy(fn ($m) => Str::limit($m, 60))
    ->sortByDesc(fn ($g) => $g->count())
    ->keys()->first();
```
Add `use Illuminate\Support\Str;`. Returns null if no failures — always guard with `?? null`.

## Social API Publish Pattern
When publishing via `apiPublishCalendarEntry()`:
1. Check `SOCIAL_AUTO_POST_ENABLED` feature flag first
2. Attempt real publish via SocialPlatformService if flag is on + account connected
3. Fall back to simulated metrics if flag off or no connected account
4. Always feed IterationEngine::recordPerformance() whether real or simulated
5. Always log SystemEvent with `[real]` or `[simulated]` tag
6. Never throw unhandled exceptions — wrap in try/catch, set status=failed on error
