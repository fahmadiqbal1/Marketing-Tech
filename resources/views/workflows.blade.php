@extends('layouts.app')
@section('title', 'Workflows')
@section('subtitle', 'All workflow runs with status and logs')

@section('content')
<div x-data="workflowsApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    {{-- ── Approval Confirmation Modal ──────────────────────────── --}}
    <div x-show="confirmApprovalId" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
         @keydown.escape.window="confirmApprovalId = null">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl p-6 w-full max-w-md mx-4"
             @click.stop>
            <h3 class="text-base font-semibold text-white mb-2">Approve workflow?</h3>
            <p class="text-sm text-slate-400 mb-5">
                This will mark <span class="text-white font-medium" x-text="'&ldquo;' + confirmApprovalName + '&rdquo;'"></span>
                as approved and allow it to continue execution.
            </p>
            <div class="flex justify-end gap-3">
                <button @click="confirmApprovalId = null"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-600 text-slate-400 hover:text-white hover:border-slate-500 transition-colors">
                    Cancel
                </button>
                <button @click="confirmApprove()"
                    class="px-4 py-2 text-sm rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 transition-colors font-medium">
                    Approve
                </button>
            </div>
        </div>
    </div>

    {{-- ── Filters ──────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-3 mb-5">
        <div class="flex bg-slate-800/60 border border-slate-700/50 rounded-lg p-1 gap-0.5">
            <template x-for="s in statuses" :key="s.value">
                <button @click="filter = s.value; load()"
                    class="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
                    :class="filter === s.value ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white'">
                    <span x-text="s.label"></span>
                    <span class="ml-1 text-xs opacity-60" x-text="s.value && counts[s.value] ? '(' + counts[s.value] + ')' : ''"></span>
                </button>
            </template>
        </div>

        <select x-model="typeFilter" @change="load()"
            class="bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg px-3 py-2 focus:ring-brand-500 focus:border-brand-500">
            <option value="">All types</option>
            <option>general</option><option>marketing</option><option>hiring</option>
            <option>media</option><option>growth</option><option>knowledge</option>
        </select>

        <div class="relative flex-1 max-w-xs">
            <input x-model="search" @input.debounce.400ms="load()" type="text" placeholder="Search workflows…"
                class="w-full bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg pl-8 pr-3 py-2 focus:ring-brand-500 focus:border-brand-500 outline-none" />
            <svg class="absolute left-2.5 top-2 w-3.5 h-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs text-slate-500" x-text="total + ' workflows'"></span>
            <button @click="load()" class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">
                <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
        </div>
    </div>

    {{-- ── Table ────────────────────────────────────────────────── --}}
    <div class="bg-slate-800/60 border border-slate-700/50 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-700/60">
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">Workflow</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">Type</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">Created</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">Duration</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading && workflows.length === 0">
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500 text-sm">Loading…</td></tr>
                </template>
                <template x-if="!loading && workflows.length === 0">
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500 text-sm">No workflows found</td></tr>
                </template>
                <template x-for="wf in workflows" :key="wf.id">
                    <tbody>
                        {{-- Main row --}}
                        <tr class="border-b border-slate-700/30 hover:bg-slate-700/20 transition-colors cursor-pointer"
                            @click="toggleDetail(wf.id)">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <svg class="w-3 h-3 text-slate-500 transition-transform flex-shrink-0"
                                         :class="expanded === wf.id ? 'rotate-90' : ''"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="text-slate-100 font-medium truncate max-w-xs" x-text="wf.name"></span>
                                    <template x-if="wf.requires_approval && wf.status === 'owner_approval'">
                                        <span class="badge bg-orange-500/20 text-orange-400 border border-orange-500/30 text-xs">approval needed</span>
                                    </template>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-slate-400 capitalize" x-text="wf.type"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="badge" :class="statusBadge(wf.status)" x-text="wf.status.replace(/_/g,' ')"></span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-400" x-text="relativeTime(wf.created_at)"></td>
                            <td class="px-4 py-3 text-xs text-slate-400" x-text="duration(wf)"></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1.5" @click.stop>
                                    <template x-if="wf.status === 'owner_approval'">
                                        <button @click="confirmApprovalId = wf.id; confirmApprovalName = wf.name"
                                            class="px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600/40 border border-emerald-500/30 transition-colors">
                                            Approve
                                        </button>
                                    </template>
                                    <template x-if="wf.status === 'failed'">
                                        <button @click="retry(wf.id)"
                                            class="px-2.5 py-1 rounded-md text-xs font-medium bg-blue-600/20 text-blue-400 hover:bg-blue-600/40 border border-blue-500/30 transition-colors">
                                            Retry
                                        </button>
                                    </template>
                                    <template x-if="!['completed','failed','cancelled'].includes(wf.status)">
                                        <button @click="cancel(wf.id)"
                                            class="px-2.5 py-1 rounded-md text-xs font-medium bg-red-600/20 text-red-400 hover:bg-red-600/40 border border-red-500/30 transition-colors">
                                            Cancel
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>

                        {{-- Expanded detail panel --}}
                        <template x-if="expanded === wf.id">
                            <tr>
                                <td colspan="6" class="bg-slate-900/60 px-6 py-4 border-b border-slate-700/30">
                                    <div x-show="detailLoading" class="text-xs text-slate-500 py-2">Loading details…</div>
                                    <div x-show="!detailLoading && detail">
                                        {{-- Error --}}
                                        <template x-if="wf.error_message">
                                            <div class="mb-3 p-3 bg-red-500/10 border border-red-500/30 rounded-lg">
                                                <p class="text-xs font-semibold text-red-400 mb-1">Error</p>
                                                <p class="text-xs text-red-300 font-mono" x-text="wf.error_message"></p>
                                            </div>
                                        </template>

                                        {{-- Tasks --}}
                                        <template x-if="detail?.tasks?.length">
                                            <div class="mb-4">
                                                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Tasks</p>
                                                <div class="flex gap-2 flex-wrap">
                                                    <template x-for="t in detail.tasks" :key="t.id">
                                                        <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs"
                                                             :class="statusBadge(t.status)">
                                                            <span x-text="t.name"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Logs --}}
                                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Activity Log</p>
                                        <div class="bg-slate-950/60 rounded-lg border border-slate-700/40 max-h-48 overflow-y-auto p-3 space-y-1 font-mono">
                                            <template x-if="!detail?.logs?.length">
                                                <p class="text-xs text-slate-600">No log entries yet</p>
                                            </template>
                                            <template x-for="log in detail?.logs ?? []" :key="log.id">
                                                <div class="flex gap-2 text-xs">
                                                    <span class="text-slate-600 flex-shrink-0" x-text="log.logged_at?.substring(11,19)"></span>
                                                    <span class="flex-shrink-0"
                                                          :class="{'text-red-400': log.level==='error','text-orange-400': log.level==='warning','text-blue-400': log.level==='info','text-slate-400': !['error','warning','info'].includes(log.level)}"
                                                          x-text="'[' + log.level?.toUpperCase() + ']'"></span>
                                                    <span class="text-slate-300" x-text="log.message"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </template>
            </tbody>
        </table>

        {{-- Pagination --}}
        <div class="flex items-center justify-between px-4 py-3 border-t border-slate-700/40" x-show="lastPage > 1">
            <button @click="page--; load()" :disabled="page <= 1"
                class="px-3 py-1.5 text-xs rounded-lg bg-slate-700/60 text-slate-300 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">← Prev</button>
            <span class="text-xs text-slate-500" x-text="'Page ' + page + ' of ' + lastPage"></span>
            <button @click="page++; load()" :disabled="page >= lastPage"
                class="px-3 py-1.5 text-xs rounded-lg bg-slate-700/60 text-slate-300 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">Next →</button>
        </div>
    </div>

