@extends('layouts.app')
@section('title', 'Knowledge Base')
@section('subtitle', 'RAG vector store — browse, search, and manage agent memory')

@section('content')
<div x-data="knowledgeApp()" x-init="init()" x-cloak>

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
                        <div class="flex items-center gap-2">
                            <template x-if="importProgress?.status === 'running'">
                                <svg class="w-3.5 h-3.5 text-brand-400 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            </template>
                            <span class="text-slate-300 font-medium"
                                  x-text="importProgress?.status === 'completed' ? 'Import complete' : importProgress?.status === 'failed' ? 'Import failed' : 'Importing\u2026'"></span>
                        </div>
                        <span class="text-slate-400" x-text="(importProgress?.ingested ?? 0) + ' / ' + (importProgress?.total ?? '?') + ' files'"></span>
                    </div>
                    {{-- Animated progress bar --}}
                    <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700 ease-out"
                             :class="{
                                 'bg-red-500': importProgress?.status === 'failed',
                                 'bg-emerald-500': importProgress?.status === 'completed',
                                 'bg-brand-500': importProgress?.status === 'running'
                             }"
                             :style="'width:' + (importProgress?.status === 'running' && !importProgress?.total ? '8' : importProgress?.total ? Math.max(4, Math.round((importProgress.ingested / importProgress.total) * 100)) : 0) + '%'">
                            {{-- Shimmer stripe for running state --}}
                            <div x-show="importProgress?.status === 'running'"
                                 class="h-full w-full bg-gradient-to-r from-transparent via-white/20 to-transparent animate-pulse rounded-full"></div>
                        </div>
                    </div>
                    <p x-show="importProgress?.error" class="text-xs text-red-400" x-text="importProgress?.error"></p>
                    {{-- Failed files expandable panel --}}
                    <template x-if="importProgress?.failed_files?.length">
                        <div class="mt-1">
                            <button @click="showFailedFiles = !showFailedFiles"
                                    class="text-xs text-amber-400 hover:text-amber-300 transition-colors flex items-center gap-1">
                                <span>⚠</span>
                                <span x-text="importProgress.failed_files.length + ' file(s) failed'"></span>
                                <span x-text="showFailedFiles ? '▲' : '▼'" class="text-slate-500"></span>
                            </button>
                            <ul x-show="showFailedFiles" class="mt-1.5 space-y-1 max-h-28 overflow-y-auto">
                                <template x-for="f in importProgress.failed_files.slice(0,5)" :key="f.path">
                                    <li class="text-xs text-slate-400 leading-snug">
                                        <span class="font-mono text-slate-300" x-text="f.path"></span>
                                        <span class="text-red-400 ml-1" x-text="'— ' + f.error"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
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
                <input type="text" x-model="search" @input.debounce.400ms="currentPage = 1; load()"
                       placeholder="Search by title or content..."
                       class="w-full bg-slate-900 border border-slate-700 rounded-lg pl-9 pr-4 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-brand-500 transition">
                {{-- Searching indicator --}}
                <div x-show="searching" class="absolute right-3 top-1/2 -translate-y-1/2">
                    <svg class="w-4 h-4 text-brand-400 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </div>
            </div>
            <select x-model="categoryFilter" @change="currentPage = 1; load()"
                    class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500">
                <option value="">All Categories</option>
                <template x-for="cat in (stats.categories || [])" :key="cat">
                    <option :value="cat" x-text="cat"></option>
                </template>
            </select>
            <button @click="load()" :class="loading ? 'opacity-50 cursor-not-allowed' : ''"
                    class="px-3 py-2 border border-slate-700 text-slate-400 hover:text-white hover:border-slate-500 rounded-lg text-sm transition flex items-center gap-1.5">
                <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
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
                    {{-- Skeleton loading rows --}}
                    <template x-if="loading && !entries.length">
                        <template x-for="i in [1,2,3,4,5,6]" :key="i">
                            <tr class="border-b border-slate-800/40">
                                <td class="py-3 pr-4">
                                    <div class="skeleton h-4 w-44 mb-1.5"></div>
                                    <div class="skeleton h-3 w-20"></div>
                                </td>
                                <td class="py-3 pr-4"><div class="skeleton h-5 w-20 rounded-full"></div></td>
                                <td class="py-3 pr-4"><div class="skeleton h-3 w-24"></div></td>
                                <td class="py-3 pr-4"><div class="skeleton h-3 w-8"></div></td>
                                <td class="py-3 pr-4"><div class="skeleton h-3 w-8"></div></td>
                                <td class="py-3 pr-4"><div class="skeleton h-3 w-20"></div></td>
                                <td class="py-3"><div class="skeleton h-6 w-6 rounded"></div></td>
                            </tr>
                        </template>
                    </template>

                    {{-- Data rows --}}
                    <template x-for="entry in entries" :key="entry.id">
                        <tbody>
                            {{-- Main row --}}
                            <tr class="border-b border-slate-800/40 hover:bg-slate-800/20 transition group cursor-pointer"
                                @click="toggleExpand(entry.id)">
                                <td class="py-2.5 pr-4">
                                    <div class="flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5 text-slate-600 transition-transform flex-shrink-0"
                                             :class="expandedId === entry.id ? 'rotate-90' : ''"
                                             fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                        <p class="text-slate-200 font-medium truncate max-w-xs" x-text="entry.title"></p>
                                    </div>
                                    <p class="text-xs text-slate-600 font-mono ml-5" x-text="entry.id.substring(0,8) + '...'"></p>
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
                                    <button @click.stop="deleteEntry(entry.id)"
                                            class="opacity-0 group-hover:opacity-100 p-1.5 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </td>
                            </tr>

                            {{-- Expandable detail row --}}
                            <tr x-show="expandedId === entry.id" class="border-b border-slate-800/40 bg-slate-900/60">
                                <td colspan="7" class="px-6 py-4">
                                    <div class="grid grid-cols-3 gap-4 text-xs">
                                        {{-- Embedding status --}}
                                        <div class="bg-slate-800/60 rounded-lg p-3">
                                            <p class="text-slate-500 mb-1.5 uppercase tracking-wide">Embedding Status</p>
                                            <div class="flex items-center gap-2">
                                                <span class="w-2 h-2 rounded-full flex-shrink-0"
                                                      :class="entry.embedding ? 'bg-emerald-400' : 'bg-amber-400 animate-pulse'"></span>
                                                <span class="text-slate-300" x-text="entry.embedding ? 'Embedded' : 'Pending embedding'"></span>
                                            </div>
                                        </div>
                                        {{-- Chunk count --}}
                                        <div class="bg-slate-800/60 rounded-lg p-3">
                                            <p class="text-slate-500 mb-1.5 uppercase tracking-wide">Vector Chunks</p>
                                            <p class="text-slate-200 font-semibold text-base" x-text="entry.chunk_index === 0 ? '1' : entry.chunk_index + 1"></p>
                                            <p class="text-slate-600 mt-0.5">segments in pgvector</p>
                                        </div>
                                        {{-- Created date --}}
                                        <div class="bg-slate-800/60 rounded-lg p-3">
                                            <p class="text-slate-500 mb-1.5 uppercase tracking-wide">Created</p>
                                            <p class="text-slate-300" x-text="entry.created_at ? new Date(entry.created_at).toLocaleString() : '–'"></p>
                                            <p class="text-slate-600 mt-0.5" x-text="relativeTime(entry.created_at)"></p>
                                        </div>
                                    </div>
                                    {{-- Access count bar --}}
                                    <div class="mt-3" x-show="(entry.access_count ?? 0) > 0">
                                        <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                                            <span>Agent retrieval count</span>
                                            <span x-text="(entry.access_count ?? 0) + ' accesses'"></span>
                                        </div>
                                        <div class="w-full h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                            <div class="h-full bg-brand-500/70 rounded-full transition-all duration-500"
                                                 :style="'width:' + Math.min(Math.round(((entry.access_count ?? 0) / 50) * 100), 100) + '%'"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </template>

                    {{-- Empty state --}}
                    <tr x-show="entries.length === 0 && !loading">
                        <td colspan="7" class="py-16 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <div class="w-16 h-16 rounded-2xl bg-slate-800/60 border border-slate-700/50 flex items-center justify-center">
                                    <svg class="w-8 h-8 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                                </div>
                                <div>
                                    <p class="text-slate-400 font-medium mb-1"
                                       x-text="search || categoryFilter ? 'No entries match your filters.' : 'No knowledge entries yet.'"></p>
                                    <p class="text-slate-600 text-xs"
                                       x-text="search || categoryFilter ? 'Try clearing your search or category filter.' : 'Add documents, guidelines, or data to help agents make better decisions.'"></p>
                                </div>
                                <button x-show="!search && !categoryFilter"
                                        @click="addPanel = true"
                                        class="mt-1 px-4 py-2 bg-brand-600/80 hover:bg-brand-600 text-white text-sm rounded-lg transition flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    Add First Entry
                                </button>
                            </div>
                        </td>
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
        searching: false,
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
        showFailedFiles: false,
        expandedId: null,
        _refreshTimer: null,
        newEntry: { title: '', content: '', category: 'general', tagsRaw: '' },

        async init() {
            const saved = JSON.parse(localStorage.getItem('filters_knowledge') ?? '{}');
            this.categoryFilter = saved.categoryFilter ?? '';
            this.search         = saved.search ?? '';
            await this.load();
            // Post-load: reset categoryFilter if no longer an available category
            if (this.categoryFilter && !(this.stats.categories ?? []).includes(this.categoryFilter)) {
                this.categoryFilter = '';
            }
            // Auto-refresh every 60 seconds (knowledge store changes infrequently)
            this._refreshTimer = setInterval(() => this.load(), 60000);
        },

        async load() {
            localStorage.setItem('filters_knowledge', JSON.stringify({
                categoryFilter: this.categoryFilter,
                search:         this.search,
            }));
            this.loading = true;
            if (this.search) this.searching = true;
            try {
                const params = new URLSearchParams({
                    page: this.currentPage,
                });
                if (this.search) params.set('search', this.search);
                if (this.categoryFilter) params.set('category', this.categoryFilter);

                const r = await fetch('/dashboard/api/knowledge?' + params.toString());
                const d = await r.json();

                this.entries      = d.data || [];
                this.totalEntries = d.total || 0;
                this.totalPages   = d.last_page || 1;
                this.currentPage  = d.current_page || 1;
                this.stats        = d.stats || {};

                updateTimestamp();
            } catch(e) {
                showToast('Failed to load knowledge entries.', 'error');
                console.error('Knowledge load error:', e);
            } finally {
                this.loading   = false;
                this.searching = false;
            }
        },

        toggleExpand(id) {
            this.expandedId = this.expandedId === id ? null : id;
        },

        async createEntry() {
            if (!this.newEntry.title.trim() || !this.newEntry.content.trim()) {
                showToast('Title and content are required.', 'error');
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
                    showToast('Knowledge stored and embedded successfully.');
                    await this.load();
                } else {
                    showToast(r.error || 'Failed to store knowledge.', 'error');
                }
            } catch(e) {
                showToast('Error: ' + e.message, 'error');
            } finally {
                this.adding = false;
            }
        },

        async deleteEntry(id) {
            const confirmed = await confirmAction(
                'Delete knowledge entry',
                'This will permanently delete the entry and all its vector chunks. This cannot be undone.',
                'Delete',
                'bg-red-600 hover:bg-red-500'
            );
            if (!confirmed) return;
            try {
                const r = await fetch('/dashboard/api/knowledge/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                });
                const d = await r.json();
                if (d.deleted) {
                    showToast('Entry deleted.');
                    if (this.expandedId === id) this.expandedId = null;
                    await this.load();
                } else {
                    showToast(d.error || 'Delete failed.', 'error');
                }
            } catch(e) {
                showToast('Delete failed: ' + e.message, 'error');
            }
        },

        changePage(page) {
            if (page < 1 || page > this.totalPages) return;
            this.currentPage = page;
            this.load();
        },

        async importGitHub() {
            // Normalize before dispatch — must match PHP normalizeRepoUrl() in IngestGitHubRepo
            const repoUrl = this.githubImport.repoUrl.trim()
                .toLowerCase()
                .replace(/\.git$/, '')
                .replace(/\/$/, '');
            if (!repoUrl) {
                showToast('Repository URL is required.', 'error');
                return;
            }
            this.importing = true;
            this.githubResult = null;
            this.importProgress = null;
            this.showFailedFiles = false;
            if (this.importPollTimer) { clearInterval(this.importPollTimer); this.importPollTimer = null; }

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
                    showToast('GitHub import queued successfully.');
                    this.pollImportProgress(repoUrl);
                } else {
                    this.githubResult = { error: r.error || 'Failed to queue import.' };
                    showToast(r.error || 'Failed to queue import.', 'error');
                }
            } catch(e) {
                this.githubResult = { error: 'Error: ' + e.message };
                showToast('Import error: ' + e.message, 'error');
            } finally {
                this.importing = false;
            }
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
                        showToast('Import complete! ' + (d.ingested ?? 0) + ' files ingested.');
                        this.load(); // Refresh the knowledge table
                    } else if (d.status === 'failed') {
                        clearInterval(this.importPollTimer);
                        this.importPollTimer = null;
                        showToast('Import failed. Check the error details above.', 'error');
                    }
                } catch (_) {}
            }, 3000);
        },

        relativeTime,
    };
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
