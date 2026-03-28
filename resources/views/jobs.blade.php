@extends('layouts.app')
@section('title', 'Agent Jobs')
@section('subtitle', 'Recent agent execution records and queue pressure')

@section('content')
<div x-data="jobsApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    {{-- ── Status Summary Cards ────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="stat-card cursor-pointer transition-all"
             :class="statusFilter === 'pending' ? 'ring-2 ring-brand-500' : 'hover:ring-1 hover:ring-slate-600'"
             @click="statusFilter = (statusFilter === 'pending' ? '' : 'pending'); currentPage = 1; load()">
            <p class="text-xs text-slate-400 uppercase mb-1">Pending</p>
            <p class="text-3xl font-bold text-slate-200" x-text="byStatus.pending ?? 0"></p>
        </div>
        <div class="stat-card cursor-pointer transition-all"
             :class="statusFilter === 'running' ? 'ring-2 ring-brand-500' : 'hover:ring-1 hover:ring-slate-600'"
             @click="statusFilter = (statusFilter === 'running' ? '' : 'running'); currentPage = 1; load()">
            <p class="text-xs text-slate-400 uppercase mb-1">Running</p>
            <p class="text-3xl font-bold text-sky-400" x-text="byStatus.running ?? 0"></p>
        </div>
        <div class="stat-card cursor-pointer transition-all"
             :class="statusFilter === 'completed' ? 'ring-2 ring-brand-500' : 'hover:ring-1 hover:ring-slate-600'"
             @click="statusFilter = (statusFilter === 'completed' ? '' : 'completed'); currentPage = 1; load()">
            <p class="text-xs text-slate-400 uppercase mb-1">Completed</p>
            <p class="text-3xl font-bold text-emerald-400" x-text="byStatus.completed ?? 0"></p>
        </div>
        <div class="stat-card cursor-pointer transition-all"
             :class="statusFilter === 'failed' ? 'ring-2 ring-red-500' : 'hover:ring-1 hover:ring-slate-600'"
             @click="statusFilter = (statusFilter === 'failed' ? '' : 'failed'); currentPage = 1; load()">
            <p class="text-xs text-slate-400 uppercase mb-1">Failed</p>
            <p class="text-3xl font-bold text-red-400" x-text="byStatus.failed ?? 0"></p>
        </div>
    </div>

    {{-- ── Queue Depth Panel ───────────────────────────────────────── --}}
    <div class="stat-card" x-show="Object.keys(queueTable).length > 0">
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">Queue Depth</h3>
        <div class="flex flex-wrap gap-3">
            <template x-for="[queue, count] in Object.entries(queueTable)" :key="queue">
                <div class="flex items-center gap-2 bg-slate-800/60 border border-slate-700/50 rounded-lg px-3 py-2">
                    <span class="text-xs text-slate-400" x-text="queue"></span>
                    <span class="text-sm font-semibold text-amber-400" x-text="count"></span>
                    <span class="text-xs text-slate-500">pending</span>
                </div>
            </template>
        </div>
    </div>

    {{-- ── Filters ─────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-3">
        <select x-model="agentTypeFilter" @change="currentPage = 1; load()"
            class="bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg px-3 py-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
            <option value="">All agent types</option>
            <template x-for="[type, count] in Object.entries(byAgentType)" :key="type">
                <option :value="type" x-text="type + ' (' + count + ')'"></option>
            </template>
        </select>

        <div class="ml-auto flex items-center gap-3">
            <span class="text-xs text-slate-500" x-text="'Refreshes in ' + refreshCountdown + 's'"></span>
            <span class="text-xs text-slate-500" x-text="totalEntries + ' jobs'"></span>
            <button @click="load()" class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">
                <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
        </div>
    </div>

    {{-- ── Jobs Table ──────────────────────────────────────────────── --}}
    <div class="stat-card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-xs uppercase border-b border-slate-700/60">
                <tr>
                    <th class="text-left py-3">Agent</th>
                    <th class="text-left py-3">Status</th>
                    <th class="text-left py-3">Workflow</th>
                    <th class="text-left py-3">Steps</th>
                    <th class="text-left py-3">Duration</th>
                    <th class="text-left py-3">Created</th>
                </tr>
            </thead>
            <tbody>
                {{-- Skeleton loading rows --}}
                <template x-if="loading && !jobs.length">
                    <template x-for="i in [1,2,3,4,5]" :key="i">
                        <tr class="border-b border-slate-800/60">
                            <td class="py-3"><div class="skeleton h-4 w-32 mb-1"></div><div class="skeleton h-3 w-48"></div></td>
                            <td class="py-3"><div class="skeleton h-5 w-16 rounded-full"></div></td>
                            <td class="py-3"><div class="skeleton h-4 w-20"></div></td>
                            <td class="py-3"><div class="skeleton h-4 w-8"></div></td>
                            <td class="py-3"><div class="skeleton h-4 w-12"></div></td>
                            <td class="py-3"><div class="skeleton h-4 w-16"></div></td>
                        </tr>
                    </template>
                </template>
                <template x-if="!loading && !jobs.length">
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">No agent jobs found.</td></tr>
                </template>
                <template x-for="job in jobs" :key="job.id">
                    {{-- Two rows per job: the main row and the expandable detail row --}}
                    <template x-if="true">
                        <tbody>
                            <tr class="border-b border-slate-800/60 hover:bg-slate-800/30 transition-colors cursor-pointer"
                                :class="job.status === 'running' ? 'border-l-2 border-l-blue-500' : ''"
                                @click="expanded = expanded === job.id ? null : job.id">
                                <td class="py-3">
                                    <div class="flex items-center gap-2">
                                        <div>
                                            <div class="font-medium text-slate-200" x-text="job.agent_type"></div>
                                            <div class="text-xs text-slate-500 max-w-xs truncate" x-text="job.short_description || '—'"></div>
                                        </div>
                                        {{-- Chevron icon --}}
                                        <svg class="w-3.5 h-3.5 text-slate-500 ml-1 flex-shrink-0 transition-transform duration-200"
                                             :class="expanded === job.id ? 'rotate-180' : ''"
                                             fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="badge" :class="statusBadge(job.status)" x-text="job.status"></span>
                                        {{-- Pulsing dot for running jobs --}}
                                        <span x-show="job.status === 'running'"
                                              class="inline-block w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                                    </div>
                                </td>
                                <td class="py-3 text-slate-400 text-xs font-mono" x-text="job.workflow_id ? job.workflow_id.slice(0,8) + '…' : '–'"></td>
                                <td class="py-3 text-slate-400" x-text="job.steps_taken ?? 0"></td>
                                <td class="py-3 text-slate-400 text-xs" x-text="jobDuration(job)"></td>
                                <td class="py-3 text-slate-400 text-xs whitespace-nowrap" x-text="relativeTime(job.created_at)"></td>
                            </tr>
                            {{-- Expandable detail row --}}
                            <tr x-show="expanded === job.id" class="bg-slate-900/60">
                                <td colspan="6" class="px-4 pb-4 pt-2">
                                    <div class="space-y-3">
                                        {{-- Full description --}}
                                        <div x-show="job.short_description">
                                            <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">Description</p>
                                            <p class="text-sm text-slate-300" x-text="job.short_description"></p>
                                        </div>
                                        {{-- Error message --}}
                                        <div x-show="job.error_message">
                                            <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">Error</p>
                                            <div class="border border-red-500/40 bg-red-950/30 rounded-lg p-3">
                                                <p class="text-xs text-red-300 font-mono whitespace-pre-wrap" x-text="job.error_message"></p>
                                            </div>
                                        </div>
                                        {{-- Steps progress --}}
                                        <div x-show="(job.total_steps ?? 0) > 0">
                                            <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">
                                                Steps: <span x-text="(job.steps_taken ?? 0) + ' / ' + (job.total_steps ?? 0)"></span>
                                            </p>
                                            <div class="h-2 bg-slate-800 rounded-full overflow-hidden w-full max-w-xs">
                                                <div class="h-2 bg-violet-500 rounded-full transition-all duration-500"
                                                     :style="`width:${Math.min(((job.steps_taken ?? 0) / (job.total_steps ?? 1)) * 100, 100)}%`"></div>
                                            </div>
                                        </div>
                                        {{-- Steps taken only (no total) --}}
                                        <div x-show="(job.total_steps ?? 0) === 0 && (job.steps_taken ?? 0) > 0">
                                            <p class="text-xs text-slate-400">Steps taken: <span class="text-slate-200" x-text="job.steps_taken"></span></p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </template>
                </template>
            </tbody>
        </table>

        {{-- Pagination --}}
        <div class="flex items-center justify-between mt-4 pt-4 border-t border-slate-800" x-show="totalPages > 1">
            <p class="text-xs text-slate-500" x-text="'Page ' + currentPage + ' of ' + totalPages + ' (' + totalEntries + ' jobs)'"></p>
            <div class="flex gap-2">
                <button @click="changePage(currentPage - 1)" :disabled="currentPage <= 1"
                        class="px-3 py-1.5 text-xs border border-slate-700 text-slate-400 rounded-lg hover:border-slate-500 hover:text-white transition disabled:opacity-40">Prev</button>
                <button @click="changePage(currentPage + 1)" :disabled="currentPage >= totalPages"
                        class="px-3 py-1.5 text-xs border border-slate-700 text-slate-400 rounded-lg hover:border-slate-500 hover:text-white transition disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function jobsApp() {
    return {
        ...dashboardState(),
        jobs: [],
        byStatus: {},
        byAgentType: {},
        queueTable: {},
        statusFilter: '',
        agentTypeFilter: '',
        currentPage: 1,
        totalPages: 1,
        totalEntries: 0,
        loading: false,
        expanded: null,
        refreshInterval: 15,
        refreshCountdown: 15,

        async init() {
            const saved = JSON.parse(localStorage.getItem('filters_jobs') ?? '{}');
            const validStatuses = ['', 'pending', 'running', 'completed', 'failed'];
            this.statusFilter    = validStatuses.includes(saved.statusFilter ?? '') ? (saved.statusFilter ?? '') : '';
            this.agentTypeFilter = saved.agentTypeFilter ?? '';
            await this.load();
            // Post-load: reset agentTypeFilter if not found in available agent types
            if (this.agentTypeFilter && !Object.keys(this.byAgentType).includes(this.agentTypeFilter)) {
                this.agentTypeFilter = '';
            }
            // Auto-refresh every 15 seconds
            setInterval(() => this.load(), 15000);
            // Countdown ticker
            setInterval(() => {
                this.refreshCountdown = this.refreshCountdown > 1 ? this.refreshCountdown - 1 : this.refreshInterval;
            }, 1000);
        },

        async load() {
            localStorage.setItem('filters_jobs', JSON.stringify({
                statusFilter:    this.statusFilter,
                agentTypeFilter: this.agentTypeFilter,
            }));
            this.loading = true;
            this.refreshCountdown = this.refreshInterval;
            this.clearMessages();
            try {
                const params = new URLSearchParams({ page: this.currentPage });
                if (this.statusFilter)    params.set('status', this.statusFilter);
                if (this.agentTypeFilter) params.set('agent_type', this.agentTypeFilter);

                const r = await fetch('/dashboard/api/jobs?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = this.applyMeta(await r.json());

                this.jobs         = d.data ?? [];
                this.totalEntries = d.total ?? 0;
                this.totalPages   = d.last_page ?? 1;
                this.currentPage  = d.current_page ?? 1;
                this.byStatus     = d.by_status ?? {};
                this.byAgentType  = d.by_agent_type ?? {};
                this.queueTable   = d.queue_table ?? {};

                updateTimestamp();
            } catch (error) {
                this.handleError(error);
            } finally {
                this.loading = false;
            }
        },

        changePage(page) {
            if (page < 1 || page > this.totalPages) return;
            this.currentPage = page;
            this.load();
        },

        jobDuration(job) {
            if (!job.started_at) return '—';
            const start = new Date(job.started_at);
            if (isNaN(start.getTime())) return '—';
            const end = job.completed_at ? new Date(job.completed_at) : new Date();
            const secs = Math.round((end - start) / 1000);
            if (isNaN(secs) || secs < 0) return '—';
            if (secs < 60) return secs + 's';
            return Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
        },

        statusBadge, relativeTime,
    }
}
</script>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.gsap) return;
    gsap.from('.stat-card', { opacity: 0, y: 18, duration: 0.45, stagger: 0.07, ease: 'power2.out', delay: 0.1, clearProps: 'all' });
});
</script>
@endpush
@endsection