</div>
@endsection

@section('scripts')
<script>
function workflowsApp() {
    return {
        ...dashboardState(),
        workflows: [], filter: '', typeFilter: '', search: '',
        loading: false, total: 0, page: 1, lastPage: 1,
        expanded: null, detail: null, detailLoading: false,
        counts: {},
        confirmApprovalId: null, confirmApprovalName: '',
        statuses: [
            { label: 'All', value: '' },
            { label: 'Active', value: 'intake' },
            { label: 'Approval', value: 'owner_approval' },
            { label: 'Completed', value: 'completed' },
            { label: 'Failed', value: 'failed' },
            { label: 'Cancelled', value: 'cancelled' },
        ],

        async init() {
            await this.load();
            setInterval(() => this.load(), 15000);
        },

        async load() {
            this.loading = true;
            const params = new URLSearchParams({ page: this.page });
            if (this.filter)     params.set('status', this.filter);
            if (this.typeFilter) params.set('type', this.typeFilter);
            if (this.search)     params.set('search', this.search);
            try {
                const d = this.applyMeta(await apiGet('/dashboard/api/workflows?' + params));
                this.workflows = d.data ?? [];
                this.total     = d.total ?? 0;
                this.lastPage  = d.last_page ?? 1;
                updateTimestamp();
            } catch (error) { this.handleError(error); }
            this.loading = false;
        },

        async toggleDetail(id) {
            if (this.expanded === id) { this.expanded = null; return; }
            this.expanded     = id;
            this.detail       = null;
            this.detailLoading = true;
            try {
                this.detail = this.applyMeta(await apiGet('/dashboard/api/workflows/' + id));
            } catch (error) { this.handleError(error); }
            this.detailLoading = false;
        },

        async confirmApprove() {
            const id = this.confirmApprovalId;
            this.confirmApprovalId = null;
            this.confirmApprovalName = '';
            await apiPost('/dashboard/api/workflows/' + id + '/approve');
            this.load();
        },
        async cancel(id) {
            if (!confirm('Cancel this workflow?')) return;
            await apiPost('/dashboard/api/workflows/' + id + '/cancel');
            this.load();
        },
        async retry(id) {
            await apiPost('/dashboard/api/workflows/' + id + '/retry');
            this.load();
        },

        duration(wf) {
            if (!wf.started_at || !wf.completed_at) return '–';
            const ms = new Date(wf.completed_at) - new Date(wf.started_at);
            if (ms < 1000) return ms + 'ms';
            if (ms < 60000) return Math.round(ms/1000) + 's';
            return Math.round(ms/60000) + 'm';
        },

        statusBadge, relativeTime
    }
}
</script>
@endsection
