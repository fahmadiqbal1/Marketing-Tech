@extends('layouts.app')
@section('title', 'Knowledge Base')
@section('subtitle', 'RAG vector store — browse, search, and manage agent memory')

@section('content')
<div x-data="knowledgeApp()" x-init="init()" x-cloak>

    {{-- ── Toast ──────────────────────────────────────────────────────── --}}
    <template x-if="toast.show">
        <div class="fixed top-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-xl text-sm font-medium"
             :class="toast.error ? 'bg-red-500/20 border border-red-500/40 text-red-300' : 'bg-emerald-500/20 border border-emerald-500/40 text-emerald-300'">
            <span x-text="toast.message"></span>
        </div>
    </template>

    {{-- ── Add Knowledge Slide Panel ────────────────────────────────── --}}
    <div x-show="addPanel" class="fixed inset-0 z-40 flex">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="addPanel = false"></div>
        <div class="relative ml-auto w-full max-w-xl bg-slate-900 border-l border-slate-700 h-full flex flex-col shadow-2xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Add to Knowledge Base</h3>
                <button @click="addPanel = false" class="text-slate-500 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- Mode Toggle --}}
            <div class="flex border-b border-slate-800 px-6">
                <button @click="importMode = 'manual'"
                        class="py-3 px-4 text-sm font-medium border-b-2 transition"
                        :class="importMode === 'manual' ? 'border-brand-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300'">
                    Manual Entry
                </button>
                <button @click="importMode = 'github'"
                        class="py-3 px-4 text-sm font-medium border-b-2 transition"
                        :class="importMode === 'github' ? 'border-brand-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300'">
                    GitHub Import
                </button>
            </div>

            {{-- GitHub Import Form --}}
            <div x-show="importMode === 'github'" class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                <p class="text-xs text-slate-500">Import files from a public or private GitHub repository into the knowledge base. Files are queued for background processing.</p>
                <div>
                    <label class="text-xs text-slate-400 uppercase mb-1 block">Repository URL <span class="text-red-400">*</span></label>
                    <input type="text" x-model="githubImport.repoUrl"
                           placeholder="https://github.com/owner/repo"
                           class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs text-slate-400 uppercase mb-1 block">Category</label>
                        <select x-model="githubImport.category"
                                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500">
                            <option value="general">General</option>
                            <option value="brand">Brand</option>
                            <option value="marketing">Marketing</option>
                            <option value="content">Content</option>
                            <option value="technical">Technical</option>
                            <option value="agent-skills">Agent Skills</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400 uppercase mb-1 block">Branch</label>
                        <input type="text" x-model="githubImport.branch" placeholder="main"
                               class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                    </div>
                </div>
                <div>
                    <label class="text-xs text-slate-400 uppercase mb-1 block">GitHub Token (optional, for private repos)</label>
                    <input type="password" x-model="githubImport.token"
                           placeholder="ghp_..."
                           class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                </div>
                <div class="bg-slate-800/60 border border-slate-700/50 rounded-lg p-3 text-xs text-slate-400 space-y-1">
                    <p class="font-semibold text-slate-300">Import limits:</p>
                    <p>• Max 200 files per import (md, txt, php, js, py, json, yaml)</p>
                    <p>• Max 200 KB per file, 200,000 chars total per repo</p>
                    <p>• Vendor, node_modules, and dist directories are skipped</p>
                    <p>• Duplicates are automatically skipped via content hash</p>
                </div>
                <div x-show="githubResult" class="rounded-lg px-3 py-2 text-xs"
                     :class="githubResult.error ? 'bg-red-500/10 text-red-400 border border-red-500/30' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'"
                     x-text="githubResult.message || githubResult.error"></div>

                {{-- Import progress --}}
                <div x-show="importProgress" class="rounded-lg border border-slate-700/60 bg-slate-800/60 px-3 py-3 space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-300 font-medium" x-text="importProgress?.status === 'completed' ? 'Import complete' : importProgress?.status === 'failed' ? 'Import failed' : 'Importing\u2026'"></span>
                        <span class="text-slate-400" x-text="(importProgress?.ingested ?? 0) + ' / ' + (importProgress?.total ?? '?') + ' files'"></span>
                    </div>
                    <div class="w-full h-1.5 bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-500"
                             :class="importProgress?.status === 'failed' ? 'bg-red-500' : importProgress?.status === 'completed' ? 'bg-emerald-500' : 'bg-brand-500'"
                             :style="'width:' + (importProgress?.total ? Math.round((importProgress.ingested / importProgress.total) * 100) : 5) + '%'"></div>
                    </div>
                    <p x-show="importProgress?.error" class="text-xs text-red-400" x-text="importProgress?.error"></p>
                </div>
            </div>
            <div x-show="importMode === 'github'" class="px-6 py-4 border-t border-slate-800 flex gap-3">
                <button @click="addPanel = false"
                        class="flex-1 py-2 border border-slate-700 text-slate-400 text-sm rounded-lg hover:border-slate-500 hover:text-white transition">Cancel</button>
                <button @click="importGitHub()" :disabled="importing"
                        class="flex-1 py-2 bg-brand-600 hover:bg-brand-500 text-white text-sm font-medium rounded-lg transition disabled:opacity-50">
                    <span x-text="importing ? 'Queuing...' : 'Import Repository'"></span>
                </button>
            </div>

            {{-- Manual Entry Form --}}
            <div x-show="importMode === 'manual'" class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                <div>
                    <label class="text-xs text-slate-400 uppercase mb-1 block">Title <span class="text-red-400">*</span></label>
                    <input type="text" x-model="newEntry.title"
                           placeholder="e.g. Brand Voice Guidelines"
                           class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                </div>
                <div>
                    <label class="text-xs text-slate-400 uppercase mb-1 block">Content <span class="text-red-400">*</span></label>
                    <textarea x-model="newEntry.content" rows="16"
                              placeholder="Paste or type the knowledge content here. Long content will be auto-chunked and embedded."
                              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition resize-none font-mono leading-relaxed"></textarea>
                    <p class="text-xs text-slate-600 mt-1" x-text="(newEntry.content || '').length + ' chars — will auto-chunk for embedding'"></p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs text-slate-400 uppercase mb-1 block">Category</label>
                        <select x-model="newEntry.category"
                                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500">
                            <option value="general">General</option>
                            <option value="brand">Brand</option>
                            <option value="marketing">Marketing</option>
                            <option value="content">Content</option>
                            <option value="hiring">Hiring</option>
                            <option value="growth">Growth</option>
                            <option value="product">Product</option>
                            <option value="technical">Technical</option>
                            <option value="agent-skills">Agent Skills</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400 uppercase mb-1 block">Tags (comma-separated)</label>
                        <input type="text" x-model="newEntry.tagsRaw"
                               placeholder="e.g. brand, tone, voice"
                               class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                    </div>
                </div>
                <div class="bg-slate-800/60 border border-slate-700/50 rounded-lg p-3 text-xs text-slate-400 space-y-1">
                    <p class="font-semibold text-slate-300">How RAG works here:</p>
                    <p>1. Content is chunked into ~1000-char segments with 200-char overlap</p>
                    <p>2. Each chunk is embedded via OpenAI text-embedding-3-large (3072 dims)</p>
                    <p>3. Stored in pgvector for cosine-similarity semantic search</p>
                    <p>4. Agents automatically retrieve the top-3 relevant chunks before each task</p>
                </div>
            </div>
            <div x-show="importMode === 'manual'" class="px-6 py-4 border-t border-slate-800 flex gap-3">
                <button @click="addPanel = false"
                        class="flex-1 py-2 border border-slate-700 text-slate-400 text-sm rounded-lg hover:border-slate-500 hover:text-white transition">Cancel</button>
                <button @click="createEntry()" :disabled="adding"
                        class="flex-1 py-2 bg-brand-600 hover:bg-brand-500 text-white text-sm font-medium rounded-lg transition disabled:opacity-50">
                    <span x-text="adding ? 'Embedding & Storing...' : 'Store Knowledge'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── Stats Bar ────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="stat-card">
            <p class="text-xs text-slate-400 uppercase mb-1">Knowledge Entries</p>
            <p class="text-3xl font-bold text-white" x-text="stats.total_entries ?? '–'"></p>
            <p class="text-xs text-slate-500 mt-1">top-level documents</p>
        </div>
        <div class="stat-card">
            <p class="text-xs text-slate-400 uppercase mb-1">Total Chunks</p>
            <p class="text-3xl font-bold text-white" x-text="stats.total_chunks ?? '–'"></p>
            <p class="text-xs text-slate-500 mt-1">embedded vector segments</p>
        </div>
        <div class="stat-card">
            <p class="text-xs text-slate-400 uppercase mb-1">Categories</p>
            <p class="text-3xl font-bold text-white" x-text="(stats.categories || []).length || '–'"></p>
            <div class="flex flex-wrap gap-1 mt-1">
                <template x-for="cat in (stats.categories || []).slice(0,5)" :key="cat">
                    <span class="badge text-xs bg-slate-700/60 text-slate-400 border border-slate-700" x-text="cat"></span>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Search + Filter + Add ────────────────────────────────────── --}}
    <div class="stat-card mb-4">
        <div class="flex items-center gap-3">
            <div class="relative flex-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" x-model="search" @input.debounce.400ms="load()"
                       placeholder="Search by title or content..."
                       class="w-full bg-slate-900 border border-slate-700 rounded-lg pl-9 pr-4 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-brand-500 transition">
            </div>
            <select x-model="categoryFilter" @change="load()"
                    class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500">
                <option value="">All Categories</option>
                <template x-for="cat in (stats.categories || [])" :key="cat">
                    <option :value="cat" x-text="cat"></option>
                </template>
            </select>
            <button @click="load()" class="px-3 py-2 border border-slate-700 text-slate-400 hover:text-white hover:border-slate-500 rounded-lg text-sm transition">
                Refresh
            </button>
            <button @click="addPanel = true"
                    class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white text-sm font-medium rounded-lg transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Knowledge
            </button>
        </div>
    </div>

    {{-- ── Knowledge Table ──────────────────────────────────────────── --}}
    <div class="stat-card">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-slate-500 uppercase border-b border-slate-800">
                        <th class="pb-2 text-left pr-4">Title</th>
                        <th class="pb-2 text-left pr-4">Category</th>
                        <th class="pb-2 text-left pr-4">Tags</th>
                        <th class="pb-2 text-left pr-4">Chunks</th>
                        <th class="pb-2 text-left pr-4">Accesses</th>
                        <th class="pb-2 text-left pr-4">Created</th>
                        <th class="pb-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="entry in entries" :key="entry.id">
                        <tr class="border-b border-slate-800/40 hover:bg-slate-800/20 transition group">
                            <td class="py-2.5 pr-4">
                                <p class="text-slate-200 font-medium truncate max-w-xs" x-text="entry.title"></p>
                                <p class="text-xs text-slate-600 font-mono" x-text="entry.id.substring(0,8) + '...'"></p>
                            </td>
                            <td class="py-2.5 pr-4">
                                <span class="badge text-xs bg-slate-700/60 text-slate-300 border border-slate-700" x-text="entry.category || 'general'"></span>
                            </td>
                            <td class="py-2.5 pr-4">
                                <div class="flex flex-wrap gap-1 max-w-[160px]">
                                    <template x-if="!entry.tags || entry.tags.length === 0">
                                        <span class="text-xs text-slate-600">—</span>
                                    </template>
                                    <template x-for="tag in (entry.tags || []).slice(0,3)" :key="tag">
                                        <span class="badge text-xs bg-brand-500/10 text-brand-400 border border-brand-500/20" x-text="tag"></span>
                                    </template>
                                    <span x-show="(entry.tags || []).length > 3"
                                          class="text-xs text-slate-500" x-text="'+' + ((entry.tags || []).length - 3) + ' more'"></span>
                                </div>
                            </td>
                            <td class="py-2.5 pr-4 text-slate-400 text-xs" x-text="entry.chunk_index === 0 ? '1+' : entry.chunk_index + 1"></td>
                            <td class="py-2.5 pr-4 text-slate-400 text-xs" x-text="entry.access_count ?? 0"></td>
                            <td class="py-2.5 pr-4 text-slate-500 text-xs" x-text="relativeTime(entry.created_at)"></td>
                            <td class="py-2.5">
                                <button @click="deleteEntry(entry.id)"
                                        class="opacity-0 group-hover:opacity-100 p-1.5 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="entries.length === 0 && !loading">
                        <td colspan="7" class="py-12 text-center">
                            <div class="flex flex-col items-center gap-2">
                                <svg class="w-10 h-10 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                                <p class="text-slate-500 text-sm">No knowledge entries yet.</p>
                                <p class="text-slate-600 text-xs">Add documents, guidelines, or data to help agents make better decisions.</p>
                                <button @click="addPanel = true" class="mt-2 px-4 py-2 bg-brand-600/80 hover:bg-brand-600 text-white text-sm rounded-lg transition">
                                    Add First Entry
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr x-show="loading">
                        <td colspan="7" class="py-8 text-center text-slate-500 text-sm">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="flex items-center justify-between mt-4 pt-4 border-t border-slate-800" x-show="totalPages > 1">
            <p class="text-xs text-slate-500" x-text="'Page ' + currentPage + ' of ' + totalPages + ' (' + totalEntries + ' entries)'"></p>
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
function knowledgeApp() {
    return {
        entries: [],
        stats: {},
        loading: false,
        search: '',
        categoryFilter: '',
        currentPage: 1,
        totalPages: 1,
        totalEntries: 0,
        addPanel: false,
        adding: false,
        importing: false,
        importMode: 'manual',
        githubImport: { repoUrl: '', category: 'general', branch: 'main', token: '' },
        githubResult: null,
        importProgress: null,
        importPollTimer: null,
        toast: { show: false, message: '', error: false },
        newEntry: { title: '', content: '', category: 'general', tagsRaw: '' },

        async init() {
            await this.load();
        },

        async load() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    page: this.currentPage,
                });
                if (this.search) params.set('search', this.search);
                if (this.categoryFilter) params.set('category', this.categoryFilter);

                const r = await fetch('/dashboard/api/knowledge?' + params.toString());
                const d = await r.json();

                this.entries     = d.data || [];
                this.totalEntries = d.total || 0;
                this.totalPages  = d.last_page || 1;
                this.currentPage = d.current_page || 1;
                this.stats       = d.stats || {};

                updateTimestamp();
            } catch(e) {
                console.error('Knowledge load error:', e);
            }
            this.loading = false;
        },

        async createEntry() {
            if (!this.newEntry.title.trim() || !this.newEntry.content.trim()) {
                this.showToast('Title and content are required.', true);
                return;
            }
            this.adding = true;
            try {
                const tags = this.newEntry.tagsRaw
                    ? this.newEntry.tagsRaw.split(',').map(t => t.trim()).filter(Boolean)
                    : [];

                const r = await apiPost('/dashboard/api/knowledge', {
                    title:    this.newEntry.title,
                    content:  this.newEntry.content,
                    category: this.newEntry.category,
                    tags,
                });

                if (r.id) {
                    this.newEntry = { title: '', content: '', category: 'general', tagsRaw: '' };
                    this.addPanel = false;
                    this.showToast('Knowledge stored and embedded successfully.');
                    await this.load();
                } else {
                    this.showToast(r.error || 'Failed to store knowledge.', true);
                }
            } catch(e) {
                this.showToast('Error: ' + e.message, true);
            }
            this.adding = false;
        },

        async deleteEntry(id) {
            if (!confirm('Delete this knowledge entry and all its chunks?')) return;
            try {
                const r = await fetch('/dashboard/api/knowledge/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                });
                const d = await r.json();
                if (d.deleted) {
                    this.showToast('Entry deleted.');
                    await this.load();
                }
            } catch(e) {
                this.showToast('Delete failed: ' + e.message, true);
            }
        },

        changePage(page) {
            if (page < 1 || page > this.totalPages) return;
            this.currentPage = page;
            this.load();
        },

        showToast(message, error = false) {
            this.toast = { show: true, message, error };
            setTimeout(() => this.toast.show = false, 3500);
        },

        async importGitHub() {
            if (!this.githubImport.repoUrl.trim()) {
                this.showToast('Repository URL is required.', true);
                return;
            }
            this.importing = true;
            this.githubResult = null;
            this.importProgress = null;
            if (this.importPollTimer) { clearInterval(this.importPollTimer); this.importPollTimer = null; }

            const repoUrl = this.githubImport.repoUrl.trim();
            try {
                const r = await apiPost('/dashboard/api/knowledge/github', {
                    repo_url: repoUrl,
                    category: this.githubImport.category,
                    branch:   this.githubImport.branch || 'main',
                    token:    this.githubImport.token || null,
                });

                if (r.dispatched ?? r.queued) {
                    this.githubResult = { message: r.message || 'Import queued. Tracking progress…' };
                    this.githubImport = { repoUrl: '', category: 'general', branch: 'main', token: '' };
                    this.showToast('GitHub import queued successfully.');
                    this.pollImportProgress(repoUrl);
                } else {
                    this.githubResult = { error: r.error || 'Failed to queue import.' };
                }
            } catch(e) {
                this.githubResult = { error: 'Error: ' + e.message };
            }
            this.importing = false;
        },

        pollImportProgress(repoUrl) {
            this.importProgress = { status: 'running', ingested: 0, total: null };
            this.importPollTimer = setInterval(async () => {
                try {
                    const params = new URLSearchParams({ repo_url: repoUrl });
                    const r = await fetch('/dashboard/api/knowledge/import-status?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!r.ok) return;
                    const d = await r.json();
                    if (d.status === 'not_found') return;
                    this.importProgress = d;
                    if (d.status === 'completed') {
                        clearInterval(this.importPollTimer);
                        this.importPollTimer = null;
                        this.showToast('Import complete! ' + (d.ingested ?? 0) + ' files ingested.');
                        this.load(); // Refresh the knowledge table
                    } else if (d.status === 'failed') {
                        clearInterval(this.importPollTimer);
                        this.importPollTimer = null;
                    }
                } catch (_) {}
            }, 3000);
        },

        relativeTime,
    };
}
</script>
@endsection
