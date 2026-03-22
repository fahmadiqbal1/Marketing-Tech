@extends('layouts.app')
@section('title', 'Overview')
@section('subtitle', 'Live platform status and activity')

@section('content')
<div x-data="overviewApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    {{-- ── System Insights ─────────────────────────────────────── --}}
    <template x-if="insights.length">
        <div class="space-y-2">
            <template x-for="ins in insights" :key="ins.msg">
                <a :href="ins.action ?? '#'"
                   class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm transition-opacity hover:opacity-80"
                   :class="{
                       'bg-amber-500/10 text-amber-300 border border-amber-500/20': ins.type === 'warning',
                       'bg-red-500/10 text-red-300 border border-red-500/20':       ins.type === 'error',
                       'bg-sky-500/10 text-sky-300 border border-sky-500/20':       ins.type === 'info',
                   }">
                    <span x-text="ins.type === 'error' ? '⚠' : ins.type === 'warning' ? '●' : 'ℹ'" class="shrink-0 text-base leading-none"></span>
                    <span x-text="ins.msg" class="flex-1"></span>
                    <span class="text-xs opacity-50 shrink-0">→</span>
                </a>
            </template>
        </div>
    </template>

    {{-- ── Stat cards ─────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

        {{-- Total Workflows --}}
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Total Workflows</p>
                <div class="w-8 h-8 rounded-lg bg-violet-500/10 flex items-center justify-center">
                    <svg class="w-4 h-4 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white" x-text="totalWorkflows">–</p>
            <p class="text-xs text-slate-500 mt-1">
                <span class="text-emerald-400" x-text="(stats.workflows?.completed ?? 0) + ' completed'"></span>
                &nbsp;·&nbsp;
                <span class="text-red-400" x-text="(stats.workflows?.failed ?? 0) + ' failed'"></span>
            </p>
        </div>

        {{-- Active Jobs (clickable) --}}
        <div class="stat-card cursor-pointer hover:ring-1 hover:ring-slate-600 transition-all"
             @click="window.location='/dashboard/jobs?status=running'">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Active Jobs</p>
                <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center">
                    <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white" x-text="stats.active_jobs ?? 0">–</p>
            <p class="text-xs text-slate-500 mt-1">
                <span x-text="(stats.queue_depth ?? 0) + ' queued'"></span>
                <span class="ml-1 text-xs text-brand-400">→ view jobs</span>
            </p>
        </div>

        {{-- AI Cost Today (with trend) --}}
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">AI Cost Today</p>
                <div class="w-8 h-8 rounded-lg bg-amber-500/10 flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <div class="flex items-end gap-2">
                <p class="text-3xl font-bold text-white" x-text="'$' + (stats.ai_cost_today ?? 0).toFixed(4)">–</p>
                <span class="mb-1 text-xs font-medium flex items-center gap-0.5"
                      :class="costTrend > 0 ? 'text-red-400' : costTrend < 0 ? 'text-emerald-400' : 'text-slate-500'"
                      x-show="stats.ai_cost_yesterday != null && stats.ai_cost_today != null">
                    <template x-if="costTrend > 0">
                        <span>↑ <span x-text="Math.abs(costTrend) + '%'"></span></span>
                    </template>
                    <template x-if="costTrend < 0">
                        <span>↓ <span x-text="Math.abs(costTrend) + '%'"></span></span>
                    </template>
                    <template x-if="costTrend === 0">
                        <span>→ flat</span>
                    </template>
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">
                <span x-text="'$' + (stats.ai_cost_week ?? 0).toFixed(4) + ' this week'"></span>
            </p>
        </div>

        {{-- Pending Approval (clickable) --}}
        <div class="stat-card cursor-pointer transition-all"
             :class="pendingApproval > 0 ? 'border-orange-500/40 hover:ring-1 hover:ring-orange-500/50' : 'hover:ring-1 hover:ring-slate-600'"
             @click="window.location='/dashboard/workflows?status=owner_approval'">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Needs Approval</p>
                <div class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center">
                    <svg class="w-4 h-4 text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold" :class="pendingApproval > 0 ? 'text-orange-400' : 'text-white'" x-text="pendingApproval">–</p>
            <p class="text-xs text-slate-500 mt-1">
                <span>workflows awaiting sign-off</span>
                <span class="ml-1 text-xs text-brand-400" x-show="pendingApproval > 0">→ review</span>
            </p>
        </div>
    </div>

    {{-- ── Middle row: charts ──────────────────────────────────── --}}
    <div class="grid grid-cols-3 gap-4 mb-6">

        {{-- Workflow status breakdown --}}
        <div class="stat-card col-span-1">
            <h3 class="text-sm font-semibold text-white mb-4">Workflow Status</h3>
            <canvas id="statusChart" height="180"></canvas>
        </div>

        {{-- AI cost chart with time range --}}
        <div class="stat-card col-span-2">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-white">AI Cost History</h3>
                <div class="flex bg-slate-800/60 border border-slate-700/50 rounded-lg p-0.5 gap-0.5">
                    <template x-for="d in [7, 14, 30]" :key="d">
                        <button @click="costDays = d; loadCosts()"
                            class="px-2.5 py-1 rounded-md text-xs font-medium transition-all"
                            :class="costDays === d ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white'"
                            x-text="d + 'd'"></button>
                    </template>
                </div>
            </div>
            <canvas id="costChart" height="130"></canvas>
        </div>
    </div>

    {{-- ── AI Cost Breakdown + Agent Performance ───────────────── --}}
    <div class="grid grid-cols-2 gap-4 mb-6">

        {{-- Cost breakdown by model --}}
        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">Cost by Model</h3>
            <template x-if="!costBreakdown.length">
                <p class="text-xs text-slate-500 py-4 text-center">No AI requests recorded yet.</p>
            </template>
            <div class="space-y-2">
                <template x-for="row in costBreakdown" :key="row.model">
                    <div class="flex items-center justify-between text-xs">
                        <div class="flex-1 min-w-0">
                            <p class="text-slate-300 truncate" x-text="row.model"></p>
                            <p class="text-slate-500" x-text="row.provider + ' · ' + row.requests + ' reqs'"></p>
                        </div>
                        <span class="ml-3 font-medium text-amber-400" x-text="'$' + Number(row.total_cost).toFixed(4)"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Agent job distribution --}}
        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">Jobs by Agent</h3>
            <template x-if="!Object.keys(agentTypes).length">
                <p class="text-xs text-slate-500 py-4 text-center">No agent jobs recorded yet.</p>
            </template>
            <div class="space-y-2">
                <template x-for="[type, count] in Object.entries(agentTypes)" :key="type">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-300 w-32 truncate" x-text="type"></span>
                        <div class="flex-1 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                            <div class="h-full bg-brand-500 rounded-full transition-all"
                                 :style="'width:' + Math.round(count / maxAgentCount * 100) + '%'"></div>
                        </div>
                        <span class="text-xs text-slate-400 w-6 text-right" x-text="count"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Bottom row ──────────────────────────────────────────── --}}
    <div class="grid grid-cols-3 gap-4">

        {{-- Recent Workflows --}}
        <div class="stat-card col-span-2">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-white">Recent Workflows</h3>
                <a href="/dashboard/workflows" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">View all →</a>
            </div>
            <div class="space-y-2">
                <template x-if="stats.recent_workflows?.length === 0">
                    <p class="text-sm text-slate-500 text-center py-6">No workflows yet. Send a command to your Telegram bot to get started.</p>
                </template>
                <template x-for="wf in (stats.recent_workflows ?? [])" :key="wf.id">
                    <div class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-slate-700/30 transition-colors cursor-pointer" @click="window.location='/dashboard/workflows'">
                        <div class="w-2 h-2 rounded-full flex-shrink-0"
                             :class="{
                                'bg-emerald-400': wf.status === 'completed',
                                'bg-red-400': wf.status === 'failed',
                                'bg-orange-400': wf.status === 'owner_approval',
                                'bg-slate-400': wf.status === 'cancelled',
                                'bg-violet-400': !['completed','failed','owner_approval','cancelled'].includes(wf.status)
                             }"></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-slate-200 truncate" x-text="wf.name"></p>
                            <p class="text-xs text-slate-500" x-text="wf.type + ' · ' + relativeTime(wf.created_at)"></p>
                        </div>
                        <span class="badge text-xs flex-shrink-0" :class="statusBadge(wf.status)" x-text="wf.status.replace('_', ' ')"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Recent System Events --}}
        <div class="stat-card col-span-1">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-white">System Events</h3>
                <a href="/dashboard/system" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">View all →</a>
            </div>
            <div class="space-y-2">
                <template x-if="!stats.recent_events?.length">
                    <p class="text-sm text-slate-500 text-center py-6">No events yet</p>
                </template>
                <template x-for="ev in (stats.recent_events ?? [])" :key="ev.id">
                    <div class="p-2.5 rounded-lg border border-slate-700/40 hover:border-slate-600/60 transition-colors">
                        <div class="flex items-start gap-2">
                            <span class="badge mt-0.5 flex-shrink-0" :class="statusBadge(ev.level)" x-text="ev.level"></span>
                            <div class="min-w-0">
                                <p class="text-xs text-slate-300 truncate" x-text="ev.event"></p>
                                <p class="text-xs text-slate-500 mt-0.5" x-text="relativeTime(ev.created_at)"></p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

