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
                   class="insight-row flex items-center gap-3 rounded-xl px-4 py-3 text-sm transition-opacity hover:opacity-80"
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
    <div class="flex items-center justify-between mb-2" id="stat-cards-header">
        <div class="flex items-center gap-2">
            <span x-show="refreshing" class="flex items-center gap-1.5 text-xs text-slate-400">
                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Updating…
            </span>
        </div>
        <button @click="manualRefresh()"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-700/50 hover:border-slate-600 transition-all">
            <svg class="w-3.5 h-3.5" :class="refreshing ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Refresh
        </button>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" id="stat-cards">

        {{-- Total Workflows --}}
        <div class="stat-card glow-border">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Total Workflows</p>
                <div class="icon-3d w-9 h-9 rounded-xl flex items-center justify-center"
                     style="background:linear-gradient(135deg,rgba(124,58,237,0.2),rgba(124,58,237,0.05));border:1px solid rgba(124,58,237,0.2);">
                    <svg class="w-4 h-4 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white" x-text="displayStats.totalWorkflows ?? '–'"></p>
            <p class="text-xs text-slate-500 mt-1">
                <span class="text-emerald-400" x-text="(stats.workflows?.completed ?? 0) + ' completed'"></span>
                &nbsp;·&nbsp;
                <span class="text-red-400" x-text="(stats.workflows?.failed ?? 0) + ' failed'"></span>
            </p>
        </div>

        {{-- Active Jobs (clickable) --}}
        <div class="stat-card glow-border cursor-pointer transition-all"
             @click="window.location='/dashboard/jobs?status=running'">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Active Jobs</p>
                <div class="icon-3d w-9 h-9 rounded-xl flex items-center justify-center"
                     style="background:linear-gradient(135deg,rgba(59,130,246,0.2),rgba(59,130,246,0.05));border:1px solid rgba(59,130,246,0.2);">
                    <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white" x-text="displayStats.activeJobs ?? '–'"></p>
            <p class="text-xs text-slate-500 mt-1">
                <span x-text="(stats.queue_depth ?? 0) + ' queued'"></span>
                <span class="ml-1 text-xs text-brand-400">→ view jobs</span>
            </p>
        </div>

        {{-- AI Cost Today (with trend) --}}
        <div class="stat-card glow-border">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">AI Cost Today</p>
                <div class="icon-3d w-9 h-9 rounded-xl flex items-center justify-center"
                     style="background:linear-gradient(135deg,rgba(245,158,11,0.2),rgba(245,158,11,0.05));border:1px solid rgba(245,158,11,0.2);">
                    <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <div class="flex items-end gap-2">
                <p class="text-3xl font-bold text-white" x-text="'$' + (displayStats.aiCost ?? 0).toFixed(4)"></p>
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
        <div class="stat-card glow-border cursor-pointer transition-all"
             :class="pendingApproval > 0 ? 'border-orange-500/40' : ''"
             @click="window.location='/dashboard/workflows?status=owner_approval'">
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Needs Approval</p>
                <div class="icon-3d w-9 h-9 rounded-xl flex items-center justify-center"
                     style="background:linear-gradient(135deg,rgba(249,115,22,0.2),rgba(249,115,22,0.05));border:1px solid rgba(249,115,22,0.2);">
                    <svg class="w-4 h-4 text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold" :class="pendingApproval > 0 ? 'text-orange-400' : 'text-white'" x-text="displayStats.pendingApproval ?? '–'"></p>
            <p class="text-xs text-slate-500 mt-1">
                <span>workflows awaiting sign-off</span>
                <span class="ml-1 text-xs text-brand-400" x-show="pendingApproval > 0">→ review</span>
            </p>
        </div>
    </div>

    {{-- ── Middle row: charts ──────────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

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
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">

        {{-- Cost breakdown by model --}}
        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">Cost by Model</h3>
            <template x-if="!costBreakdown.length">
                <p class="text-xs text-slate-500 py-4 text-center">No AI requests recorded yet.</p>
            </template>
            <div class="space-y-2 max-h-56 overflow-y-auto pr-1">
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
            <div class="space-y-2 max-h-56 overflow-y-auto pr-1">
                <template x-for="[type, count] in Object.entries(agentTypes)" :key="type">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-300 w-32 truncate" x-text="type"></span>
                        <div class="flex-1 h-1.5 rounded-full overflow-hidden" style="background:rgba(30,41,59,0.8);">
                            <div class="h-full rounded-full transition-all duration-700"
                                 style="background:linear-gradient(90deg,#7c3aed,#a78bfa);"
                                 :style="'width:' + Math.round(count / maxAgentCount * 100) + '%'"></div>
                        </div>
                        <span class="text-xs text-slate-400 w-6 text-right" x-text="count"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Bottom row ──────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

        {{-- Recent Workflows --}}
        <div class="stat-card col-span-2">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-white">Recent Workflows</h3>
                <a href="/dashboard/workflows" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">View all →</a>
            </div>
            <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                <template x-if="stats.recent_workflows?.length === 0">
                    <div class="flex flex-col items-center gap-2 py-8">
                        <svg class="w-8 h-8 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                        <p class="text-sm text-slate-500 text-center">No workflows yet.</p>
                        <p class="text-xs text-slate-600 text-center">Send a Telegram command to get started</p>
                        <span class="mt-1 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-700 text-xs font-mono text-violet-400">/help</span>
                    </div>
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
            <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                <template x-if="!stats.recent_events?.length">
                    <p class="text-sm text-slate-500 text-center py-6">No events yet</p>
                </template>
                <template x-for="ev in (stats.recent_events ?? [])" :key="ev.id">
                    <div class="relative p-2.5 rounded-lg border border-slate-700/40 hover:border-slate-600/60 transition-colors cursor-pointer"
                         @click="selectedEvent = (selectedEvent === ev.id ? null : ev.id)">
                        <div class="flex items-start gap-2">
                            <span class="badge mt-0.5 flex-shrink-0" :class="statusBadge(ev.level)" x-text="ev.level"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-slate-300 truncate" x-text="ev.event"></p>
                                <p class="text-xs text-slate-500 mt-0.5" x-text="relativeTime(ev.created_at)"></p>
                            </div>
                            <svg class="w-3 h-3 text-slate-600 flex-shrink-0 mt-0.5 transition-transform"
                                 :class="selectedEvent === ev.id ? 'rotate-180' : ''"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                        {{-- Expanded detail --}}
                        <div x-show="selectedEvent === ev.id"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="mt-2 pt-2 border-t border-slate-700/40">
                            <p class="text-xs text-slate-300 break-words leading-relaxed" x-text="ev.event"></p>
                            <template x-if="ev.context">
                                <p class="mt-1 text-xs text-slate-500 font-mono break-all" x-text="typeof ev.context === 'string' ? ev.context : JSON.stringify(ev.context)"></p>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Social Intelligence + Content Velocity ──────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">

        {{-- Social Intelligence Panel --}}
        <div class="stat-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-white">Social Intelligence</h3>
                <a href="/dashboard/social" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">Manage →</a>
            </div>

            {{-- Platform connection badges --}}
            <div class="flex flex-wrap gap-2 mb-4">
                <template x-for="p in socialHealth.platforms ?? []" :key="p.platform">
                    <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs font-medium transition-colors"
                         :class="p.connected
                             ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
                             : 'bg-slate-800/60 border-slate-700/40 text-slate-500'">
                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                              :class="p.connected ? 'bg-emerald-400' : 'bg-slate-600'"></span>
                        <span class="capitalize" x-text="p.platform"></span>
                    </div>
                </template>
                <template x-if="!socialHealth.platforms?.length">
                    <span class="text-xs text-slate-500">No accounts connected</span>
                </template>
            </div>

            {{-- Scheduled count --}}
            <div class="flex items-center justify-between py-2.5 border-t border-slate-800">
                <span class="text-xs text-slate-400">Scheduled posts</span>
                <span class="text-sm font-semibold text-amber-400" x-text="socialHealth.scheduled_count ?? 0"></span>
            </div>
            <div class="flex items-center justify-between py-2.5 border-t border-slate-800">
                <span class="text-xs text-slate-400">Pending approval</span>
                <span class="text-sm font-semibold"
                      :class="(socialHealth.pending_approval ?? 0) > 0 ? 'text-orange-400' : 'text-slate-400'"
                      x-text="socialHealth.pending_approval ?? 0"></span>
            </div>

            {{-- Latest trend insight --}}
            <div x-show="socialHealth.latest_trend" class="mt-3 pt-3 border-t border-slate-800">
                <p class="text-xs text-slate-500 mb-1.5">Latest trend signal</p>
                <div class="bg-slate-800/40 rounded-lg p-2.5">
                    <p class="text-xs text-slate-300 leading-relaxed" x-text="socialHealth.latest_trend?.topic ?? ''"></p>
                    <div class="flex items-center gap-2 mt-1.5">
                        <span class="text-xs text-slate-500 capitalize" x-text="socialHealth.latest_trend?.platform ?? ''"></span>
                        <div class="flex-1 h-1 bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-full bg-violet-500 rounded-full transition-all"
                                 :style="'width:' + Math.min((socialHealth.latest_trend?.confidence ?? 0), 100) + '%'"></div>
                        </div>
                        <span class="text-xs text-violet-400" x-text="(socialHealth.latest_trend?.confidence ?? 0) + '%'"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Content Velocity Widget --}}
        <div class="stat-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-white">Content Velocity</h3>
                <a href="/dashboard/content" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">View all →</a>
            </div>

            {{-- This week pipeline --}}
            <div class="space-y-3 mb-4">
                <template x-for="stage in contentVelocity.stages ?? []" :key="stage.label">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs text-slate-400" x-text="stage.label"></span>
                            <span class="text-xs font-medium" :class="stage.color" x-text="stage.count"></span>
                        </div>
                        <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all"
                                 :class="stage.barColor"
                                 :style="'width:' + (contentVelocity.max > 0 ? Math.round(stage.count / contentVelocity.max * 100) : 0) + '%'"></div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Pipeline health bar --}}
            <div class="pt-3 border-t border-slate-800">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs text-slate-400">Pipeline health</span>
                    <span class="text-xs font-medium"
                          :class="{
                              'text-emerald-400': contentVelocity.health_pct >= 70,
                              'text-amber-400':   contentVelocity.health_pct >= 40 && contentVelocity.health_pct < 70,
                              'text-red-400':     contentVelocity.health_pct < 40,
                          }"
                          x-text="(contentVelocity.health_pct ?? 0) + '%'"></span>
                </div>
                <div class="h-2 bg-slate-800 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all"
                         :class="{
                             'bg-emerald-500': contentVelocity.health_pct >= 70,
                             'bg-amber-500':   contentVelocity.health_pct >= 40 && contentVelocity.health_pct < 70,
                             'bg-red-500':     contentVelocity.health_pct < 40,
                         }"
                         :style="'width:' + (contentVelocity.health_pct ?? 0) + '%'"></div>
                </div>
                <p class="text-xs text-slate-500 mt-1.5" x-text="contentVelocity.health_label ?? 'No data yet'"></p>
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
        socialHealth: {},
        contentVelocity: {},
        refreshing: false,
        selectedEvent: null,
        displayStats: { totalWorkflows: '–', activeJobs: '–', aiCost: 0, pendingApproval: '–' },

        // Animates a numeric value from 0 to `target` over `duration` ms,
        // writing into `this.displayStats[key]` at each frame.
        animateCounter(key, target, duration = 600, isFloat = false) {
            const start = performance.now();
            const from  = 0;
            const step  = (now) => {
                const elapsed  = now - start;
                const progress = Math.min(elapsed / duration, 1);
                // ease-out quad
                const eased = 1 - (1 - progress) * (1 - progress);
                const current = from + (target - from) * eased;
                this.displayStats[key] = isFloat ? current : Math.round(current);
                if (progress < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        },

        get totalWorkflows() {
            return Object.values(this.stats.workflows ?? {}).reduce((a, b) => a + Number(b), 0);
        },
        get pendingApproval() {
            return Number(this.stats.workflows?.owner_approval ?? 0);
        },
        get costTrend() {
            const today = Number(this.stats.ai_cost_today ?? 0);
            const yesterday = Number(this.stats.ai_cost_yesterday ?? 0);
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
            await Promise.all([this.load(), this.loadHealth(), this.loadSocialHealth(), this.loadContentVelocity()]);
            this.buildCharts();
            setInterval(() => this.load(), 12000);
            setInterval(() => { this.loadSocialHealth(); this.loadContentVelocity(); this.loadHealth(); }, 30000);
        },

        async manualRefresh() {
            await Promise.all([this.load(), this.loadHealth(), this.loadSocialHealth(), this.loadContentVelocity()]);
        },

        async load() {
            this.refreshing = true;
            try {
                const data = this.applyMeta(await apiGet('/dashboard/api/stats'));
                this.stats = data;
                this.updateCharts();
                updateTimestamp();
                // Animate stat counters on each data load
                this.animateCounter('totalWorkflows', this.totalWorkflows, 600, false);
                this.animateCounter('activeJobs',     Number(data.active_jobs ?? 0), 600, false);
                this.animateCounter('aiCost',         Number(data.ai_cost_today ?? 0), 600, true);
                this.animateCounter('pendingApproval', this.pendingApproval, 600, false);
            } catch (error) { this.handleError(error); }
            this.refreshing = false;
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

                // Cost bar chart with gradient fill
                const cCtx = document.getElementById('costChart')?.getContext('2d');
                if (cCtx) {
                    if (this.costChartInstance) { this.costChartInstance.destroy(); this.costChartInstance = null; }
                    const gradient = cCtx.createLinearGradient(0, 0, 0, 200);
                    gradient.addColorStop(0,   'rgba(139, 92, 246, 0.8)');
                    gradient.addColorStop(0.5, 'rgba(124, 58, 237, 0.5)');
                    gradient.addColorStop(1,   'rgba(109, 40, 217, 0.1)');
                    this.costChartInstance = new Chart(cCtx, {
                        type: 'bar',
                        data: { labels: [], datasets: [{
                            label: 'USD',
                            data: [],
                            backgroundColor: gradient,
                            borderRadius: 6,
                            borderSkipped: false,
                            hoverBackgroundColor: 'rgba(167, 139, 250, 0.9)',
                        }]},
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title:    { display: false },
                                subtitle: { display: false },
                                legend:   { display: false },
                                tooltip:  {
                                    backgroundColor: 'rgba(15,23,42,0.9)',
                                    borderColor: 'rgba(124,58,237,0.4)',
                                    borderWidth: 1,
                                    titleColor: '#e2e8f0',
                                    bodyColor: '#a78bfa',
                                    callbacks: { label: ctx => ' $' + ctx.parsed.y.toFixed(4) },
                                },
                            },
                            scales: {
                                x: { grid: { color: 'rgba(255,255,255,0.03)' }, border: { dash: [4,4] }, ticks: { color: '#475569', font: { size: 10 } } },
                                y: { beginAtZero: true, min: 0, suggestedMax: 0.10, grid: { color: 'rgba(255,255,255,0.03)' }, border: { dash: [4,4] }, ticks: { color: '#475569', font: { size: 10 }, callback: v => '$' + v } },
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
                const costs = (d.daily ?? []).map(x => x.cost);
                this.costChartInstance.data.labels = (d.daily ?? []).map(x => x.date);
                this.costChartInstance.data.datasets[0].data = costs;
                const maxVal = costs.length ? Math.max(...costs) : 0;
                this.costChartInstance.options.scales.y.suggestedMax = Math.max(maxVal * 1.4, 0.05);
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

        async loadSocialHealth() {
            try {
                const d = await apiGet('/dashboard/api/social/health');
                // Map endpoint fields to panel-expected shape
                const accounts = d.connected_accounts ?? [];
                const allPlatforms = ['instagram', 'tiktok', 'twitter', 'linkedin', 'facebook'];
                const connectedSet = new Set(accounts.map(a => a.platform));
                this.socialHealth = {
                    platforms:        allPlatforms.map(p => ({ platform: p, connected: connectedSet.has(p) })),
                    scheduled_count:  d.scheduled_this_week ?? 0,
                    pending_approval: d.pending_approval ?? 0,
                    latest_trend:     null, // loaded separately below
                };
                // Fetch latest trend from first connected platform
                const firstConnected = accounts[0]?.platform;
                if (firstConnected) {
                    try {
                        const t = await apiGet('/dashboard/api/trend-insights?platform=' + firstConnected);
                        const insights = t.insights ?? [];
                        if (insights.length) {
                            this.socialHealth.latest_trend = {
                                platform:   firstConnected,
                                topic:      insights[0].title ?? '',
                                confidence: insights[0].confidence === 'high' ? 85
                                           : insights[0].confidence === 'medium' ? 55 : 30,
                            };
                        }
                    } catch (_) {}
                }
            } catch (_) {}
        },

        async loadContentVelocity() {
            try {
                const d = await apiGet('/dashboard/api/content?per_page=200');
                const items = d.data ?? [];
                const now = new Date();
                const weekStart = new Date(now);
                weekStart.setDate(now.getDate() - now.getDay());
                weekStart.setHours(0, 0, 0, 0);

                const thisWeek = items.filter(i => new Date(i.created_at) >= weekStart);
                const drafts    = thisWeek.filter(i => i.status === 'draft').length;
                const scheduled = thisWeek.filter(i => i.status === 'scheduled').length;
                const published = thisWeek.filter(i => i.status === 'published').length;
                const total     = thisWeek.length || 1;
                const maxVal    = Math.max(drafts, scheduled, published, 1);

                // Health: published / total this week (0–100)
                const healthPct = Math.round((published / total) * 100);
                let healthLabel = 'No content this week';
                if (total > 1) {
                    if (healthPct >= 70)      healthLabel = `${published} published — strong output`;
                    else if (healthPct >= 40) healthLabel = `${published} published — moderate output`;
                    else                      healthLabel = `${published} published — pipeline needs attention`;
                }

                this.contentVelocity = {
                    stages: [
                        { label: 'Drafts this week',     count: drafts,    color: 'text-slate-400',  barColor: 'bg-slate-500' },
                        { label: 'Scheduled this week',  count: scheduled, color: 'text-amber-400',  barColor: 'bg-amber-500' },
                        { label: 'Published this week',  count: published, color: 'text-emerald-400', barColor: 'bg-emerald-500' },
                    ],
                    max:          maxVal,
                    health_pct:   healthPct,
                    health_label: healthLabel,
                };
            } catch (_) {}
        },

        statusBadge, relativeTime
    }
}
</script>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.gsap) return;
    // Stagger entrance for stat cards
    gsap.from('#stat-cards > div', {
        opacity: 0, y: 20, duration: 0.5, stagger: 0.08, ease: 'power2.out',
        delay: 0.2,
    });
    // Stagger for insight rows (after they render)
    setTimeout(() => {
        gsap.from('.insight-row', {
            opacity: 0, x: -12, duration: 0.4, stagger: 0.06, ease: 'power2.out',
        });
    }, 600);
});
</script>
@endpush
@endsection
