@extends('layouts.app')
@section('title', 'Social Media')
@section('subtitle', 'Content calendar, accounts, hashtag library, and trend insights')

@section('content')
<div class="space-y-6">

    {{-- OAuth flash messages --}}
    @if(session('success') || session('error') || session('info'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         class="rounded-xl border px-4 py-3 flex items-start gap-3 text-sm
                @if(session('success')) bg-emerald-500/10 border-emerald-500/20 text-emerald-300
                @elseif(session('error'))   bg-red-500/10   border-red-500/20   text-red-300
                @else                       bg-sky-500/10   border-sky-500/20   text-sky-300
                @endif">
        <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            @if(session('success'))
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            @elseif(session('error'))
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            @else
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            @endif
        </svg>
        <span>{{ session('success') ?? session('error') ?? session('info') }}</span>
        <button @click="show = false" class="ml-auto opacity-60 hover:opacity-100 transition-opacity">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    @endif

    {{-- Tab navigation --}}
    <div x-data="{ activeTab: 'calendar' }" class="space-y-6">
        <div class="flex gap-2 border-b border-slate-700/60 pb-0 overflow-x-auto">
            @foreach([['calendar','Calendar'],['accounts','Accounts'],['hashtags','Hashtags'],['trends','Trends'],['settings','Settings']] as [$tab,$label])
            <button @click="activeTab = '{{ $tab }}'"
                :class="activeTab === '{{ $tab }}' ? 'border-b-2 border-violet-500 text-white' : 'text-slate-400 hover:text-slate-200'"
                class="px-4 py-2 text-sm font-medium transition-colors -mb-px whitespace-nowrap">{{ $label }}</button>
            @endforeach
                {{-- ── Settings Tab ─────────────────────────────────────────── --}}
                <div x-show="activeTab === 'settings'" x-cloak>
                    <div x-data="credentialsComponent()" x-init="init()" class="space-y-6">
                        <h2 class="text-lg font-semibold text-white mb-4">Social Platform Credentials</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <template x-for="cred in credentials" :key="cred.platform">
                                <div class="bg-slate-800 rounded-xl border border-slate-700 p-5 flex flex-col gap-3">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span x-html="platformIcon(cred.platform)"></span>
                                        <span class="font-semibold text-white text-base capitalize" x-text="cred.platform"></span>
                                        <a :href="cred.setup_guide" target="_blank" class="ml-auto text-xs text-violet-400 hover:underline">Setup guide</a>
                                    </div>
                                    <div class="mb-2">
                                        <label class="text-xs text-slate-400 mb-1 block">Callback URL</label>
                                        <div class="flex items-center gap-2">
                                            <input type="text" :value="cred.callback_url" readonly class="form-input w-full bg-slate-900 text-slate-400">
                                            <button @click="copyToClipboard(cred.callback_url)" class="btn-secondary px-2 py-1 text-xs">Copy</button>
                                        </div>
                                        <div class="bg-amber-500/20 text-amber-400 text-xs rounded px-2 py-1 mt-1">This exact URL must be added to your app's Authorized Redirect URIs</div>
                                    </div>
                                    <template x-for="field in platformFields[cred.platform] || defaultFields" :key="field.key">
                                        <div class="mt-2">
                                            <label class="text-xs text-slate-400 mb-1 block" x-text="field.label + (field.required ? '' : ' (optional)')"></label>
                                            <input
                                                :type="field.type || 'text'"
                                                :value="cred.fields[field.key] || ''"
                                                @input="cred.fields[field.key] = $event.target.value"
                                                class="form-input w-full"
                                                :placeholder="field.label">
                                        </div>
                                    </template>
                                    <div class="flex items-center gap-2 mt-3">
                                        <button @click="saveAndVerify(cred)" :disabled="cred.saving" class="btn-primary px-3 py-1.5 text-xs disabled:opacity-50">
                                            <span x-show="!cred.saving">Save & Verify</span>
                                            <span x-show="cred.saving">Verifying…</span>
                                        </button>
                                        <span x-show="cred.status === 'verified'" class="flex items-center gap-1 bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded text-xs">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            Verified<span x-show="cred.platform_user" x-text="' — ' + cred.platform_user"></span>
                                        </span>
                                        <span x-show="cred.status === 'saved'" class="bg-amber-500/20 text-amber-400 px-2 py-0.5 rounded text-xs">Saved — not verified</span>
                                        <span x-show="cred.status === 'not_configured'" class="bg-slate-700 text-slate-400 px-2 py-0.5 rounded text-xs">Not configured</span>
                                        <span x-show="cred.status === 'needs_attention'" class="flex items-center gap-1 bg-red-500/20 text-red-400 px-2 py-0.5 rounded text-xs">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                            Verification failed
                                        </span>
                                    </div>
                                    <div x-show="cred.last_test_error" class="text-xs text-red-400 mt-1">Error: <span x-text="cred.last_test_error"></span></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
        <script>
        function credentialsComponent() {
            return {
                credentials: [],
                platformFields: {
                    twitter:   [
                        {key:'client_id',     label:'Client ID',              type:'text',     required:true},
                        {key:'client_secret', label:'Client Secret',          type:'password', required:true},
                        {key:'bearer_token',  label:'Bearer Token',           type:'password', required:false},
                    ],
                    tiktok:    [
                        {key:'client_id',     label:'Client Key',             type:'text',     required:true},
                        {key:'client_secret', label:'Client Secret',          type:'password', required:true},
                    ],
                    instagram: [{key:'client_id', label:'App ID',         type:'text',     required:true}, {key:'client_secret', label:'App Secret',     type:'password', required:true}],
                    linkedin:  [{key:'client_id', label:'Client ID',      type:'text',     required:true}, {key:'client_secret', label:'Client Secret',  type:'password', required:true}],
                    facebook:  [{key:'client_id', label:'App ID',         type:'text',     required:true}, {key:'client_secret', label:'App Secret',     type:'password', required:true}],
                    youtube:   [{key:'client_id', label:'OAuth Client ID',type:'text',     required:true}, {key:'client_secret', label:'Client Secret',  type:'password', required:true}],
                },
                defaultFields: [
                    {key:'client_id',     label:'Client ID',     type:'text',     required:true},
                    {key:'client_secret', label:'Client Secret', type:'password', required:true},
                ],
                async init() {
                    const res = await fetch('/dashboard/api/social-credentials');
                    const data = await res.json();
                    this.credentials = data.map(c => ({ ...c, fields: {}, saving: false, platform_user: null }));
                },
                async saveAndVerify(cred) {
                    const fields = this.platformFields[cred.platform] || this.defaultFields;
                    const requiredMissing = fields.filter(f => f.required && !cred.fields[f.key]);
                    if (requiredMissing.length) {
                        cred.last_test_error = 'Please fill in: ' + requiredMissing.map(f => f.label).join(', ');
                        cred.status = 'needs_attention';
                        return;
                    }
                    cred.saving = true;
                    cred.last_test_error = null;
                    try {
                        const payload = {
                            platform:      cred.platform,
                            client_id:     cred.fields.client_id || '',
                            client_secret: cred.fields.client_secret || '',
                        };
                        if (cred.fields.bearer_token) payload.bearer_token = cred.fields.bearer_token;

                        const res = await fetch('/dashboard/api/social-credentials', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                            body: JSON.stringify(payload)
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            cred.last_test_error = data.error || 'Verification failed';
                            cred.status = 'needs_attention';
                        } else {
                            cred.status = data.status;
                            cred.platform_user = data.platform_user || null;
                            cred.validated_at = data.tested_at;
                            cred.last_test_error = null;
                        }
                    } catch (e) {
                        cred.last_test_error = e.message;
                        cred.status = 'needs_attention';
                    } finally {
                        cred.saving = false;
                    }
                },
                copyToClipboard(text) {
                    navigator.clipboard.writeText(text);
                },
                platformIcon(platform) {
                    const icons = {
                        twitter:   `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.746l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>`,
                        instagram: `<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4.5"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>`,
                        facebook:  `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.235 2.686.235v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.263h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>`,
                        linkedin:  `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>`,
                        tiktok:    `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.69a8.18 8.18 0 004.77 1.52V6.76a4.85 4.85 0 01-1-.07z"/></svg>`,
                        youtube:   `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>`,
                    };
                    return icons[platform] ?? `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg>`;
                }
            }
        }
        </script>
        </div>

        {{-- ── Calendar Tab ─────────────────────────────────────────── --}}
        <div x-show="activeTab === 'calendar'" x-cloak>
            <div x-data="calendarComponent()" x-init="init()" class="space-y-4">

                {{-- Header --}}
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <button @click="prevWeek()" class="p-2 rounded-lg hover:bg-slate-800 text-slate-400 hover:text-white transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <span class="text-sm font-medium text-slate-200" x-text="weekLabel"></span>
                        <button @click="nextWeek()" class="p-2 rounded-lg hover:bg-slate-800 text-slate-400 hover:text-white transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                    <div class="flex gap-2 flex-wrap">
                        <select x-model="platformFilter" @change="load()" class="text-sm bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-slate-300">
                            <option value="">All platforms</option>
                            <option value="tiktok">TikTok</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="twitter">Twitter/X</option>
                            <option value="linkedin">LinkedIn</option>
                            <option value="youtube">YouTube</option>
                        </select>
                        <button @click="openCreate()" class="btn-primary text-sm px-4 py-1.5">+ New Entry</button>
                    </div>
                </div>

                {{-- Skeleton calendar --}}
                <template x-if="calendarLoading && entries.length === 0">
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2 animate-pulse">
                        <template x-for="i in [1,2,3,4,5,6,7]" :key="i">
                            <div class="min-h-32 bg-slate-900 rounded-xl border border-slate-700/50 p-2">
                                <div class="h-3 bg-slate-700 rounded w-12 mb-3"></div>
                                <div class="h-6 bg-slate-700/60 rounded mb-1.5"></div>
                                <div class="h-6 bg-slate-700/40 rounded mb-1.5"></div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Week grid --}}
                <template x-if="!calendarLoading || entries.length > 0">
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2">
                        <template x-for="day in weekDays" :key="day.date">
                            <div class="min-h-32 bg-slate-900 rounded-xl border border-slate-700/50 p-2"
                                 :class="day.date === todayStr ? 'border-violet-500/40 ring-1 ring-violet-500/20' : ''">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-medium"
                                          :class="day.date === todayStr ? 'text-violet-400' : 'text-slate-500'"
                                          x-text="day.label"></span>
                                    {{-- Entry count badge --}}
                                    <span x-show="entriesForDay(day.date).length > 0"
                                          class="text-xs bg-slate-700 text-slate-400 rounded-full px-1.5 py-0 leading-5"
                                          x-text="entriesForDay(day.date).length"></span>
                                </div>
                                <template x-for="entry in entriesForDay(day.date)" :key="entry.id">
                                    <div class="relative group"
                                         @mouseenter="hoveredEntry = entry.id"
                                         @mouseleave="hoveredEntry = null">
                                        <div @click="openEdit(entry)"
                                             :class="platformColor(entry.platform)"
                                             class="mb-1.5 px-2 py-1 rounded-lg text-xs cursor-pointer truncate flex items-center gap-1.5">
                                            <span x-text="platformIcon(entry.platform)"></span>
                                            <span class="truncate" x-text="entry.title"></span>
                                            <span x-show="entry.moderation_status === 'pending'" class="ml-auto text-amber-400 flex-shrink-0">⚠</span>
                                            <span x-show="entry.status === 'published'" class="ml-auto text-emerald-400 flex-shrink-0">✓</span>
                                        </div>
                                        {{-- Hover preview card --}}
                                        <div x-show="hoveredEntry === entry.id"
                                             class="absolute left-0 top-full mt-1 z-40 w-56 bg-slate-800 border border-slate-600 rounded-xl shadow-2xl p-3 text-xs pointer-events-none"
                                             style="min-width: 220px">
                                            <p class="font-semibold text-white mb-1 leading-snug" x-text="entry.title"></p>
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <span class="text-xs px-1.5 py-0.5 rounded"
                                                      :class="platformColor(entry.platform)"
                                                      x-text="platformIcon(entry.platform) + ' ' + entry.platform"></span>
                                                <span class="text-slate-400 capitalize" x-text="entry.content_type ?? ''"></span>
                                            </div>
                                            <div class="flex items-center gap-1.5 mb-1">
                                                <span :class="{
                                                    'text-emerald-400': entry.status === 'published',
                                                    'text-amber-400': entry.moderation_status === 'pending',
                                                    'text-slate-400': entry.status === 'draft'
                                                }" x-text="entry.moderation_status === 'pending' ? 'Awaiting approval' : entry.status"></span>
                                            </div>
                                            <p class="text-slate-500 mt-1" x-text="entry.scheduled_at ? 'Scheduled: ' + entry.scheduled_at.replace('T',' ').substring(0,16) : 'No time set'"></p>
                                        </div>
                                    </div>
                                </template>
                                <button @click="openCreateForDay(day.date)" class="w-full text-xs text-slate-600 hover:text-slate-400 py-1 transition-colors">+ add</button>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Platform colour legend --}}
                <div class="flex flex-wrap items-center gap-3 pt-1">
                    <span class="text-xs text-slate-600 font-medium uppercase tracking-wide">Platforms:</span>
                    <template x-for="[plat, cls] in Object.entries(platformColorMap)" :key="plat">
                        <span class="flex items-center gap-1.5 text-xs">
                            <span class="inline-block w-2.5 h-2.5 rounded-full" :class="cls.split(' ')[0]"></span>
                            <span class="text-slate-400 capitalize" x-text="plat"></span>
                        </span>
                    </template>
                </div>

                {{-- Pending approval banner --}}
                <div x-show="pendingCount > 0" class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300 flex items-center justify-between">
                    <span x-text="`${pendingCount} entries awaiting moderation approval`"></span>
                    <button @click="platformFilter=''; statusFilter='pending_approval'; load()" class="text-amber-400 underline text-xs">Review</button>
                </div>

                {{-- Create/Edit slide-over --}}
                <div x-show="showModal" x-cloak @keydown.escape.window="showModal = false"
                     class="fixed inset-0 z-50 flex items-start justify-end">
                    <div @click="showModal = false" class="fixed inset-0 bg-black/60"></div>
                    <div class="relative z-10 w-full max-w-md h-full bg-slate-900 border-l border-slate-700 p-6 overflow-y-auto">
                        <h3 class="text-lg font-semibold text-white mb-4" x-text="editEntry.id ? 'Edit Entry' : 'New Calendar Entry'"></h3>

                        <div class="space-y-4">
                            <div>
                                <label class="text-xs text-slate-400 mb-1 block">Title</label>
                                <input x-model="editEntry.title" type="text" class="form-input w-full" placeholder="Post title">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-slate-400 mb-1 block">Platform</label>
                                    <select x-model="editEntry.platform" class="form-input w-full">
                                        <option value="tiktok">TikTok</option>
                                        <option value="instagram">Instagram</option>
                                        <option value="facebook">Facebook</option>
                                        <option value="twitter">Twitter/X</option>
                                        <option value="linkedin">LinkedIn</option>
                                        <option value="youtube">YouTube</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs text-slate-400 mb-1 block">Content Type</label>
                                    <select x-model="editEntry.content_type" class="form-input w-full">
                                        <option value="post">Post</option>
                                        <option value="reel">Reel</option>
                                        <option value="story">Story</option>
                                        <option value="carousel">Carousel</option>
                                        <option value="thread">Thread</option>
                                        <option value="video">Video</option>
                                        <option value="short">Short</option>
                                        <option value="live">Live</option>
                                        <option value="ad">Ad</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-slate-400 mb-1 block">Scheduled At</label>
                                <input x-model="editEntry.scheduled_at" type="datetime-local" class="form-input w-full">
                            </div>
                            <div>
                                <label class="text-xs text-slate-400 mb-1 block">Draft Content</label>
                                <textarea x-model="editEntry.draft_content" rows="6" class="form-input w-full resize-none" placeholder="Your post content..."></textarea>
                            </div>
                            <div x-show="editEntry.moderation_status === 'pending'" class="rounded-lg bg-amber-500/10 border border-amber-500/30 px-3 py-2 text-sm text-amber-300">
                                Awaiting moderation approval
                            </div>
                            <div class="flex gap-2 flex-wrap">
                                <button @click="saveEntry()" :disabled="saving" class="btn-primary flex-1 text-sm py-2 flex items-center justify-center gap-1.5">
                                    <span x-show="saving" class="inline-block animate-spin text-xs">↻</span>
                                    <span x-text="saving ? 'Saving…' : 'Save'"></span>
                                </button>
                                <template x-if="editEntry.id && editEntry.status !== 'published'">
                                    <button @click="publishEntry(editEntry.id)" :disabled="publishing"
                                            class="bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg px-4 text-sm py-2 transition-colors flex items-center gap-1.5">
                                        <span x-show="publishing" class="inline-block animate-spin text-xs">↻</span>
                                        <span x-text="publishing ? 'Publishing…' : 'Publish'"></span>
                                    </button>
                                </template>
                                <template x-if="editEntry.id && editEntry.moderation_status === 'pending'">
                                    <button @click="approveEntry(editEntry.id)" class="bg-violet-600 hover:bg-violet-500 text-white rounded-lg px-4 text-sm py-2 transition-colors">Approve</button>
                                </template>
                                <template x-if="editEntry.id && editEntry.moderation_status === 'pending'">
                                    <button @click="rejectEntry(editEntry.id)" class="bg-amber-600/20 hover:bg-amber-600/30 text-amber-400 border border-amber-600/30 rounded-lg px-3 text-sm py-2 transition-colors">Reject</button>
                                </template>
                                <template x-if="editEntry.id && editEntry.status !== 'published'">
                                    <button @click="deleteEntry(editEntry.id)" class="bg-red-500/10 hover:bg-red-500/20 text-red-400 rounded-lg px-3 text-sm py-2 transition-colors" title="Delete entry">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </template>
                            </div>
                            <div x-show="modalError" x-text="modalError" class="text-red-400 text-sm"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Accounts Tab ─────────────────────────────────────────── --}}
        <div x-show="activeTab === 'accounts'" x-cloak>
            <div x-data="accountsComponent()" x-init="init()" class="space-y-4">

                {{-- Info banner: all platforms use real OAuth --}}
                <div class="flex items-start gap-3 rounded-xl border border-sky-500/20 bg-sky-500/8 px-4 py-3 text-xs text-sky-300">
                    <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>All platforms use real OAuth 2.0. Click <strong>Connect</strong> to authorise via the official platform login. Configure credentials in the <strong>Settings</strong> tab first, then connect accounts.</span>
                </div>

                {{-- Skeleton accounts --}}
                <template x-if="accountsLoading">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 animate-pulse">
                        <template x-for="i in [1,2,3,4,5,6]" :key="i">
                            <div class="stat-card">
                                <div class="h-4 bg-slate-700 rounded w-24 mb-3"></div>
                                <div class="grid grid-cols-2 gap-2 mb-3">
                                    <div class="bg-slate-800 rounded-lg h-16"></div>
                                    <div class="bg-slate-800 rounded-lg h-16"></div>
                                </div>
                                <div class="h-8 bg-slate-700/60 rounded-lg"></div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!accountsLoading">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach([
                            ['instagram', 'Instagram', '#E1306C'],
                            ['tiktok',    'TikTok',    '#EE1D52'],
                            ['facebook',  'Facebook',  '#1877F2'],
                            ['twitter',   'Twitter/X', '#1DA1F2'],
                            ['linkedin',  'LinkedIn',  '#0077B5'],
                            ['youtube',   'YouTube',   '#FF0000'],
                        ] as [$platform, $label, $color])
                        <div class="stat-card card-lift relative overflow-hidden"
                             style="border-top: 2px solid {{ $color }}40; box-shadow: 0 4px 24px rgba(0,0,0,0.4), 0 0 0 1px {{ $color }}15, inset 0 1px 0 rgba(255,255,255,0.04);">
                            {{-- Accent glow --}}
                            <div class="absolute top-0 left-0 right-0 h-px" style="background: {{ $color }}; opacity: 0.7;"></div>
                            <div class="absolute -top-8 left-1/2 -translate-x-1/2 w-32 h-16 rounded-full opacity-10 pointer-events-none"
                                 style="background: radial-gradient(circle, {{ $color }}, transparent);"></div>

                            <div class="flex items-start justify-between mb-3 mt-1">
                                <div>
                                    <p class="text-sm font-semibold text-white">{{ $label }}</p>
                                    <template x-if="accountFor('{{ $platform }}')">
                                        <p class="text-xs text-slate-400 mt-0.5" x-text="(accountFor('{{ $platform }}').display_name || ('@' + accountFor('{{ $platform }}').handle))"></p>
                                    </template>
                                    <template x-if="!accountFor('{{ $platform }}')">
                                        <p class="text-xs text-slate-600 mt-0.5">Not connected</p>
                                    </template>
                                </div>
                                <div class="flex items-center gap-2">
                                    {{-- Animated status dot --}}
                                    <template x-if="accountFor('{{ $platform }}') && accountFor('{{ $platform }}').is_connected">
                                        <span class="flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot"></span>
                                            <span class="text-xs bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full font-medium">Connected</span>
                                        </span>
                                    </template>
                                    <template x-if="!accountFor('{{ $platform }}') || !accountFor('{{ $platform }}').is_connected">
                                        <span class="flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full bg-slate-600"></span>
                                            <span class="text-xs bg-slate-700/60 text-slate-500 px-2 py-0.5 rounded-full">Disconnected</span>
                                        </span>
                                    </template>
                                </div>
                            </div>

                            {{-- Token expiry warning --}}
                            <template x-if="accountFor('{{ $platform }}') && accountFor('{{ $platform }}').is_connected && isTokenExpiringSoon(accountFor('{{ $platform }}'))">
                                <div class="mb-2 flex items-center gap-2 text-xs rounded-lg bg-amber-500/10 border border-amber-500/20 px-2 py-1.5 text-amber-300">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Token expires soon — reconnect to avoid interruptions
                                </div>
                            </template>

                            <template x-if="accountFor('{{ $platform }}') && accountFor('{{ $platform }}').is_connected">
                                <div class="grid grid-cols-2 gap-2 mb-3 text-center">
                                    <div class="bg-slate-900/50 rounded-lg py-2">
                                        <p class="text-lg font-bold text-white" x-text="(accountFor('{{ $platform }}').follower_count ?? 0).toLocaleString()"></p>
                                        <p class="text-xs text-slate-500">Followers</p>
                                    </div>
                                    <div class="bg-slate-900/50 rounded-lg py-2">
                                        <p class="text-lg font-bold text-white" x-text="((accountFor('{{ $platform }}').avg_engagement_rate ?? 0) * 100).toFixed(1) + '%'"></p>
                                        <p class="text-xs text-slate-500">Engagement</p>
                                    </div>
                                </div>
                            </template>

                            <template x-if="accountFor('{{ $platform }}') && accountFor('{{ $platform }}').last_error">
                                <p class="text-xs text-red-400 mb-2 bg-red-500/10 rounded px-2 py-1 truncate" x-text="accountFor('{{ $platform }}').last_error"></p>
                            </template>

                            @if($platform === 'linkedin')
                            {{-- LinkedIn: organisation selector (shown when ≥1 org in metadata) --}}
                            <template x-if="accountFor('linkedin') && accountFor('linkedin').is_connected && accountFor('linkedin').metadata && (accountFor('linkedin').metadata.organizations || []).length > 0">
                                <div class="mb-3">
                                    <label class="block text-xs text-slate-400 mb-1">Posting as organisation</label>
                                    <select class="form-input w-full text-xs"
                                            @change="updateOrgUrn(accountFor('linkedin').id, $event.target.value)">
                                        <template x-for="org in (accountFor('linkedin').metadata.organizations ?? [])" :key="org.urn">
                                            <option :value="org.urn"
                                                    :selected="org.urn === accountFor('linkedin').metadata.organization_urn"
                                                    x-text="org.name ?? org.urn"></option>
                                        </template>
                                    </select>
                                </div>
                            </template>
                            @endif

                            {{-- All platforms: real OAuth redirect --}}
                            <div class="flex gap-2 mt-auto">
                                <a href="/dashboard/social/auth/{{ $platform }}/redirect"
                                   class="flex-1 text-center text-xs py-1.5 rounded-lg font-medium transition-colors"
                                   :class="accountFor('{{ $platform }}')?.is_connected
                                       ? 'bg-slate-700 hover:bg-slate-600 text-slate-300'
                                       : 'bg-violet-600 hover:bg-violet-500 text-white'">
                                    <template x-if="accountFor('{{ $platform }}') && accountFor('{{ $platform }}').is_connected">
                                        <span>Reconnect</span>
                                    </template>
                                    <template x-if="!accountFor('{{ $platform }}') || !accountFor('{{ $platform }}').is_connected">
                                        <span>Connect via OAuth</span>
                                    </template>
                                </a>
                                <template x-if="accountFor('{{ $platform }}') && accountFor('{{ $platform }}').is_connected">
                                    <button @click="disconnectAccount('{{ $platform }}')"
                                            class="px-2 py-1.5 rounded-lg bg-red-500/10 text-red-400 hover:bg-red-500/20 text-xs transition-colors"
                                            title="Disconnect">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                    </button>
                                </template>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </template>
            </div>
        </div>

        {{-- ── Hashtags Tab ─────────────────────────────────────────── --}}
        <div x-show="activeTab === 'hashtags'" x-cloak>
            <div x-data="hashtagComponent()" x-init="init()" class="space-y-4">

                {{-- Create form --}}
                <div class="stat-card">
                    <h3 class="text-sm font-semibold text-white mb-3">New Hashtag Set</h3>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <input x-model="newSet.name" type="text" class="form-input" placeholder="Set name">
                        <select x-model="newSet.platform" class="form-input">
                            <option value="instagram">Instagram</option>
                            <option value="tiktok">TikTok</option>
                            <option value="facebook">Facebook</option>
                            <option value="twitter">Twitter/X</option>
                            <option value="linkedin">LinkedIn</option>
                            <option value="youtube">YouTube</option>
                        </select>
                        <input x-model="newSet.niche" type="text" class="form-input" placeholder="Niche (optional)">
                        <select x-model="newSet.reach_tier" class="form-input">
                            <option value="low">Low reach</option>
                            <option value="medium">Medium reach</option>
                            <option value="high">High reach</option>
                        </select>
                    </div>
                    <input x-model="newSet.tagsRaw" type="text" class="form-input w-full mb-3" placeholder="#tag1, #tag2, #tag3 (comma-separated)">
                    <button @click="createSet()" :disabled="saving"
                            class="btn-primary text-sm px-4 py-1.5 flex items-center gap-1.5">
                        <span x-show="saving" class="inline-block animate-spin text-xs">↻</span>
                        <span x-text="saving ? 'Saving…' : 'Add Set'"></span>
                    </button>
                    <div x-show="createError" x-text="createError" class="text-red-400 text-sm mt-2"></div>
                </div>

                {{-- Filter by platform --}}
                <div class="flex flex-wrap gap-2">
                    @foreach(['','instagram','tiktok','facebook','twitter','linkedin','youtube'] as $p)
                    <button @click="platformFilter = '{{ $p }}'; filterSets()"
                        :class="platformFilter === '{{ $p }}' ? 'bg-violet-600 text-white border-violet-500' : 'bg-slate-800 text-slate-400 hover:text-white border-slate-700'"
                        class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors border">{{ $p ?: 'All' }}</button>
                    @endforeach
                </div>

                {{-- Skeleton --}}
                <template x-if="loading">
                    <div class="space-y-3 animate-pulse">
                        <template x-for="i in [1,2,3]" :key="i">
                            <div class="stat-card">
                                <div class="h-4 bg-slate-700 rounded w-32 mb-2"></div>
                                <div class="h-3 bg-slate-700/60 rounded w-48 mb-3"></div>
                                <div class="flex gap-1.5">
                                    <div class="h-5 bg-slate-700 rounded-full w-16"></div>
                                    <div class="h-5 bg-slate-700 rounded-full w-20"></div>
                                    <div class="h-5 bg-slate-700 rounded-full w-14"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Hashtag sets list --}}
                <template x-if="!loading">
                    <div class="space-y-3">
                        <template x-for="set in filteredSets" :key="set.id">
                            <div class="stat-card">
                                <div class="flex items-start justify-between mb-2">
                                    <div>
                                        <p class="text-sm font-semibold text-white" x-text="set.name"></p>
                                        <p class="text-xs text-slate-500 mt-0.5">
                                            <span x-text="set.platform"></span>
                                            <template x-if="set.niche"> · <span x-text="set.niche"></span></template>
                                            · <span x-text="set.reach_tier + ' reach'"></span>
                                            · used <span class="text-slate-300 font-medium" x-text="set.usage_count ?? 0"></span>×
                                        </p>
                                    </div>
                                    <div class="flex gap-2 flex-shrink-0">
                                        <button @click="copyTags(set)"
                                                class="text-xs text-slate-400 hover:text-white transition-colors px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                            Copy all
                                        </button>
                                        <button @click="deleteSet(set.id)"
                                                class="text-xs text-red-400 hover:text-red-300 transition-colors px-2 py-1 bg-slate-800 hover:bg-red-500/10 rounded-lg">Delete</button>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="tag in set.tags" :key="tag">
                                        <span class="text-xs bg-slate-800 text-violet-400 px-2 py-0.5 rounded-full" x-text="tag"></span>
                                    </template>
                                </div>
                                <p x-show="set.tags && set.tags.length > 0"
                                   class="text-xs text-slate-600 mt-2"
                                   x-text="set.tags.length + ' tag' + (set.tags.length !== 1 ? 's' : '')"></p>
                            </div>
                        </template>
                        <div x-show="filteredSets.length === 0" class="text-center py-12">
                            <span class="text-3xl block mb-3">🏷️</span>
                            <p class="text-slate-400 text-sm font-medium">No hashtag sets yet</p>
                            <p class="text-slate-600 text-xs mt-1">Create a set above to organise your hashtags by platform and niche.</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- ── Trends Tab ───────────────────────────────────────────── --}}
        <div x-show="activeTab === 'trends'" x-cloak>
            <div x-data="trendComponent()" x-init="init()" class="space-y-4">

                {{-- Platform filter pills --}}
                <div class="flex flex-wrap gap-2">
                    @foreach(['all','tiktok','instagram','facebook','twitter','linkedin'] as $p)
                    <button @click="platform = '{{ $p }}'; load()"
                        :class="platform === '{{ $p }}' ? 'bg-violet-600 text-white border-violet-500' : 'bg-slate-800 text-slate-400 hover:text-white border-slate-700'"
                        class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors capitalize border">{{ $p }}</button>
                    @endforeach
                </div>

                <div class="flex items-center justify-between text-xs text-slate-500">
                    <span class="flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 pulse-dot"></span>
                        Auto-refreshing every 5 minutes
                    </span>
                    <button @click="load()" :disabled="loading"
                            class="flex items-center gap-1.5 text-slate-400 hover:text-white transition-colors">
                        <svg class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Refresh
                    </button>
                </div>

                {{-- Skeleton --}}
                <template x-if="loading">
                    <div class="space-y-3 animate-pulse">
                        <template x-for="i in [1,2,3,4]" :key="i">
                            <div class="stat-card">
                                <div class="flex justify-between mb-2">
                                    <div class="h-4 bg-slate-700 rounded w-48"></div>
                                    <div class="h-5 bg-slate-700 rounded-full w-16"></div>
                                </div>
                                <div class="h-3 bg-slate-700/60 rounded w-32 mb-3"></div>
                                <div class="h-1.5 bg-slate-700/40 rounded-full w-full"></div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!loading">
                    <div class="space-y-3">
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Insights derived from your knowledge base. No live API calls.
                        </div>

                        <template x-for="insight in insights" :key="insight.id">
                            <div class="stat-card"
                                 :class="{
                                     'border-emerald-500/30': insight.confidence === 'high',
                                     'border-amber-500/20': insight.confidence === 'medium',
                                 }">
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-white truncate" x-text="insight.title"></p>
                                        <p class="text-xs text-slate-500 mt-0.5">
                                            <span x-text="insight.category"></span>
                                            · <span x-text="insight.age_days + ' days ago'"></span>
                                        </p>
                                    </div>
                                    <span :class="{
                                        'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30': insight.confidence === 'high',
                                        'bg-amber-500/20 text-amber-400 border border-amber-500/30': insight.confidence === 'medium',
                                        'bg-slate-700 text-slate-400 border border-slate-600': insight.confidence === 'low'
                                    }" class="text-xs px-2 py-0.5 rounded-full ml-3 flex-shrink-0 capitalize" x-text="insight.confidence"></span>
                                </div>

                                {{-- Confidence progress bar --}}
                                <div class="mb-3">
                                    <div class="flex justify-between text-xs mb-1">
                                        <span class="text-slate-600">Confidence</span>
                                        <span :class="{
                                            'text-emerald-400': insight.confidence === 'high',
                                            'text-amber-400': insight.confidence === 'medium',
                                            'text-slate-500': insight.confidence === 'low'
                                        }" x-text="insight.confidence === 'high' ? '80–100%' : insight.confidence === 'medium' ? '50–79%' : '< 50%'"></span>
                                    </div>
                                    <div class="w-full h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-700"
                                             :class="{
                                                 'bg-emerald-500': insight.confidence === 'high',
                                                 'bg-amber-500': insight.confidence === 'medium',
                                                 'bg-slate-500': insight.confidence === 'low'
                                             }"
                                             :style="'width:' + (insight.confidence === 'high' ? 88 : insight.confidence === 'medium' ? 62 : 30) + '%'"></div>
                                    </div>
                                </div>

                                <button @click="useInsight(insight)"
                                        class="text-xs text-violet-400 hover:text-violet-300 transition-colors">
                                    Use this insight →
                                </button>
                            </div>
                        </template>
                        <div x-show="insights.length === 0" class="text-center py-12">
                            <span class="text-3xl block mb-3">📊</span>
                            <p class="text-slate-400 text-sm font-medium">No insights yet</p>
                            <p class="text-slate-600 text-xs mt-1">Import knowledge base content to generate trend analysis.</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function calendarComponent() {
    return {
        entries: [],
        weekStart: new Date(),
        weekDays: [],
        weekLabel: '',
        platformFilter: '',
        pendingCount: 0,
        showModal: false,
        editEntry: {},
        saving: false,
        publishing: false,
        modalError: '',
        hoveredEntry: null,
        calendarLoading: false,
        calendarRefreshTimer: null,
        todayStr: new Date().toISOString().split('T')[0],

        // Legend colour map
        platformColorMap: {
            tiktok: 'bg-pink-900/60 text-pink-300',
            instagram: 'bg-purple-900/60 text-purple-300',
            facebook: 'bg-blue-900/60 text-blue-300',
            twitter: 'bg-sky-900/60 text-sky-300',
            linkedin: 'bg-indigo-900/60 text-indigo-300',
        },

        init() {
            this.weekStart = this.startOfWeek(new Date());
            this.buildWeek();
            this.load();
            // Auto-refresh calendar every 60s
            this.calendarRefreshTimer = setInterval(() => this.load(), 60000);
        },

        startOfWeek(date) {
            const d = new Date(date);
            const day = d.getDay();
            const diff = d.getDate() - day + (day === 0 ? -6 : 1);
            d.setDate(diff);
            d.setHours(0,0,0,0);
            return d;
        },

        buildWeek() {
            const days = [];
            const names = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
            for (let i = 0; i < 7; i++) {
                const d = new Date(this.weekStart);
                d.setDate(d.getDate() + i);
                days.push({ date: d.toISOString().split('T')[0], label: `${names[i]} ${d.getDate()}` });
            }
            this.weekDays = days;
            const end = new Date(this.weekStart); end.setDate(end.getDate() + 6);
            this.weekLabel = this.weekStart.toLocaleDateString('en', {month:'short',day:'numeric'}) + ' – ' + end.toLocaleDateString('en', {month:'short',day:'numeric'});
        },

        prevWeek() { this.weekStart.setDate(this.weekStart.getDate() - 7); this.buildWeek(); this.load(); },
        nextWeek() { this.weekStart.setDate(this.weekStart.getDate() + 7); this.buildWeek(); this.load(); },

        async load() {
            this.calendarLoading = true;
            try {
                const week = this.weekDays[0]?.date ?? '';
                const params = new URLSearchParams({ week, per_page: 100 });
                if (this.platformFilter) params.set('platform', this.platformFilter);
                const r = await apiGet(`/dashboard/api/content-calendar?${params}`);
                this.entries = r.data ?? [];
                this.pendingCount = this.entries.filter(e => e.moderation_status === 'pending').length;
            } catch(e) {
                showToast('Failed to load calendar: ' + e.message, 'error');
            } finally {
                this.calendarLoading = false;
            }
        },

        entriesForDay(date) {
            return this.entries.filter(e => e.scheduled_at && e.scheduled_at.startsWith(date));
        },

        platformColor(platform) {
            return this.platformColorMap[platform] ?? 'bg-slate-700 text-slate-300';
        },

        platformIcon(platform) {
            const icons = {
                twitter:   `<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.746l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>`,
                instagram: `<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4.5"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>`,
                facebook:  `<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.235 2.686.235v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.263h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>`,
                linkedin:  `<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>`,
                tiktok:    `<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.69a8.18 8.18 0 004.77 1.52V6.76a4.85 4.85 0 01-1-.07z"/></svg>`,
                youtube:   `<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>`,
            };
            return icons[platform] ?? `<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg>`;
        },

        openCreate() { this.editEntry = { platform: 'instagram', content_type: 'post', status: 'draft' }; this.modalError = ''; this.showModal = true; },
        openCreateForDay(date) { this.editEntry = { platform: 'instagram', content_type: 'post', status: 'draft', scheduled_at: date + 'T09:00' }; this.modalError = ''; this.showModal = true; },
        openEdit(entry) { this.editEntry = { ...entry, scheduled_at: entry.scheduled_at ? entry.scheduled_at.replace(' ','T').substring(0,16) : '' }; this.modalError = ''; this.showModal = true; },

        async saveEntry() {
            this.saving = true; this.modalError = '';
            try {
                const method = this.editEntry.id ? 'PUT' : 'POST';
                const url = this.editEntry.id ? `/dashboard/api/content-calendar/${this.editEntry.id}` : '/dashboard/api/content-calendar';
                const r = await (method === 'POST' ? apiPost(url, this.editEntry) : apiPut(url, this.editEntry));
                if (r.error) { this.modalError = r.error; return; }
                this.showModal = false;
                showToast(this.editEntry.id ? 'Entry updated' : 'Entry created', 'success');
                await this.load();
            } catch(e) {
                this.modalError = e.message;
                showToast('Failed to save entry: ' + e.message, 'error');
            }
            finally { this.saving = false; }
        },

        async publishEntry(id) {
            this.publishing = true; this.modalError = '';
            try {
                const r = await apiPost(`/dashboard/api/content-calendar/${id}/publish`, {});
                if (r.error) { this.modalError = r.error; return; }
                this.showModal = false;
                showToast('Entry queued for publishing', 'success');
                await this.load();
            } catch(e) {
                this.modalError = e.message;
                showToast('Failed to publish entry: ' + e.message, 'error');
            }
            finally { this.publishing = false; }
        },

        async approveEntry(id) {
            try {
                await apiPost(`/dashboard/api/content-calendar/${id}/approve`, {});
                this.showModal = false;
                showToast('Entry approved', 'success');
                await this.load();
            } catch (e) {
                this.modalError = e.message;
                showToast('Approval failed: ' + e.message, 'error');
            }
        },

        async rejectEntry(id) {
            const ok = await confirmAction(
                'Reject entry?',
                'This will move the entry back to draft and it will need to be re-submitted for approval.',
                'Reject',
                'bg-amber-600 hover:bg-amber-500 text-white'
            );
            if (!ok) return;
            try {
                await apiPost(`/dashboard/api/content-calendar/${id}/reject`, {});
                this.showModal = false;
                showToast('Entry moved back to draft', 'warning');
                await this.load();
            } catch (e) {
                this.modalError = e.message;
                showToast('Rejection failed: ' + e.message, 'error');
            }
        },

        async deleteEntry(id) {
            const ok = await confirmAction(
                'Delete entry?',
                'This cannot be undone. The calendar entry will be permanently removed.',
                'Delete',
                'bg-red-600 hover:bg-red-500 text-white'
            );
            if (!ok) return;
            try {
                await apiDelete(`/dashboard/api/content-calendar/${id}`);
                this.showModal = false;
                showToast('Entry deleted', 'success');
                await this.load();
            } catch (e) {
                this.modalError = e.message;
                showToast('Failed to delete entry: ' + e.message, 'error');
            }
        },
    };
}

