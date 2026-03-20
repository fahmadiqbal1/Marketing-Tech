@extends('layouts.app')
@section('title', 'Overview')
@section('subtitle', 'Live platform status and activity')

@section('content')
<div x-data="overviewApp()" x-init="init()" x-cloak>

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

        {{-- Active Jobs --}}
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Active Jobs</p>
                <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center">
                    <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white" x-text="stats.active_jobs ?? 0">–</p>
            <p class="text-xs text-slate-500 mt-1">
                <span x-text="(stats.queue_depth ?? 0) + ' queued'"></span>
            </p>
        </div>

        {{-- AI Cost Today --}}
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">AI Cost Today</p>
                <div class="w-8 h-8 rounded-lg bg-amber-500/10 flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white" x-text="'$' + (stats.ai_cost_today ?? 0).toFixed(4)">–</p>
            <p class="text-xs text-slate-500 mt-1">
                <span x-text="'$' + (stats.ai_cost_week ?? 0).toFixed(4) + ' this week'"></span>
            </p>
        </div>

        {{-- Pending Approval --}}
        <div class="stat-card" :class="pendingApproval > 0 ? 'border-orange-500/40' : ''">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Needs Approval</p>
                <div class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center">
                    <svg class="w-4 h-4 text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold" :class="pendingApproval > 0 ? 'text-orange-400' : 'text-white'" x-text="pendingApproval">–</p>
            <p class="text-xs text-slate-500 mt-1">workflows awaiting sign-off</p>
        </div>
    </div>

    {{-- ── Middle row ──────────────────────────────────────────── --}}
    <div class="grid grid-cols-3 gap-4 mb-6">

        {{-- Workflow status breakdown --}}
        <div class="stat-card col-span-1">
            <h3 class="text-sm font-semibold text-white mb-4">Workflow Status</h3>
            <canvas id="statusChart" height="180"></canvas>
        </div>

        {{-- AI cost chart --}}
        <div class="stat-card col-span-2">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-white">AI Cost — Last 7 Days</h3>
                <span class="text-xs text-slate-500">per day</span>
            </div>
            <canvas id="costChart" height="130"></canvas>
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
        stats: {},
        statusChartInstance: null,
        costChartInstance: null,

        get totalWorkflows() {
            return Object.values(this.stats.workflows ?? {}).reduce((a, b) => a + Number(b), 0);
        },
        get pendingApproval() {
            return Number(this.stats.workflows?.owner_approval ?? 0);
        },

        async init() {
            await this.load();
            this.buildCharts();
            setInterval(() => this.load(), 12000);
        },

        async load() {
            try {
                const r = await fetch('/dashboard/api/stats');
                this.stats = await r.json();
                this.updateCharts();
                updateTimestamp();
            } catch(e) { console.error(e); }
        },

        buildCharts() {
            // Status donut
            const sCtx = document.getElementById('statusChart')?.getContext('2d');
            if (sCtx) {
                this.statusChartInstance = new Chart(sCtx, {
                    type: 'doughnut',
                    data: { labels: [], datasets: [{ data: [], backgroundColor: [], borderWidth: 0 }] },
                    options: {
                        cutout: '65%',
                        plugins: { legend: { position: 'right', labels: { color: '#94a3b8', font: { size: 11 }, boxWidth: 10, padding: 8 } } },
                    }
                });
            }

            // Cost bar chart
            const cCtx = document.getElementById('costChart')?.getContext('2d');
            if (cCtx) {
                this.costChartInstance = new Chart(cCtx, {
                    type: 'bar',
                    data: { labels: [], datasets: [{ label: 'USD', data: [], backgroundColor: 'rgba(139,92,246,0.6)', borderRadius: 4 }] },
                    options: {
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b', font: { size: 10 } } },
                            y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b', font: { size: 10 }, callback: v => '$' + v } },
                        }
                    }
                });
                // Load cost data
                this.loadCosts();
                setInterval(() => this.loadCosts(), 30000);
            }
        },

        updateCharts() {
            if (!this.statusChartInstance) return;
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
            if (!this.costChartInstance) return;
            try {
                const r = await fetch('/dashboard/api/ai-costs?days=7');
                const d = await r.json();
                this.costChartInstance.data.labels = (d.daily ?? []).map(x => x.date);
                this.costChartInstance.data.datasets[0].data = (d.daily ?? []).map(x => x.cost);
                this.costChartInstance.update();
            } catch(e) {}
        },

        statusBadge, relativeTime
    }
}
</script>
@endsection
