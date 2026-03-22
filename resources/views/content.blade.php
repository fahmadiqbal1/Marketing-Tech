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
                    <div class="bg-slate-950/60 border border-slate-700/40 rounded-lg p-4 max-h-64 overflow-y-auto">
                        <pre class="text-xs text-slate-300 whitespace-pre-wrap font-mono leading-relaxed" x-text="detail?.body || 'No body content.'"></pre>
                    </div>
                </div>

                {{-- Hashtag Suggestions --}}
                <div x-show="hashtagSuggestions.length > 0 || hashtagLoading">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Suggested Hashtags</p>
                        <button x-show="hashtagSuggestions.length > 0"
                                @click="copyHashtags()"
                                class="text-xs text-brand-400 hover:text-brand-300 transition-colors">
                            <span x-text="hashtagCopied ? '✓ Copied' : 'Copy all'"></span>
                        </button>
                    </div>
                    <div x-show="hashtagLoading" class="text-xs text-slate-500 py-2">Loading hashtags…</div>
                    <div x-show="!hashtagLoading" class="flex flex-wrap gap-1.5">
                        <template x-for="tag in hashtagSuggestions" :key="tag">
                            <span class="px-2 py-0.5 bg-brand-600/15 text-brand-400 border border-brand-600/20 rounded-full text-xs cursor-pointer hover:bg-brand-600/25 transition-colors"
                                  @click="copyTag(tag)"
                                  x-text="tag"></span>
                        </template>
                    </div>
                </div>

                {{-- Platform Preview --}}
                <div x-show="detail?.platform">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Platform Preview</p>

                    {{-- Instagram --}}
                    <template x-if="detail?.platform === 'instagram'">
                        <div class="bg-slate-950 border border-slate-700/40 rounded-xl overflow-hidden max-w-xs">
                            <div class="flex items-center gap-2 p-3 border-b border-slate-800">
                                <div class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500 to-pink-500 flex items-center justify-center text-white text-xs font-bold">M</div>
                                <span class="text-xs font-semibold text-slate-200">your_brand</span>
                                <span class="ml-auto text-slate-500">···</span>
                            </div>
                            <div class="bg-slate-800/60 h-36 flex items-center justify-center">
                                <svg class="w-8 h-8 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                            <div class="p-3">
                                <p class="text-xs text-slate-300 leading-relaxed line-clamp-3" x-text="(detail?.body ?? '').substring(0, 120) + '…'"></p>
                                <p class="text-xs text-brand-400 mt-1.5" x-text="hashtagSuggestions.slice(0, 5).join(' ')"></p>
                            </div>
                        </div>
                    </template>

                    {{-- Twitter / X --}}
                    <template x-if="detail?.platform === 'twitter'">
                        <div class="bg-slate-950 border border-slate-700/40 rounded-xl p-4 max-w-xs">
                            <div class="flex gap-3">
                                <div class="w-9 h-9 rounded-full bg-slate-700 flex items-center justify-center text-white text-sm font-bold shrink-0">M</div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1 mb-1">
                                        <span class="text-xs font-bold text-slate-200">Your Brand</span>
                                        <span class="text-xs text-slate-500">@your_brand · now</span>
                                    </div>
                                    <p class="text-xs text-slate-300 leading-relaxed" x-text="(detail?.body ?? '').substring(0, 280)"></p>
                                    <p class="text-xs text-sky-400 mt-1" x-text="hashtagSuggestions.slice(0, 3).join(' ')"></p>
                                    <div class="flex gap-5 mt-2 text-slate-600 text-xs">
                                        <span>♡ 0</span><span>↺ 0</span><span>💬 0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- LinkedIn --}}
                    <template x-if="detail?.platform === 'linkedin'">
                        <div class="bg-slate-950 border border-slate-700/40 rounded-xl p-4 max-w-xs">
                            <div class="flex gap-3 mb-3">
                                <div class="w-10 h-10 rounded bg-slate-700 flex items-center justify-center text-white text-sm font-bold shrink-0">M</div>
                                <div>
                                    <p class="text-xs font-semibold text-slate-200">Your Brand</p>
                                    <p class="text-xs text-slate-500">Marketing · now</p>
                                </div>
                            </div>
                            <p class="text-xs text-slate-300 leading-relaxed line-clamp-4" x-text="(detail?.body ?? '').substring(0, 200)"></p>
                            <p class="text-xs text-sky-400 mt-1.5" x-text="hashtagSuggestions.slice(0, 4).join(' ')"></p>
                            <div class="flex gap-4 mt-3 pt-2 border-t border-slate-800 text-xs text-slate-500">
                                <span>👍 Like</span><span>💬 Comment</span><span>↗ Share</span>
                            </div>
                        </div>
                    </template>

                    {{-- TikTok --}}
                    <template x-if="detail?.platform === 'tiktok'">
                        <div class="bg-slate-950 border border-slate-700/40 rounded-xl overflow-hidden max-w-xs">
                            <div class="bg-slate-800/60 h-40 flex flex-col items-center justify-center relative">
                                <svg class="w-8 h-8 text-slate-600 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.069A1 1 0 0121 8.82v6.36a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                <span class="text-xs text-slate-500">Video content</span>
                                <div class="absolute bottom-2 left-3 right-3">
                                    <p class="text-xs text-white/80 leading-snug line-clamp-2" x-text="(detail?.body ?? '').substring(0, 80)"></p>
                                    <p class="text-xs text-pink-400 mt-0.5" x-text="hashtagSuggestions.slice(0, 4).join(' ')"></p>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Generic fallback --}}
                    <template x-if="!['instagram','twitter','linkedin','tiktok'].includes(detail?.platform ?? '')">
                        <div class="bg-slate-800/40 border border-slate-700/40 rounded-lg p-4 max-w-xs">
                            <p class="text-xs text-slate-400 capitalize" x-text="(detail?.platform ?? 'generic') + ' post'"></p>
                            <p class="text-xs text-slate-300 mt-1 line-clamp-4" x-text="(detail?.body ?? '').substring(0, 200)"></p>
                        </div>
                    </template>
                </div>

                {{-- Add to Calendar CTA --}}
                <div class="pt-1 border-t border-slate-700/50 flex justify-end">
                    <button @click="calendarModalOpen = true"
                            class="flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white text-xs font-medium rounded-lg transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Add to Calendar
                    </button>
                </div>
            </div>

            {{-- Add to Calendar Modal (nested inside slide panel) --}}
            <div x-show="calendarModalOpen" x-cloak
                 class="absolute inset-0 z-20 flex items-center justify-center bg-black/60 backdrop-blur-sm"
                 @click.self="calendarModalOpen = false">
                <div class="bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
                    <div class="flex items-center justify-between mb-5">
                        <h3 class="text-sm font-semibold text-white">Add to Content Calendar</h3>
                        <button @click="calendarModalOpen = false" class="text-slate-400 hover:text-white transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <label class="text-xs text-slate-400 mb-1 block">Platform</label>
                            <select x-model="calendarForm.platform"
                                    @change="fetchHashtagSuggestions(calendarForm.platform)"
                                    class="w-full bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg px-3 py-2 outline-none focus:border-brand-500">
                                <option value="">Select platform…</option>
                                <option value="instagram">Instagram</option>
                                <option value="tiktok">TikTok</option>
                                <option value="twitter">Twitter / X</option>
                                <option value="linkedin">LinkedIn</option>
                                <option value="facebook">Facebook</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-slate-400 mb-1 block">Content Type</label>
                            <select x-model="calendarForm.content_type"
                                    class="w-full bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg px-3 py-2 outline-none focus:border-brand-500">
                                <option value="">Select type…</option>
                                <option value="post">Post</option>
                                <option value="reel">Reel / Short video</option>
                                <option value="carousel">Carousel</option>
                                <option value="story">Story</option>
                                <option value="thread">Thread</option>
                                <option value="article">Article</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-slate-400 mb-1 block">Schedule At</label>
                            <input x-model="calendarForm.scheduled_at" type="datetime-local"
                                   class="w-full bg-slate-800 border border-slate-700 text-slate-300 text-xs rounded-lg px-3 py-2 outline-none focus:border-brand-500" />
                        </div>
                        <div x-show="calendarError" class="text-xs text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-3 py-2" x-text="calendarError"></div>
                        <div x-show="calendarSuccess" class="text-xs text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-lg px-3 py-2">Added to calendar successfully.</div>
                    </div>
                    <div class="flex gap-2 mt-5">
                        <button @click="calendarModalOpen = false"
                                class="flex-1 px-3 py-2 text-xs text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                            Cancel
                        </button>
                        <button @click="addToCalendar()"
                                :disabled="calendarSaving || !calendarForm.platform || !calendarForm.content_type"
                                class="flex-1 px-3 py-2 text-xs bg-brand-600 hover:bg-brand-500 disabled:opacity-50 text-white rounded-lg transition-colors font-medium">
                            <span x-text="calendarSaving ? 'Saving…' : 'Schedule'"></span>
                        </button>
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
        hashtagSuggestions: [],
        hashtagLoading: false,
        hashtagCopied: false,
        calendarModalOpen: false,
        calendarSaving: false,
        calendarError: '',
        calendarSuccess: false,
        calendarForm: { platform: '', content_type: '', scheduled_at: '' },

        async init() {
            const saved = JSON.parse(localStorage.getItem('filters_content') ?? '{}');
            const validTypes    = ['', 'blog', 'social', 'email', 'ad', 'landing_page', 'video_script'];
            const validStatuses = ['', 'draft', 'scheduled', 'published', 'failed'];
            this.typeFilter   = validTypes.includes(saved.typeFilter ?? '')    ? (saved.typeFilter ?? '')   : '';
            this.statusFilter = validStatuses.includes(saved.statusFilter ?? '') ? (saved.statusFilter ?? '') : '';
            this.search       = saved.search ?? '';
            await this.load();
        },

        async load() {
            localStorage.setItem('filters_content', JSON.stringify({
                typeFilter:   this.typeFilter,
                statusFilter: this.statusFilter,
                search:       this.search,
            }));
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
            this.hashtagSuggestions = [];
            this.calendarModalOpen = false;
            this.calendarSuccess = false;
            this.calendarError = '';
            try {
                const r = await fetch('/dashboard/api/content/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = await r.json();
                this.detail = d.item ?? null;
                if (this.detail?.platform) {
                    this.calendarForm.platform = this.detail.platform;
                    this.fetchHashtagSuggestions(this.detail.platform);
                }
            } catch (e) {
                this.handleError(e);
                this.detailOpen = false;
            } finally {
                this.detailLoading = false;
            }
        },

        async fetchHashtagSuggestions(platform) {
            if (!platform) { this.hashtagSuggestions = []; return; }
            this.hashtagLoading = true;
            try {
                const r = await fetch('/dashboard/api/hashtag-sets?platform=' + encodeURIComponent(platform) + '&per_page=3', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!r.ok) return;
                const d = await r.json();
                const sets = d.data ?? d ?? [];
                // Flatten tags from first matching set
                const tags = sets.length > 0 ? (sets[0].tags ?? []) : [];
                this.hashtagSuggestions = Array.isArray(tags) ? tags.slice(0, 15) : [];
            } catch (_) {
                this.hashtagSuggestions = [];
            } finally {
                this.hashtagLoading = false;
            }
        },

        copyHashtags() {
            const text = this.hashtagSuggestions.join(' ');
            navigator.clipboard?.writeText(text).then(() => {
                this.hashtagCopied = true;
                setTimeout(() => { this.hashtagCopied = false; }, 2000);
            });
        },

        copyTag(tag) {
            navigator.clipboard?.writeText(tag);
        },

        async addToCalendar() {
            this.calendarError = '';
            this.calendarSuccess = false;
            if (!this.calendarForm.platform || !this.calendarForm.content_type) {
                this.calendarError = 'Platform and content type are required.';
                return;
            }
            this.calendarSaving = true;
            try {
                const payload = {
                    platform:     this.calendarForm.platform,
                    content_type: this.calendarForm.content_type,
                    scheduled_at: this.calendarForm.scheduled_at || null,
                    body:         this.detail?.body ?? '',
                    title:        this.detail?.title ?? '',
                    hashtags:     this.hashtagSuggestions,
                };
                const r = await fetch('/dashboard/api/content-calendar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                const d = await r.json();
                if (!r.ok) {
                    this.calendarError = d.message ?? ('Error ' + r.status);
                    return;
                }
                this.calendarSuccess = true;
                this.calendarForm = { platform: this.detail?.platform ?? '', content_type: '', scheduled_at: '' };
                setTimeout(() => { this.calendarModalOpen = false; this.calendarSuccess = false; }, 1800);
            } catch (e) {
                this.calendarError = e.message;
            } finally {
                this.calendarSaving = false;
            }
        },

        statusBadge, relativeTime,
    }
}
</script>
@endsection
