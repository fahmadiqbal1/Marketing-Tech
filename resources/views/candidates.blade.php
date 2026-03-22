@extends('layouts.app')
@section('title', 'Candidates')
@section('subtitle', 'Hiring pipeline visibility and candidate readiness')

@section('content')
<div x-data="candidatesApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    {{-- ── Pipeline Stage Cards ────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-2" x-show="Object.keys(byStage).length > 0">
        <template x-for="[stage, count] in Object.entries(byStage)" :key="stage">
            <button @click="stageFilter = (stageFilter === stage ? '' : stage); currentPage = 1; load()"
                class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-all"
                :class="stageFilter === stage
                    ? 'bg-brand-600 border-brand-500 text-white'
                    : 'bg-slate-800/60 border-slate-700/50 text-slate-300 hover:border-slate-500'">
                <span x-text="stage"></span>
                <span class="ml-1.5 opacity-60" x-text="count"></span>
            </button>
        </template>
    </div>

    {{-- ── Filters ─────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative flex-1 max-w-xs">
            <input x-model="search" @input.debounce.400ms="currentPage = 1; load()" type="text" placeholder="Search name or email…"
                class="w-full bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg pl-8 pr-3 py-2 focus:ring-brand-500 focus:border-brand-500 outline-none" />
            <svg class="absolute left-2.5 top-2 w-3.5 h-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs text-slate-500" x-text="totalEntries + ' candidates'"></span>
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
                    <th class="text-left py-3">Candidate</th>
                    <th class="text-left py-3">Stage</th>
                    <th class="text-left py-3">Score</th>
                    <th class="text-left py-3">Current role</th>
                    <th class="text-left py-3">Links</th>
                    <th class="text-left py-3">Added</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading && !candidates.length">
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">Loading…</td></tr>
                </template>
                <template x-if="!loading && !candidates.length">
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">No candidates found.</td></tr>
                </template>
                <template x-for="c in candidates" :key="c.id">
                    <tr class="border-b border-slate-800/60 hover:bg-slate-800/30 transition-colors cursor-pointer"
                        @click="openDetail(c.id)">
                        <td class="py-3">
                            <div class="font-medium text-slate-200" x-text="c.name"></div>
                            <div class="text-xs text-slate-500" x-text="c.email || '—'"></div>
                        </td>
                        <td class="py-3">
                            <span class="badge" :class="statusBadge(c.pipeline_stage)" x-text="c.pipeline_stage ?? '—'"></span>
                        </td>
                        <td class="py-3">
                            <div class="flex items-center gap-2">
                                <span class="text-slate-200 font-medium" x-text="c.score != null ? c.score : '—'"></span>
                                <div x-show="c.score != null" class="w-16 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all"
                                         :class="c.score >= 80 ? 'bg-emerald-500' : c.score >= 60 ? 'bg-amber-500' : 'bg-red-500'"
                                         :style="'width:' + Math.min(c.score, 100) + '%'"></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 text-slate-400 text-xs" x-text="[c.current_title, c.current_company].filter(Boolean).join(' @ ') || '—'"></td>
                        <td class="py-3">
                            <div class="flex gap-2">
                                <a x-show="c.linkedin_url" :href="c.linkedin_url" target="_blank" @click.stop
                                   class="text-xs text-sky-400 hover:text-sky-300 transition-colors">LI</a>
                                <a x-show="c.github_url" :href="c.github_url" target="_blank" @click.stop
                                   class="text-xs text-slate-400 hover:text-white transition-colors">GH</a>
                            </div>
                        </td>
                        <td class="py-3 text-slate-400 text-xs whitespace-nowrap" x-text="relativeTime(c.created_at)"></td>
                    </tr>
                </template>
            </tbody>
        </table>

        {{-- Pagination --}}
        <div class="flex items-center justify-between mt-4 pt-4 border-t border-slate-800" x-show="totalPages > 1">
            <p class="text-xs text-slate-500" x-text="'Page ' + currentPage + ' of ' + totalPages + ' (' + totalEntries + ' candidates)'"></p>
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
         @keydown.escape.window="detailOpen = false">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="detailOpen = false"></div>
        <div class="relative bg-slate-900 border-l border-slate-700 w-full max-w-2xl h-full overflow-y-auto shadow-2xl flex flex-col">

            {{-- Header --}}
            <div class="flex items-start justify-between p-6 border-b border-slate-700/60 sticky top-0 bg-slate-900 z-10">
                <div class="flex-1 min-w-0 pr-4">
                    <h2 class="text-base font-semibold text-white" x-text="detail?.name ?? 'Loading…'"></h2>
                    <p class="text-xs text-slate-400 mt-0.5" x-text="[detail?.current_title, detail?.current_company].filter(Boolean).join(' @ ') || ''"></p>
                    <div class="flex items-center gap-2 mt-1.5">
                        <span class="badge text-xs" :class="statusBadge(detail?.pipeline_stage)" x-text="detail?.pipeline_stage ?? ''"></span>
                        <span class="text-xs text-slate-500" x-show="detail?.score != null" x-text="'Score: ' + detail?.score"></span>
                    </div>
                </div>
                <button @click="detailOpen = false" class="text-slate-400 hover:text-white transition-colors flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div x-show="detailLoading" class="flex-1 flex items-center justify-center text-slate-500 text-sm">Loading…</div>

            <div x-show="!detailLoading && detail" class="p-6 space-y-5 flex-1">

                {{-- Contact + Links --}}
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="bg-slate-800/60 rounded-lg p-3" x-show="detail?.email">
                        <p class="text-slate-500 mb-1">Email</p>
                        <p class="text-slate-200" x-text="detail?.email"></p>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg p-3" x-show="detail?.phone">
                        <p class="text-slate-500 mb-1">Phone</p>
                        <p class="text-slate-200" x-text="detail?.phone"></p>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg p-3" x-show="detail?.location">
                        <p class="text-slate-500 mb-1">Location</p>
                        <p class="text-slate-200" x-text="detail?.location"></p>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg p-3" x-show="detail?.years_experience">
                        <p class="text-slate-500 mb-1">Experience</p>
                        <p class="text-slate-200" x-text="(detail?.years_experience ?? 0) + ' years'"></p>
                    </div>
                </div>

                {{-- External links --}}
                <div class="flex gap-3" x-show="detail?.linkedin_url || detail?.github_url">
                    <a x-show="detail?.linkedin_url" :href="detail?.linkedin_url" target="_blank"
                       class="px-3 py-1.5 text-xs rounded-lg bg-sky-600/20 text-sky-400 border border-sky-500/30 hover:bg-sky-600/40 transition-colors">
                        LinkedIn ↗
                    </a>
                    <a x-show="detail?.github_url" :href="detail?.github_url" target="_blank"
                       class="px-3 py-1.5 text-xs rounded-lg bg-slate-700/60 text-slate-300 border border-slate-600 hover:bg-slate-700 transition-colors">
                        GitHub ↗
                    </a>
                </div>

                {{-- Skills --}}
                <div x-show="detail?.skills?.length">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Skills</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="skill in detail?.skills ?? []" :key="skill">
                            <span class="px-2 py-0.5 bg-brand-600/20 text-brand-400 border border-brand-500/20 rounded text-xs" x-text="skill"></span>
                        </template>
                    </div>
                </div>

                {{-- Score breakdown --}}
                <div x-show="detail?.score_details && Object.keys(detail?.score_details ?? {}).length">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Score breakdown</p>
                    <div class="space-y-1.5">
                        <template x-for="[key, val] in Object.entries(detail?.score_details ?? {})" :key="key">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-slate-400 capitalize" x-text="key.replace(/_/g,' ')"></span>
                                <span class="text-slate-200 font-medium" x-text="val"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Summary --}}
                <div x-show="detail?.summary">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Summary</p>
                    <p class="text-xs text-slate-300 leading-relaxed" x-text="detail?.summary"></p>
                </div>

                {{-- Experience --}}
                <div x-show="detail?.experience?.length">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Experience</p>
                    <div class="space-y-3">
                        <template x-for="(exp, i) in detail?.experience ?? []" :key="i">
                            <div class="border-l-2 border-slate-700 pl-3">
                                <p class="text-xs font-medium text-slate-200" x-text="(exp.title ?? '') + ' @ ' + (exp.company ?? '')"></p>
                                <p class="text-xs text-slate-500 mt-0.5" x-text="exp.summary ?? ''"></p>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Pipeline notes --}}
                <div x-show="detail?.pipeline_notes">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Pipeline Notes</p>
                    <p class="text-xs text-slate-300 leading-relaxed bg-slate-800/60 rounded-lg p-3" x-text="detail?.pipeline_notes"></p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function candidatesApp() {
    return {
        ...dashboardState(),
        candidates: [],
        byStage: {},
        search: '',
        stageFilter: '',
        currentPage: 1,
        totalPages: 1,
        totalEntries: 0,
        loading: false,
        detailOpen: false,
        detailLoading: false,
        detail: null,

        async init() {
            const saved = JSON.parse(localStorage.getItem('filters_candidates') ?? '{}');
            this.stageFilter = saved.stageFilter ?? '';
            this.search      = saved.search ?? '';
            await this.load();
            // Post-load: reset stageFilter if stage no longer exists in data
            if (this.stageFilter && !Object.keys(this.byStage).includes(this.stageFilter)) {
                this.stageFilter = '';
            }
        },

        async load() {
            localStorage.setItem('filters_candidates', JSON.stringify({
                stageFilter: this.stageFilter,
                search:      this.search,
            }));
            this.loading = true;
            this.clearMessages();
            try {
                const params = new URLSearchParams({ page: this.currentPage });
                if (this.search)      params.set('search', this.search);
                if (this.stageFilter) params.set('pipeline_stage', this.stageFilter);

                const r = await fetch('/dashboard/api/candidates?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = this.applyMeta(await r.json());

                this.candidates   = d.data ?? [];
                this.totalEntries = d.total ?? 0;
                this.totalPages   = d.last_page ?? 1;
                this.currentPage  = d.current_page ?? 1;
                this.byStage      = d.by_stage ?? {};

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
                const r = await fetch('/dashboard/api/candidates/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = await r.json();
                this.detail = d.candidate ?? null;
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
