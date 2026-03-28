@extends('layouts.app')
@section('title', 'System Events')
@section('subtitle', 'Recent platform events, warnings, and errors')

@section('content')
<div x-data="systemApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    {{-- ── Severity Summary Cards ─────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <template x-for="sev in severities" :key="sev.key">
            <div class="stat-card cursor-pointer transition-all"
                 :class="levelFilter === sev.key ? 'ring-2 ring-brand-500' : 'hover:ring-1 hover:ring-slate-600'"
                 @click="levelFilter = (levelFilter === sev.key ? '' : sev.key); currentPage = 1; load()">
                <p class="text-xs text-slate-500 uppercase tracking-wide mb-1" x-text="sev.label"></p>
                <p class="text-2xl font-bold" :class="sev.color" x-text="counts[sev.key] ?? 0"></p>
            </div>
        </template>
    </div>

    {{-- ── Filters ────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex bg-slate-800/60 border border-slate-700/50 rounded-lg p-1 gap-0.5">
            <button @click="levelFilter = ''; currentPage = 1; load()"
                class="px-3 py-1.5 rounded-md text-xs font-medium transition-all active:scale-95"
                :class="levelFilter === '' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white'">All</button>
            <button @click="levelFilter = 'info'; currentPage = 1; load()"
                class="px-3 py-1.5 rounded-md text-xs font-medium transition-all active:scale-95"
                :class="levelFilter === 'info' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white'">Info</button>
            <button @click="levelFilter = 'warning'; currentPage = 1; load()"
                class="px-3 py-1.5 rounded-md text-xs font-medium transition-all active:scale-95"
                :class="levelFilter === 'warning' ? 'bg-amber-500 text-white' : 'text-slate-400 hover:text-white'">Warning</button>
            <button @click="levelFilter = 'error'; currentPage = 1; load()"
                class="px-3 py-1.5 rounded-md text-xs font-medium transition-all active:scale-95"
                :class="levelFilter === 'error' ? 'bg-red-600 text-white' : 'text-slate-400 hover:text-white'">Error</button>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs text-slate-500" x-text="totalEntries + ' events'"></span>
            <button @click="load()" class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">
                <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
        </div>
    </div>

    {{-- ── Events Table ───────────────────────────────────────────── --}}
    <div class="stat-card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-xs uppercase border-b border-slate-700/60">
                <tr>
                    <th class="text-left py-3">Level</th>
                    <th class="text-left py-3">Event</th>
                    <th class="text-left py-3">Source</th>
                    <th class="text-left py-3">Message</th>
                    <th class="text-left py-3">When</th>
                </tr>
            </thead>
            <tbody>
                {{-- Skeleton loading rows --}}
                <template x-if="loading && !events.length">
                    <template x-for="i in [1,2,3,4,5]" :key="i">
                        <tr class="border-b border-slate-800/60">
                            <td class="py-3"><div class="skeleton h-5 w-16 rounded-full"></div></td>
                            <td class="py-3"><div class="skeleton h-4 w-28"></div></td>
                            <td class="py-3"><div class="skeleton h-4 w-20"></div></td>
                            <td class="py-3"><div class="skeleton h-4 w-48"></div></td>
                            <td class="py-3"><div class="skeleton h-4 w-16"></div></td>
                        </tr>
                    </template>
                </template>
                <template x-if="!loading && !events.length">
                    <tr><td colspan="5" class="py-8 text-center text-slate-500">No system events found.</td></tr>
                </template>
                <template x-for="event in events" :key="event.id">
                    <template x-if="true">
                        <tbody>
                            {{-- Main event row --}}
                            <tr class="border-b border-slate-800/60 align-top hover:bg-slate-800/30 transition-colors cursor-pointer"
                                @click="expanded = expanded === event.id ? null : event.id">
                                <td class="py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="badge" :class="statusBadge(event.level)" x-text="event.level"></span>
                                        <svg class="w-3 h-3 text-slate-500 flex-shrink-0 transition-transform duration-200"
                                             :class="expanded === event.id ? 'rotate-180' : ''"
                                             fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </div>
                                </td>
                                <td class="py-3 text-slate-300" x-text="event.event"></td>
                                <td class="py-3 text-slate-400 text-xs" x-text="event.source || 'app'"></td>
                                <td class="py-3 text-slate-400 max-w-sm truncate" x-text="event.message"></td>
                                <td class="py-3 text-slate-400 text-xs whitespace-nowrap" x-text="relativeTime(event.created_at)"></td>
                            </tr>
                            {{-- Expandable detail row --}}
                            <tr x-show="expanded === event.id" class="bg-slate-900/60">
                                <td colspan="5" class="px-4 pb-4 pt-2">
                                    <div class="space-y-3">
                                        {{-- Full message --}}
                                        <div>
                                            <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">Full Message</p>
                                            <p class="text-sm text-slate-300 whitespace-pre-wrap" x-text="event.message"></p>
                                        </div>
                                        {{-- Payload / context --}}
                                        <div x-show="event.payload && Object.keys(event.payload).length > 0">
                                            <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">Payload</p>
                                            <div class="border border-slate-700/50 bg-slate-800/60 rounded-lg p-3">
                                                <pre class="text-xs text-slate-300 font-mono whitespace-pre-wrap overflow-x-auto" x-text="JSON.stringify(event.payload, null, 2)"></pre>
                                            </div>
                                        </div>
                                        <div x-show="event.context && Object.keys(event.context).length > 0">
                                            <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">Context</p>
                                            <div class="border border-slate-700/50 bg-slate-800/60 rounded-lg p-3">
                                                <pre class="text-xs text-slate-300 font-mono whitespace-pre-wrap overflow-x-auto" x-text="JSON.stringify(event.context, null, 2)"></pre>
                                            </div>
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
            <p class="text-xs text-slate-500" x-text="'Page ' + currentPage + ' of ' + totalPages + ' (' + totalEntries + ' events)'"></p>
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
function systemApp() {
    return {
        ...dashboardState(),
        events: [],
        levelFilter: '',
        currentPage: 1,
        totalPages: 1,
        totalEntries: 0,
        loading: false,
        expanded: null,
        counts: {},
        severities: [
            { key: 'info',    label: 'Info',    color: 'text-sky-400' },
            { key: 'warning', label: 'Warning', color: 'text-amber-400' },
            { key: 'error',   label: 'Error',   color: 'text-red-400' },
            { key: 'debug',   label: 'Debug',   color: 'text-slate-400' },
        ],

        async init() {
            const saved = JSON.parse(localStorage.getItem('filters_system') ?? '{}');
            const validLevels = ['', 'info', 'warning', 'error', 'debug'];
            this.levelFilter = validLevels.includes(saved.levelFilter ?? '') ? (saved.levelFilter ?? '') : '';
            // Run load and counts in parallel
            await Promise.all([this.load(), this.loadCounts()]);
            // Auto-refresh every 20 seconds
            setInterval(() => this.load(), 20000);
        },

        async loadCounts() {
            for (const sev of this.severities) {
                try {
                    const r = await fetch(`/dashboard/api/system-events?level=${sev.key}&per_page=1`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const d = await r.json();
                    this.counts[sev.key] = d.total ?? 0;
                } catch(_) {}
            }
        },

        async load() {
            localStorage.setItem('filters_system', JSON.stringify({
                levelFilter: this.levelFilter,
            }));
            this.loading = true;
            this.clearMessages();
            try {
                const params = new URLSearchParams({ page: this.currentPage });
                if (this.levelFilter) params.set('level', this.levelFilter);

                const r = await fetch('/dashboard/api/system-events?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = this.applyMeta(await r.json());

                this.events       = d.data ?? [];
                this.totalEntries = d.total ?? 0;
                this.totalPages   = d.last_page ?? 1;
                this.currentPage  = d.current_page ?? 1;

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