function accountsComponent() {
    return {
        accounts: [],
        accountsLoading: false,

        async init() { await this.load(); },

        async load() {
            this.accountsLoading = true;
            try {
                const r = await apiGet('/dashboard/api/social-accounts');
                this.accounts = r.data ?? [];
            } catch(e) {
                showToast('Failed to load accounts: ' + e.message, 'error');
            } finally {
                this.accountsLoading = false;
            }
        },

        accountFor(platform) {
            return this.accounts.find(a => a.platform === platform) ?? null;
        },

        isTokenExpiringSoon(account) {
            if (!account || !account.token_expires_at) return false;
            const expiresAt = new Date(account.token_expires_at);
            const sevenDaysFromNow = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);
            return expiresAt <= sevenDaysFromNow;
        },

        async disconnectAccount(platform) {
            const acct = this.accountFor(platform);
            if (!acct) return;
            const ok = await confirmAction(
                'Disconnect ' + platform + '?',
                'The account record and tokens will be removed. You will need to reconnect via OAuth to post again.',
                'Disconnect',
                'bg-red-600 hover:bg-red-500 text-white'
            );
            if (!ok) return;
            try {
                await apiDelete(`/dashboard/api/social-accounts/${acct.id}`);
                showToast(platform + ' disconnected', 'success');
                await this.load();
            } catch(e) {
                showToast('Failed to disconnect: ' + e.message, 'error');
            }
        },

        async updateOrgUrn(accountId, urn) {
            const acct = this.accounts.find(a => a.id === accountId);
            if (!acct) return;
            const meta = { ...(acct.metadata ?? {}), organization_urn: urn };
            try {
                await apiPatch(`/dashboard/api/social-accounts/${accountId}`, { metadata: meta });
                showToast('Organisation updated', 'success');
                await this.load();
            } catch(e) {
                showToast('Failed to update organisation: ' + e.message, 'error');
            }
        },
    };
}

