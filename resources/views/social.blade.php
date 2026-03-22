@extends('layouts.app')
@section('title', 'Social Media')
@section('subtitle', 'Content calendar, accounts, hashtag library, and trend insights')

@section('content')
<div class="space-y-6">

    {{-- Tab navigation --}}
    <div x-data="{ activeTab: 'calendar' }" class="space-y-6">
        <div class="flex gap-2 border-b border-slate-700/60 pb-0">
            @foreach([['calendar','Calendar'],['accounts','Accounts'],['hashtags','Hashtags'],['trends','Trends']] as [$tab,$label])
            <button @click="activeTab = '{{ $tab }}'"
                :class="activeTab === '{{ $tab }}' ? 'border-b-2 border-violet-500 text-white' : 'text-slate-400 hover:text-slate-200'"
                class="px-4 py-2 text-sm font-medium transition-colors -mb-px">{{ $label }}</button>
            @endforeach
        </div>

        {{-- Calendar Tab --}}
        <div x-show="activeTab === 'calendar'" x-cloak>
            <div x-data="calendarComponent()" x-init="init()" class="space-y-4">

                {{-- Header --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button @click="prevWeek()" class="p-2 rounded-lg hover:bg-slate-800 text-slate-400 hover:text-white transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <span class="text-sm font-medium text-slate-200" x-text="weekLabel"></span>
                        <button @click="nextWeek()" class="p-2 rounded-lg hover:bg-slate-800 text-slate-400 hover:text-white transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <select x-model="platformFilter" @change="load()" class="text-sm bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-slate-300">
                            <option value="">All platforms</option>
                            <option value="tiktok">TikTok</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="twitter">Twitter/X</option>
                            <option value="linkedin">LinkedIn</option>
                        </select>
                        <button @click="openCreate()" class="btn-primary text-sm px-4 py-1.5">+ New Entry</button>
                    </div>
                </div>

                {{-- Week grid --}}
                <div class="grid grid-cols-7 gap-2">
                    <template x-for="day in weekDays" :key="day.date">
                        <div class="min-h-32 bg-slate-900 rounded-xl border border-slate-700/50 p-2">
                            <div class="text-xs text-slate-500 mb-2 font-medium" x-text="day.label"></div>
                            <template x-for="entry in entriesForDay(day.date)" :key="entry.id">
                                <div @click="openEdit(entry)"
                                     :class="platformColor(entry.platform)"
                                     class="mb-1.5 px-2 py-1 rounded-lg text-xs cursor-pointer truncate flex items-center gap-1.5 group">
                                    <span x-text="platformIcon(entry.platform)"></span>
                                    <span class="truncate" x-text="entry.title"></span>
                                    <span x-show="entry.moderation_status === 'pending'" class="ml-auto text-amber-400 text-xs">⚠</span>
                                    <span x-show="entry.status === 'published'" class="ml-auto text-emerald-400 text-xs">✓</span>
                                </div>
                            </template>
                            <button @click="openCreateForDay(day.date)" class="w-full text-xs text-slate-600 hover:text-slate-400 py-1 transition-colors">+ add</button>
                        </div>
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
                            <div class="flex gap-2">
                                <button @click="saveEntry()" :disabled="saving" class="btn-primary flex-1 text-sm py-2" x-text="saving ? 'Saving…' : 'Save'"></button>
                                <template x-if="editEntry.id && editEntry.status !== 'published'">
                                    <button @click="publishEntry(editEntry.id)" :disabled="publishing" class="bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg px-4 text-sm py-2 transition-colors" x-text="publishing ? 'Publishing…' : 'Publish'"></button>
                                </template>
                                <template x-if="editEntry.id && editEntry.moderation_status === 'pending'">
                                    <button @click="approveEntry(editEntry.id)" class="bg-violet-600 hover:bg-violet-500 text-white rounded-lg px-4 text-sm py-2 transition-colors">Approve</button>
                                </template>
                            </div>
                            <div x-show="modalError" x-text="modalError" class="text-red-400 text-sm"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Accounts Tab --}}
        <div x-show="activeTab === 'accounts'" x-cloak>
            <div x-data="accountsComponent()" x-init="init()" class="space-y-4">

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach([
                        ['tiktok',    'TikTok',    'bg-slate-800 border-slate-700', '#EE1D52'],
                        ['instagram', 'Instagram', 'bg-slate-800 border-slate-700', '#E1306C'],
                        ['facebook',  'Facebook',  'bg-slate-800 border-slate-700', '#1877F2'],
                        ['twitter',   'Twitter/X', 'bg-slate-800 border-slate-700', '#1DA1F2'],
                        ['linkedin',  'LinkedIn',  'bg-slate-800 border-slate-700', '#0077B5'],
                    ] as [$platform, $label, $cardClass, $color])
                    <div class="stat-card relative">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <p class="text-sm font-semibold text-white">{{ $label }}</p>
                                <template x-if="accountFor('{{ $platform }}')">
                                    <p class="text-xs text-slate-400 mt-0.5" x-text="'@' + accountFor('{{ $platform }}').handle"></p>
                                </template>
                            </div>
                            <template x-if="accountFor('{{ $platform }}') && accountFor('{{ $platform }}').is_connected">
                                <span class="text-xs bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full">Connected</span>
                            </template>
                            <template x-if="!accountFor('{{ $platform }}') || !accountFor('{{ $platform }}').is_connected">
                                <span class="text-xs bg-slate-700 text-slate-400 px-2 py-0.5 rounded-full">Not connected</span>
                            </template>
                        </div>

                        <template x-if="accountFor('{{ $platform }}')">
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
                            <p class="text-xs text-red-400 mb-2 truncate" x-text="accountFor('{{ $platform }}').last_error"></p>
                        </template>

                        <div class="flex gap-2">
                            @if($platform === 'instagram')
                            <a href="/dashboard/social/auth/instagram/redirect" class="flex-1 text-center text-sm py-1.5 rounded-lg bg-violet-600 hover:bg-violet-500 text-white transition-colors">
                                <template x-if="accountFor('instagram') && accountFor('instagram').is_connected">
                                    <span>Reconnect</span>
                                </template>
                                <template x-if="!accountFor('instagram') || !accountFor('instagram').is_connected">
                                    <span>Connect</span>
                                </template>
                            </a>
                            @else
                            <button @click="openConnect('{{ $platform }}')" class="flex-1 text-sm py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 transition-colors">
                                <template x-if="accountFor('{{ $platform }}') && accountFor('{{ $platform }}').is_connected">
                                    <span>Edit</span>
                                </template>
                                <template x-if="!accountFor('{{ $platform }}') || !accountFor('{{ $platform }}').is_connected">
                                    <span>Add Token</span>
                                </template>
                            </button>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- Connect modal (for non-Instagram platforms) --}}
                <div x-show="showConnect" x-cloak @keydown.escape.window="showConnect = false"
                     class="fixed inset-0 z-50 flex items-center justify-center">
                    <div @click="showConnect = false" class="fixed inset-0 bg-black/60"></div>
                    <div class="relative z-10 bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-md mx-4">
                        <h3 class="text-lg font-semibold text-white mb-4" x-text="'Connect ' + connectPlatform"></h3>
                        <div class="space-y-3">
                            <div>
                                <label class="text-xs text-slate-400 mb-1 block">Handle / Username</label>
                                <input x-model="connectHandle" type="text" class="form-input w-full" placeholder="@yourbrand">
                            </div>
                            <div>
                                <label class="text-xs text-slate-400 mb-1 block">Access Token (optional)</label>
                                <input x-model="connectToken" type="password" class="form-input w-full" placeholder="Paste token here">
                            </div>
                            <div class="flex gap-2 pt-1">
                                <button @click="saveAccount()" :disabled="saving" class="btn-primary flex-1 text-sm py-2" x-text="saving ? 'Saving…' : 'Save'"></button>
                                <button @click="showConnect = false" class="flex-1 text-sm py-2 rounded-lg bg-slate-700 text-slate-300 hover:bg-slate-600 transition-colors">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Hashtags Tab --}}
        <div x-show="activeTab === 'hashtags'" x-cloak>
            <div x-data="hashtagComponent()" x-init="init()" class="space-y-4">

                {{-- Create form --}}
                <div class="stat-card">
                    <h3 class="text-sm font-semibold text-white mb-3">New Hashtag Set</h3>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <input x-model="newSet.name" type="text" class="form-input" placeholder="Set name">
                        <select x-model="newSet.platform" class="form-input">
                            <option value="tiktok">TikTok</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="twitter">Twitter/X</option>
                            <option value="linkedin">LinkedIn</option>
                        </select>
                        <input x-model="newSet.niche" type="text" class="form-input" placeholder="Niche (optional)">
                        <select x-model="newSet.reach_tier" class="form-input">
                            <option value="low">Low reach</option>
                            <option value="medium">Medium reach</option>
                            <option value="high">High reach</option>
                        </select>
                    </div>
                    <input x-model="newSet.tagsRaw" type="text" class="form-input w-full mb-3" placeholder="#tag1, #tag2, #tag3 (comma-separated)">
                    <button @click="createSet()" :disabled="saving" class="btn-primary text-sm px-4 py-1.5" x-text="saving ? 'Saving…' : 'Add Set'"></button>
                    <div x-show="createError" x-text="createError" class="text-red-400 text-sm mt-2"></div>
                </div>

                {{-- Filter by platform --}}
                <div class="flex gap-2">
                    @foreach(['','tiktok','instagram','facebook','twitter','linkedin'] as $p)
                    <button @click="platformFilter = '{{ $p }}'; filterSets()"
                        :class="platformFilter === '{{ $p }}' ? 'bg-violet-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">{{ $p ?: 'All' }}</button>
                    @endforeach
                </div>

                {{-- Hashtag sets list --}}
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
                                        · used <span x-text="set.usage_count"></span>×
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <button @click="copyTags(set)" class="text-xs text-slate-400 hover:text-white transition-colors px-2 py-1 bg-slate-800 rounded-lg">Copy all</button>
                                    <button @click="deleteSet(set.id)" class="text-xs text-red-400 hover:text-red-300 transition-colors px-2 py-1 bg-slate-800 rounded-lg">Delete</button>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="tag in set.tags" :key="tag">
                                    <span class="text-xs bg-slate-800 text-violet-400 px-2 py-0.5 rounded-full" x-text="tag"></span>
                                </template>
                            </div>
                        </div>
                    </template>
                    <div x-show="filteredSets.length === 0 && !loading" class="text-center py-8 text-slate-500 text-sm">No hashtag sets yet. Create one above.</div>
                </div>
            </div>
        </div>

        {{-- Trends Tab --}}
        <div x-show="activeTab === 'trends'" x-cloak>
            <div x-data="trendComponent()" x-init="init()" class="space-y-4">

                {{-- Platform tabs --}}
                <div class="flex gap-2">
                    @foreach(['all','tiktok','instagram','facebook','twitter','linkedin'] as $p)
                    <button @click="platform = '{{ $p }}'; load()"
                        :class="platform === '{{ $p }}' ? 'bg-violet-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors capitalize">{{ $p }}</button>
                    @endforeach
                </div>

                <div x-show="loading" class="text-center py-8 text-slate-500 text-sm">Analysing patterns…</div>

                <div x-show="!loading" class="space-y-3">
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Insights derived from your knowledge base. No live API calls.
                    </div>

                    <template x-for="insight in insights" :key="insight.id">
                        <div class="stat-card">
                            <div class="flex items-start justify-between mb-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-white truncate" x-text="insight.title"></p>
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        <span x-text="insight.category"></span>
                                        · <span x-text="insight.age_days + ' days ago'"></span>
                                    </p>
                                </div>
                                <span :class="{
                                    'bg-emerald-500/20 text-emerald-400': insight.confidence === 'high',
                                    'bg-amber-500/20 text-amber-400': insight.confidence === 'medium',
                                    'bg-slate-700 text-slate-400': insight.confidence === 'low'
                                }" class="text-xs px-2 py-0.5 rounded-full ml-3 flex-shrink-0" x-text="insight.confidence"></span>
                            </div>
                            <button @click="useInsight(insight)"
                                    class="text-xs text-violet-400 hover:text-violet-300 transition-colors">
                                Use this insight →
                            </button>
                        </div>
                    </template>
                    <div x-show="insights.length === 0" class="text-center py-8 text-slate-500 text-sm">
                        No insights yet. Import knowledge base content to generate trend analysis.
                    </div>
                </div>
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

        init() {
            this.weekStart = this.startOfWeek(new Date());
            this.buildWeek();
            this.load();
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
            const week = this.weekDays[0]?.date ?? '';
            const params = new URLSearchParams({ week, per_page: 100 });
            if (this.platformFilter) params.set('platform', this.platformFilter);
            const r = await apiGet(`/dashboard/api/content-calendar?${params}`);
            this.entries = r.data ?? [];
            this.pendingCount = this.entries.filter(e => e.moderation_status === 'pending').length;
        },

        entriesForDay(date) {
            return this.entries.filter(e => e.scheduled_at && e.scheduled_at.startsWith(date));
        },

        platformColor(platform) {
            return {
                tiktok: 'bg-pink-900/60 text-pink-300',
                instagram: 'bg-purple-900/60 text-purple-300',
                facebook: 'bg-blue-900/60 text-blue-300',
                twitter: 'bg-sky-900/60 text-sky-300',
                linkedin: 'bg-indigo-900/60 text-indigo-300',
            }[platform] ?? 'bg-slate-700 text-slate-300';
        },

        platformIcon(platform) {
            return { tiktok:'🎵', instagram:'📸', facebook:'👍', twitter:'🐦', linkedin:'💼' }[platform] ?? '📱';
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
                await this.load();
            } catch(e) { this.modalError = e.message; }
            finally { this.saving = false; }
        },

        async publishEntry(id) {
            this.publishing = true; this.modalError = '';
            try {
                const r = await apiPost(`/dashboard/api/content-calendar/${id}/publish`, {});
                if (r.error) { this.modalError = r.error; return; }
                this.showModal = false;
                await this.load();
            } catch(e) { this.modalError = e.message; }
            finally { this.publishing = false; }
        },

        async approveEntry(id) {
            await apiPost(`/dashboard/api/content-calendar/${id}/approve`, {});
            this.showModal = false;
            await this.load();
        },
    };
}

