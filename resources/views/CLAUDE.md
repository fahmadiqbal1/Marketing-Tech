# Frontend Rules — Marketing-Tech

> Domain-specific rules for Blade templates, Alpine.js, and UI components.
> Root rules: @../../CLAUDE.md

---

## Design Palette (always enforce)
| Element | Class / Hex |
|---------|-------------|
| Page background | `bg-slate-950` (`#020617`) |
| Card / sidebar | `bg-slate-900` / `bg-slate-800` (`#0f172a` / `#1e293b`) |
| Primary accent | `text-violet-400` / `bg-violet-600` (`#7c3aed` / `#8b5cf6`) |
| Warning | `bg-amber-500/20 text-amber-400` |
| Error | `bg-red-500/20 text-red-400` |
| Success | `bg-emerald-500/20 text-emerald-400` |

**Always verify** dark theme consistency after any Blade edit: `http://localhost:8080`

## Chart.js Plugin Config
When using Chart.js 4.x, **always** explicitly set both keys even when not using them:
```js
plugins: {
    title:    { display: false },
    subtitle: { display: false },
    legend:   { ... }
}
```
Omitting them causes `Cannot set properties of undefined (setting 'fullSize')` — Chart.js 4.4.0 crash. We pin **4.4.8** (CDN).

## Horizon Layout Override
File: `resources/views/vendor/horizon/layout.blade.php`
If `vendor:publish --tag=horizon-views` returns "No publishable resources":
```bash
cp vendor/laravel/horizon/resources/views/layout.blade.php resources/views/vendor/horizon/layout.blade.php
```
Then apply dark palette override in the `<style>` block.

## Frontend Design Checks
When editing Blade templates, Alpine.js, or Tailwind classes:
1. Check `http://localhost:8080` after changes — never trust PHP-only rendering
2. Test Alpine.js `x-show` and `x-for` in browser devtools
3. Use amber warnings for missing data (`bg-amber-500/20 text-amber-400`)
4. After any UI change: compare rendered output to the design plan before marking task done

## UI Verification Rule
When modifying any UI component:
1. Capture the rendered state visually (screenshot or manual browser check)
2. Compare against the design spec / plan
3. Iterate until the design matches — do NOT mark completed based on code review alone

## Client-Side Insights Pattern (overview.blade.php)
- Dynamic threshold: compare top value to `1.5× average` across peers — not hardcoded %
- Health endpoint: `fetch('/health')` with `AbortController` 3s timeout — silent catch
- Sort insights by severity: `{ error: 0, warning: 1, info: 2 }` — errors surface first
- Cap at 3 insights with `.slice(0, 3)`
- `top_failure_reason` comes from `apiJobs` response — attach to `this.stats` in `loadCosts()`

## Filter Persistence via localStorage
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
- Always reset `currentPage = 1` on restore
- Never persist page numbers — only filter values
- Key format: `filters_{page}` (e.g., `filters_jobs`, `filters_candidates`)