function hashtagComponent() {
    return {
        sets: [],
        filteredSets: [],
        platformFilter: '',
        loading: false,
        saving: false,
        createError: '',
        newSet: { name: '', platform: 'instagram', niche: '', reach_tier: 'medium', tagsRaw: '' },

        async init() { await this.load(); },

        async load() {
            this.loading = true;
            try {
                const r = await apiGet('/dashboard/api/hashtag-sets');
                this.sets = r.data ?? [];
                this.filterSets();
            } catch(e) {
                showToast('Failed to load hashtag sets: ' + e.message, 'error');
            } finally {
                this.loading = false;
            }
        },

        filterSets() {
            this.filteredSets = this.platformFilter ? this.sets.filter(s => s.platform === this.platformFilter) : this.sets;
        },

        async createSet() {
            this.saving = true; this.createError = '';
            const tags = this.newSet.tagsRaw.split(',').map(t => t.trim().replace(/^#?/, '#')).filter(Boolean);
            try {
                const r = await apiPost('/dashboard/api/hashtag-sets', { ...this.newSet, tags });
                if (r.error) { this.createError = r.error; }
                else {
                    this.newSet = { name:'', platform:'instagram', niche:'', reach_tier:'medium', tagsRaw:'' };
                    showToast('Hashtag set created', 'success');
                    await this.load();
                }
            } catch(e) {
                this.createError = e.message;
                showToast('Failed to create set: ' + e.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        async deleteSet(id) {
            const ok = await confirmAction(
                'Delete hashtag set?',
                'This hashtag set will be permanently removed.',
                'Delete',
                'bg-red-600 hover:bg-red-500 text-white'
            );
            if (!ok) return;
            try {
                await apiDelete(`/dashboard/api/hashtag-sets/${id}`);
                showToast('Hashtag set deleted', 'success');
                await this.load();
            } catch(e) {
                showToast('Failed to delete set: ' + e.message, 'error');
            }
        },

        copyTags(set) {
            navigator.clipboard.writeText(set.tags.join(' ')).then(() => {
                showToast(set.tags.length + ' tags copied to clipboard', 'success');
            }).catch(() => {
                showToast('Clipboard access denied', 'error');
            });
        },
    };
}

function trendComponent() {
    return {
        platform: 'all',
        insights: [],
        loading: false,
        refreshTimer: null,

        async init() {
            await this.load();
            // Auto-refresh every 5 minutes
            this.refreshTimer = setInterval(() => this.load(), 5 * 60 * 1000);
        },

        async load() {
            this.loading = true;
            try {
                const r = await apiGet(`/dashboard/api/trend-insights?platform=${this.platform}`);
                this.insights = r.insights ?? [];
            } catch(e) {
                showToast('Failed to load insights: ' + e.message, 'error');
            } finally {
                this.loading = false;
            }
        },

        useInsight(insight) {
            // Switch to calendar tab and pre-fill a new entry
            window.location.hash = 'calendar';
            // Dispatch custom event for calendarComponent to pick up
            window.dispatchEvent(new CustomEvent('use-insight', { detail: insight }));
        },
    };
}

// apiPut/apiPatch/apiDelete defined globally in layouts/app.blade.php
</script>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.gsap) return;
    gsap.from('.stat-card', { opacity: 0, y: 18, duration: 0.45, stagger: 0.07, ease: 'power2.out', delay: 0.1, clearProps: 'all' });
});
</script>
@endpush
@endsection