</div>
@endsection

@section('scripts')
<script>
function overviewApp() {
    return {
        ...dashboardState(),
        stats: {},
        costBreakdown: [],
        agentTypes: {},
        costDays: 7,
        health: null,
        statusChartInstance: null,
        costChartInstance: null,

        get totalWorkflows() {
            return Object.values(this.stats.workflows ?? {}).reduce((a, b) => a + Number(b), 0);
        },
        get pendingApproval() {
            return Number(this.stats.workflows?.owner_approval ?? 0);
        },
        get costTrend() {
            const today = this.stats.ai_cost_today ?? 0;
            const yesterday = this.stats.ai_cost_yesterday ?? 0;
            if (yesterday === 0 && today === 0) return 0;
            if (yesterday === 0) return 100;
            return Math.round(((today - yesterday) / yesterday) * 100);
        },
        get maxAgentCount() {
            const vals = Object.values(this.agentTypes);
            return vals.length ? Math.max(...vals) : 1;
        },
        get insights() {
            const out = [];
            const rank = { error: 0, warning: 1, info: 2 };

            // 1. Pipeline bottleneck (dynamic: >1.5× average stage size)
            const stages = Object.entries(this.stats.candidate_stages ?? {});
            if (stages.length > 1) {
                const total = stages.reduce((s, [, c]) => s + Number(c), 0);
                const avg   = total / stages.length;
                const [top, cnt] = stages.sort((a, b) => b[1] - a[1])[0];
                if (Number(cnt) > avg * 1.5) {
                    const pct = Math.round(Number(cnt) / total * 100);
                    out.push({ type: 'warning', msg: `${pct}% of candidates stuck in "${top}"`, action: `/dashboard/candidates?stage=${encodeURIComponent(top)}` });
                }
            }

            // 2. High job failure rate >20%
            const totalJobs = Object.values(this.agentTypes).reduce((s, c) => s + Number(c), 0);
            const failPct   = totalJobs > 0 ? Math.round((this.stats.failed_jobs ?? 0) / totalJobs * 100) : 0;
            if (failPct > 20) {
                const reason = this.stats.top_failure_reason ? ` — ${this.stats.top_failure_reason}` : '';
                out.push({ type: 'error', msg: `${failPct}% of recent jobs failed${reason}`, action: `/dashboard/jobs?status=failed` });
            }

            // 3. AI cost spike >50% day-over-day
            if (this.costTrend > 50) {
                out.push({ type: 'warning', msg: `AI cost up ${this.costTrend}% vs yesterday`, action: `/dashboard/system` });
            }

            // 4. Pending approvals
            if ((this.stats.needs_approval ?? 0) > 0) {
                out.push({ type: 'info', msg: `${this.stats.needs_approval} workflow(s) awaiting approval`, action: `/dashboard/workflows?status=owner_approval` });
            }

            // 5. Queue/worker health (only when data is fully loaded and well-typed)
            if (this.health && typeof this.health.worker_healthy !== 'undefined' && !this.health.worker_healthy) {
                out.push({ type: 'error', msg: `Queue worker unhealthy — ${this.health.queue_pending_jobs ?? '?'} jobs pending`, action: `/dashboard/jobs` });
            }

            return out.sort((a, b) => rank[a.type] - rank[b.type]).slice(0, 3);
        },

        async init() {
            await Promise.all([this.load(), this.loadHealth()]);
            this.buildCharts();
            setInterval(() => this.load(), 12000);
        },

        async load() {
            try {
                const data = this.applyMeta(await apiGet('/dashboard/api/stats'));
                this.stats = data;
                this.updateCharts();
                updateTimestamp();
            } catch (error) { this.handleError(error); }
        },

        buildCharts() {
            if (!window.Chart) return;

            this.$nextTick(() => {
                // Status donut
                const sCtx = document.getElementById('statusChart')?.getContext('2d');
                if (sCtx) {
                    if (this.statusChartInstance) { this.statusChartInstance.destroy(); this.statusChartInstance = null; }
                    this.statusChartInstance = new Chart(sCtx, {
                        type: 'doughnut',
                        data: { labels: [], datasets: [{ data: [], backgroundColor: [], borderWidth: 0 }] },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '65%',
                            plugins: {
                                title:    { display: false },
                                subtitle: { display: false },
                                legend: { position: 'right', labels: { color: '#94a3b8', font: { size: 11 }, boxWidth: 10, padding: 8 } },
                            },
                        }
                    });
                }

                // Cost bar chart
                const cCtx = document.getElementById('costChart')?.getContext('2d');
                if (cCtx) {
                    if (this.costChartInstance) { this.costChartInstance.destroy(); this.costChartInstance = null; }
                    this.costChartInstance = new Chart(cCtx, {
                        type: 'bar',
                        data: { labels: [], datasets: [{ label: 'USD', data: [], backgroundColor: 'rgba(139,92,246,0.6)', borderRadius: 4 }] },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title:    { display: false },
                                subtitle: { display: false },
                                legend:   { display: false },
                            },
                            scales: {
                                x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b', font: { size: 10 } } },
                                y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b', font: { size: 10 }, callback: v => '$' + v } },
                            }
                        }
                    });
                    this.loadCosts();
                    setInterval(() => this.loadCosts(), 30000);
                }
            });
        },

        updateCharts() {
            if (!this.statusChartInstance?.canvas) return;
            const wf = this.stats.workflows ?? {};
            const colorMap = {
                completed: '#34d399', failed: '#f87171', cancelled: '#64748b',
                owner_approval: '#fb923c', pending: '#fbbf24',
                intake: '#a78bfa', planning: '#a78bfa', task_execution: '#a78bfa',
                review: '#a78bfa', execution: '#a78bfa', learning: '#a78bfa',
                context_retrieval: '#a78bfa', observation: '#a78bfa',
            };
            const labels = Object.keys(wf).filter(k => wf[k] > 0);
            this.statusChartInstance.data.labels = labels.map(l => l.replace(/_/g, ' '));
            this.statusChartInstance.data.datasets[0].data = labels.map(l => wf[l]);
            this.statusChartInstance.data.datasets[0].backgroundColor = labels.map(l => colorMap[l] ?? '#475569');
            this.statusChartInstance.update();
        },

        async loadCosts() {
            if (!this.costChartInstance?.canvas) return;
            try {
                const d = this.applyMeta(await apiGet('/dashboard/api/ai-costs?days=' + this.costDays));
                this.costChartInstance.data.labels = (d.daily ?? []).map(x => x.date);
                this.costChartInstance.data.datasets[0].data = (d.daily ?? []).map(x => x.cost);
                this.costChartInstance.update();
                this.costBreakdown = d.breakdown ?? [];
            } catch (error) { this.handleError(error); }

            // Also load agent type distribution (provides agentTypes for insights)
            try {
                const j = await apiGet('/dashboard/api/jobs');
                this.agentTypes        = j.by_agent_type ?? {};
                // top_failure_reason comes from apiJobs — attach to stats for insights getter
                this.stats.top_failure_reason = j.top_failure_reason ?? null;
            } catch (_) {}
        },

        async loadHealth() {
            try {
                const ctrl = new AbortController();
                setTimeout(() => ctrl.abort(), 3000);
                const r = await fetch('/health', {
                    signal: ctrl.signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (r.ok) this.health = await r.json();
            } catch (_) {} // silent — insights degrade gracefully without health data
        },

        statusBadge, relativeTime
    }
}
</script>
@endsection
