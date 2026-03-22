@extends('layouts.app')
@section('title', 'Content')
@section('subtitle', 'Drafts, scheduled content, and published output')

@section('content')
<div x-data="contentApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    {{-- ── Stat Cards ──────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="stat-card">
            <p class="text-xs text-slate-400 uppercase mb-1">Total</p>
            <p class="text-3xl font-bold text-slate-200" x-text="totalEntries"></p>
        </div>
        <div class="stat-card cursor-pointer transition-all"
             :class="statusFilter === 'published' ? 'ring-2 ring-brand-500' : 'hover:ring-1 hover:ring-slate-600'"
             @click="statusFilter = (statusFilter === 'published' ? '' : 'published'); currentPage = 1; load()">
            <p class="text-xs text-slate-400 uppercase mb-1">Published</p>
            <p class="text-3xl font-bold text-emerald-400" x-text="statusCounts.published ?? 0"></p>
        </div>
        <div class="stat-card cursor-pointer transition-all"
             :class="statusFilter === 'draft' ? 'ring-2 ring-brand-500' : 'hover:ring-1 hover:ring-slate-600'"
             @click="statusFilter = (statusFilter === 'draft' ? '' : 'draft'); currentPage = 1; load()">
            <p class="text-xs text-slate-400 uppercase mb-1">Drafts</p>
            <p class="text-3xl font-bold text-slate-400" x-text="statusCounts.draft ?? 0"></p>
        </div>
        <div class="stat-card cursor-pointer transition-all"
             :class="statusFilter === 'scheduled' ? 'ring-2 ring-brand-500' : 'hover:ring-1 hover:ring-slate-600'"
             @click="statusFilter = (statusFilter === 'scheduled' ? '' : 'scheduled'); currentPage = 1; load()">
            <p class="text-xs text-slate-400 uppercase mb-1">Scheduled</p>
            <p class="text-3xl font-bold text-amber-400" x-text="statusCounts.scheduled ?? 0"></p>
        </div>
    </div>

    {{-- ── Filters ─────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative flex-1 max-w-xs">
            <input x-model="search" @input.debounce.400ms="currentPage = 1; load()" type="text" placeholder="Search content…"
                class="w-full bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg pl-8 pr-3 py-2 focus:ring-brand-500 focus:border-brand-500 outline-none" />
            <svg class="absolute left-2.5 top-2 w-3.5 h-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <select x-model="typeFilter" @change="currentPage = 1; load()"
            class="bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg px-3 py-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
            <option value="">All types</option>
            <option>blog</option><option>social</option><option>email</option>
            <option>ad</option><option>landing_page</option><option>video_script</option>
        </select>

        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs text-slate-500" x-text="totalEntries + ' items'"></span>
            <button @click="load()" class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">
                <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
        </div>
    </div>

    {{-- ── Table ───────────────────────────────────────────────────── --}}
    <div class="stat-card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-xs uppercase border-b border-slate-700/60">
                <tr>
                    <th class="text-left py-3">Title</th>
                    <th class="text-left py-3">Type</th>
                    <th class="text-left py-3">Status</th>
                    <th class="text-left py-3">Platform</th>
                    <th class="text-left py-3">Words</th>
                    <th class="text-left py-3">Created</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading && !items.length">
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">Loading…</td></tr>
                </template>
                <template x-if="!loading && !items.length">
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">No content items found.</td></tr>
                </template>
                <template x-for="item in items" :key="item.id">
                    <tr class="border-b border-slate-800/60 hover:bg-slate-800/30 transition-colors cursor-pointer"
                        @click="openDetail(item.id)">
                        <td class="py-3">
                            <div class="font-medium text-slate-200 truncate max-w-xs" x-text="item.title"></div>
                            <div class="text-xs text-slate-500" x-text="relativeTime(item.created_at)"></div>
                        </td>
                        <td class="py-3 text-slate-400 text-xs" x-text="item.type"></td>
                        <td class="py-3"><span class="badge" :class="statusBadge(item.status)" x-text="item.status"></span></td>
                        <td class="py-3 text-slate-400 text-xs" x-text="item.platform || '–'"></td>
                        <td class="py-3 text-slate-400" x-text="item.word_count ?? 0"></td>
                        <td class="py-3 text-slate-400 text-xs whitespace-nowrap" x-text="relativeTime(item.created_at)"></td>
                    </tr>
                </template>
            </tbody>
        </table>

        {{-- Pagination --}}
        <div class="flex items-center justify-between mt-4 pt-4 border-t border-slate-800" x-show="totalPages > 1">
            <p class="text-xs text-slate-500" x-text="'Page ' + currentPage + ' of ' + totalPages + ' (' + totalEntries + ' items)'"></p>
            <div class="flex gap-2">
                <button @click="changePage(currentPage - 1)" :disabled="currentPage <= 1"
                        class="px-3 py-1.5 text-xs border border-slate-700 text-slate-400 rounded-lg hover:border-slate-500 hover:text-white transition disabled:opacity-40">Prev</button>
                <button @click="changePage(currentPage + 1)" :disabled="currentPage >= totalPages"
                        class="px-3 py-1.5 text-xs border border-slate-700 text-slate-400 rounded-lg hover:border-slate-500 hover:text-white transition disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>

    {{-- ── Detail Slide Panel ──────────────────────────────────────── --}}
    <div x-show="detailOpen" x-cloak
         class="fixed inset-0 z-50 flex justify-end"
         @click.self="detailOpen = false"
         @keydown.escape.window="detailOpen = false">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="detailOpen = false"></div>
        <div class="relative bg-slate-900 border-l border-slate-700 w-full max-w-2xl h-full overflow-y-auto shadow-2xl flex flex-col">
            {{-- Header --}}
            <div class="flex items-start justify-between p-6 border-b border-slate-700/60 sticky top-0 bg-slate-900 z-10">
                <div class="flex-1 min-w-0 pr-4">
                    <h2 class="text-base font-semibold text-white truncate" x-text="detail?.title ?? 'Loading…'"></h2>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="badge text-xs" :class="statusBadge(detail?.status)" x-text="detail?.status ?? ''"></span>
                        <span class="text-xs text-slate-500" x-text="detail?.type ?? ''"></span>
                        <span class="text-xs text-slate-600" x-show="detail?.platform" x-text="'· ' + (detail?.platform ?? '')"></span>
                    </div>
                </div>
                <button @click="detailOpen = false" class="text-slate-400 hover:text-white transition-colors flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- Loading --}}
            <div x-show="detailLoading" class="flex-1 flex items-center justify-center text-slate-500 text-sm">Loading…</div>

            {{-- Content --}}
            <div x-show="!detailLoading && detail" class="p-6 space-y-5 flex-1">
                {{-- Meta --}}
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="bg-slate-800/60 rounded-lg p-3">
                        <p class="text-slate-500 mb-1">Word count</p>
                        <p class="text-slate-200 font-medium" x-text="detail?.word_count ?? 0"></p>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg p-3">
                        <p class="text-slate-500 mb-1">Created</p>
                        <p class="text-slate-200 font-medium" x-text="detail?.created_at ? relativeTime(detail.created_at) : '–'"></p>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg p-3" x-show="detail?.scheduled_at">
                        <p class="text-slate-500 mb-1">Scheduled</p>
                        <p class="text-amber-400 font-medium" x-text="detail?.scheduled_at ? new Date(detail.scheduled_at).toLocaleString() : '–'"></p>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg p-3" x-show="detail?.tags?.length">
                        <p class="text-slate-500 mb-1">Tags</p>
                        <div class="flex flex-wrap gap-1 mt-1">
                            <template x-for="tag in detail?.tags ?? []" :key="tag">
                                <span class="px-1.5 py-0.5 bg-brand-600/20 text-brand-400 rounded text-xs" x-text="tag"></span>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Body --}}
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Content</p>
                    <div class="bg-slate-950/60 border border-slate-700/40 rounded-lg p-4 max-h-96 overflow-y-auto">
                        <pre class="text-xs text-slate-300 whitespace-pre-wrap font-mono leading-relaxed" x-text="detail?.body || 'No body content.'"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function contentApp() {
    return {
        ...dashboardState(),
        items: [],
        search: '',
        typeFilter: '',
        statusFilter: '',
        currentPage: 1,
        totalPages: 1,
        totalEntries: 0,
        loading: false,
        statusCounts: {},
        detailOpen: false,
        detailLoading: false,
        detail: null,

        async init() { await this.load(); },

        async load() {
            this.loading = true;
            this.clearMessages();
            try {
                const params = new URLSearchParams({ page: this.currentPage });
                if (this.search)       params.set('search', this.search);
                if (this.typeFilter)   params.set('type', this.typeFilter);
                if (this.statusFilter) params.set('status', this.statusFilter);

                const r = await fetch('/dashboard/api/content?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = this.applyMeta(await r.json());

                this.items        = d.data ?? [];
                this.totalEntries = d.total ?? 0;
                this.totalPages   = d.last_page ?? 1;
                this.currentPage  = d.current_page ?? 1;

                // Tally status counts from current unfiltered page
                if (!this.statusFilter && !this.search && !this.typeFilter) {
                    const tally = {};
                    this.items.forEach(i => { tally[i.status] = (tally[i.status] || 0) + 1; });
                    Object.assign(this.statusCounts, tally);
                }

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

        async openDetail(id) {
            this.detailOpen = true;
            this.detailLoading = true;
            this.detail = null;
            try {
                const r = await fetch('/dashboard/api/content/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = await r.json();
                this.detail = d.item ?? null;
            } catch (e) {
                this.handleError(e);
                this.detailOpen = false;
            } finally {
                this.detailLoading = false;
            }
        },

        statusBadge, relativeTime,
    }
}
</script>
@endsection