function accountsComponent() {
    return {
        accounts: [],
        showConnect: false,
        connectPlatform: '',
        connectHandle: '',
        connectToken: '',
        saving: false,

        async init() { await this.load(); },

        async load() {
            const r = await apiGet('/dashboard/api/social-accounts');
            this.accounts = r.data ?? [];
        },

        accountFor(platform) {
            return this.accounts.find(a => a.platform === platform) ?? null;
        },

        openConnect(platform) {
            const existing = this.accountFor(platform);
            this.connectPlatform = platform;
            this.connectHandle   = existing?.handle ?? '';
            this.connectToken    = '';
            this.showConnect     = true;
        },

        async saveAccount() {
            this.saving = true;
            const payload = { platform: this.connectPlatform, handle: this.connectHandle.replace('@',''), access_token: this.connectToken || undefined };
            await apiPost('/dashboard/api/social-accounts', payload);
            this.showConnect = false;
            this.saving = false;
            await this.load();
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
            const r = await apiGet('/dashboard/api/hashtag-sets');
            this.sets = r.data ?? [];
            this.filterSets();
            this.loading = false;
        },

        filterSets() {
            this.filteredSets = this.platformFilter ? this.sets.filter(s => s.platform === this.platformFilter) : this.sets;
        },

        async createSet() {
            this.saving = true; this.createError = '';
            const tags = this.newSet.tagsRaw.split(',').map(t => t.trim().replace(/^#?/, '#')).filter(Boolean);
            const r = await apiPost('/dashboard/api/hashtag-sets', { ...this.newSet, tags });
            if (r.error) { this.createError = r.error; }
            else { this.newSet = { name:'', platform:'instagram', niche:'', reach_tier:'medium', tagsRaw:'' }; await this.load(); }
            this.saving = false;
        },

        async deleteSet(id) {
            await apiDelete(`/dashboard/api/hashtag-sets/${id}`);
            await this.load();
        },

        copyTags(set) {
            navigator.clipboard.writeText(set.tags.join(' '));
        },
    };
}

function trendComponent() {
    return {
        platform: 'all',
        insights: [],
        loading: false,

        async init() { await this.load(); },

        async load() {
            this.loading = true;
            const r = await apiGet(`/dashboard/api/trend-insights?platform=${this.platform}`);
            this.insights = r.insights ?? [];
            this.loading = false;
        },

        useInsight(insight) {
            // Switch to calendar tab and pre-fill a new entry
            document.querySelectorAll('[\\@click]');
            window.location.hash = 'calendar';
            // Dispatch custom event for calendarComponent to pick up
            window.dispatchEvent(new CustomEvent('use-insight', { detail: insight }));
        },
    };
}

// Helper: apiPut
async function apiPut(url, data) {
    const r = await fetch(url, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
        body: JSON.stringify(data),
    });
    return r.json();
}

async function apiDelete(url) {
    return fetch(url, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
    }).then(r => r.json());
}
</script>
@endpush
@endsection
