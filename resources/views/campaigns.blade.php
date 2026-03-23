@extends('layouts.app')
@section('title', 'Campaigns')
@section('subtitle', 'Campaign health, send activity, and revenue attribution')

@section('content')
<div x-data="campaignsApp()" x-init="init()" x-cloak class="space-y-5">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error"   class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200"    x-text="error"></div>

    {{-- Stats row --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="stat-card text-center">
            <p class="text-xs text-slate-400 uppercase mb-1">Total</p>
            <p class="text-2xl font-bold text-white" x-text="summary.total ?? 0"></p>
        </div>
        <div class="stat-card text-center">
            <p class="text-xs text-slate-400 uppercase mb-1">Active</p>
            <p class="text-2xl font-bold text-emerald-400" x-text="summary.active ?? 0"></p>
        </div>
        <div class="stat-card text-center">
            <p class="text-xs text-slate-400 uppercase mb-1">Scheduled</p>
            <p class="text-2xl font-bold text-violet-400" x-text="summary.scheduled ?? 0"></p>
        </div>
        <div class="stat-card text-center">
            <p class="text-xs text-slate-400 uppercase mb-1">Completed</p>
            <p class="text-2xl font-bold text-blue-400" x-text="summary.sent ?? 0"></p>
        </div>
        <div class="stat-card text-center">
            <p class="text-xs text-slate-400 uppercase mb-1">Total Sent</p>
            <p class="text-2xl font-bold text-white" x-text="(summary.total_sent ?? 0).toLocaleString()"></p>
        </div>
        <div class="stat-card text-center">
            <p class="text-xs text-slate-400 uppercase mb-1">Avg Open Rate</p>
            <p class="text-2xl font-bold text-amber-400" x-text="(summary.avg_open_rate ?? 0).toFixed(1) + '%'"></p>
        </div>
    </div>

    {{-- Filter bar + Create button --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex gap-1 bg-slate-900 rounded-xl p-1 border border-slate-700/50">
            @foreach(['','draft','active','scheduled','sent','paused'] as $s)
            <button @click="statusFilter = '{{ $s }}'; load()"
                :class="statusFilter === '{{ $s }}' ? 'bg-violet-600 text-white' : 'text-slate-400 hover:text-white'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors capitalize">{{ $s ?: 'All' }}</button>
            @endforeach
        </div>
        <select x-model="typeFilter" @change="load()" class="text-sm bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-slate-300 ml-auto">
            <option value="">All types</option>
            <option value="email">Email</option>
            <option value="social">Social</option>
            <option value="sms">SMS</option>
            <option value="push">Push</option>
        </select>
        <input x-model="search" @input.debounce.300ms="load()" type="text"
               placeholder="Search campaigns…" class="text-sm bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-slate-300 w-48">
        <button @click="openCreate()" class="btn-primary text-sm px-4 py-1.5">+ New Campaign</button>
    </div>

    {{-- Campaign cards grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <template x-if="!campaigns.length && !loading">
            <div class="col-span-3 py-16 text-center text-slate-500">
                <p class="text-lg mb-2">No campaigns yet</p>
                <p class="text-sm">Create your first campaign to get started.</p>
            </div>
        </template>

        <template x-for="c in campaigns" :key="c.id">
            <div class="stat-card flex flex-col gap-3">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-white truncate" x-text="c.name"></p>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs bg-slate-700 text-slate-300 px-2 py-0.5 rounded-full capitalize" x-text="c.type ?? 'email'"></span>
                            <span class="badge text-xs" :class="statusBadge(c.status)" x-text="c.status"></span>
                        </div>
                    </div>
                    <div class="flex gap-1.5 ml-2">
                        <button @click="viewDetail(c)" class="text-xs text-violet-400 hover:text-violet-300 transition-colors px-2 py-1 rounded-lg hover:bg-slate-800">View</button>
                        <button x-show="c.status === 'active'" @click="pauseCampaign(c.id)" class="text-xs text-amber-400 hover:text-amber-300 transition-colors px-2 py-1 rounded-lg hover:bg-slate-800">Pause</button>
                        <button x-show="c.status === 'paused'" @click="resumeCampaign(c.id)" class="text-xs text-emerald-400 hover:text-emerald-300 transition-colors px-2 py-1 rounded-lg hover:bg-slate-800">Resume</button>
                    </div>
                </div>

                {{-- Metrics --}}
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="bg-slate-900/60 rounded-lg py-2">
                        <p class="text-sm font-bold text-white" x-text="(c.send_count ?? 0).toLocaleString()"></p>
                        <p class="text-xs text-slate-500">Sent</p>
                    </div>
                    <div class="bg-slate-900/60 rounded-lg py-2">
                        <p class="text-sm font-bold text-white" x-text="(c.open_rate ?? 0).toFixed(1) + '%'"></p>
                        <p class="text-xs text-slate-500">Opens</p>
                    </div>
                    <div class="bg-slate-900/60 rounded-lg py-2">
                        <p class="text-sm font-bold text-white" x-text="(c.click_rate ?? 0).toFixed(1) + '%'"></p>
                        <p class="text-xs text-slate-500">Clicks</p>
                    </div>
                </div>

                {{-- Progress bars --}}
                <div class="space-y-1.5">
                    <div>
                        <div class="flex justify-between text-xs text-slate-400 mb-1">
                            <span>Open rate</span>
                            <span x-text="(c.open_rate ?? 0).toFixed(1) + '%'"></span>
                        </div>
                        <div class="h-1.5 bg-slate-800 rounded-full"><div class="h-1.5 bg-violet-500 rounded-full" :style="`width:${Math.min(c.open_rate ?? 0, 100)}%`"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs text-slate-400 mb-1">
                            <span>Click rate</span>
                            <span x-text="(c.click_rate ?? 0).toFixed(1) + '%'"></span>
                        </div>
                        <div class="h-1.5 bg-slate-800 rounded-full"><div class="h-1.5 bg-emerald-500 rounded-full" :style="`width:${Math.min((c.click_rate ?? 0) * 5, 100)}%`"></div></div>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs text-slate-500 pt-1 border-t border-slate-700/50">
                    <span x-text="c.audience ? 'Audience: ' + c.audience : ''"></span>
                    <span class="font-medium text-emerald-400" x-text="c.revenue_attributed ? '$' + Number(c.revenue_attributed).toFixed(0) + ' revenue' : ''"></span>
                </div>
            </div>
        </template>
    </div>

    {{-- Create Campaign Modal --}}
    <div x-show="showCreate" x-cloak @keydown.escape.window="showCreate = false"
         class="fixed inset-0 z-50 flex items-center justify-center">
        <div @click="showCreate = false" class="fixed inset-0 bg-black/60"></div>
        <div class="relative z-10 bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-md mx-4">
            <h3 class="text-lg font-semibold text-white mb-4">New Campaign</h3>
            <div class="space-y-3">
                <input x-model="newCampaign.name" type="text" placeholder="Campaign name" class="form-input w-full">
                <div class="grid grid-cols-2 gap-3">
                    <select x-model="newCampaign.type" class="form-input">
                        <option value="email">Email</option>
                        <option value="social">Social</option>
                        <option value="sms">SMS</option>
                        <option value="push">Push</option>
                    </select>
                    <input x-model="newCampaign.audience" type="text" placeholder="Audience segment" class="form-input">
                </div>
                <input x-model="newCampaign.subject" type="text" placeholder="Subject line" class="form-input w-full">
                <input x-model="newCampaign.schedule_at" type="datetime-local" class="form-input w-full">
                <div class="flex gap-2 pt-1">
                    <button @click="createCampaign()" :disabled="saving" class="btn-primary flex-1 text-sm py-2" x-text="saving ? 'Creating…' : 'Create Campaign'"></button>
                    <button @click="showCreate = false" class="flex-1 text-sm py-2 rounded-lg bg-slate-700 text-slate-300 hover:bg-slate-600 transition-colors">Cancel</button>
                </div>
                <div x-show="createError" x-text="createError" class="text-red-400 text-sm"></div>
            </div>
        </div>
    </div>

    {{-- Campaign detail slide-over --}}
    <div x-show="showDetail" x-cloak @keydown.escape.window="showDetail = false"
         class="fixed inset-0 z-50 flex items-start justify-end">
        <div @click="showDetail = false" class="fixed inset-0 bg-black/60"></div>
        <div class="relative z-10 w-full max-w-lg h-full bg-slate-900 border-l border-slate-700 p-6 overflow-y-auto">
            <div class="flex items-start justify-between mb-5">
                <div>
                    <h3 class="text-lg font-semibold text-white" x-text="detail.name"></h3>
                    <p class="text-xs text-slate-400 mt-1">
                        <span class="capitalize" x-text="detail.type ?? 'email'"></span>
                        · <span class="capitalize" x-text="detail.status"></span>
                    </p>
                </div>
                <button @click="showDetail = false" class="text-slate-400 hover:text-white p-1">✕</button>
            </div>

            {{-- A/B variants --}}
            <div x-show="detail.variants && detail.variants.length > 1" class="grid grid-cols-2 gap-3 mb-5">
                <template x-for="v in (detail.variants ?? [])" :key="v.label">
                    <div class="bg-slate-800 rounded-xl p-3 border" :class="v.is_winner ? 'border-emerald-500/50' : 'border-slate-700/50'">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-semibold text-white" x-text="'Variant ' + v.label"></p>
                            <span x-show="v.is_winner" class="text-xs bg-emerald-500/20 text-emerald-400 px-1.5 py-0.5 rounded">Winner</span>
                        </div>
                        <p class="text-xs text-slate-400" x-text="v.subject ?? v.content_preview ?? '—'"></p>
                        <div class="mt-2 text-xs text-slate-500">
                            Opens: <span class="text-white" x-text="(v.open_rate ?? 0).toFixed(1) + '%'"></span>
                            · Clicks: <span class="text-white" x-text="(v.click_rate ?? 0).toFixed(1) + '%'"></span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Performance chart --}}
            <div class="bg-slate-800 rounded-xl p-4 mb-5">
                <p class="text-xs text-slate-400 mb-3 uppercase tracking-wider">Daily Performance</p>
                <canvas id="campaignDetailChart" height="100"></canvas>
            </div>

            {{-- Linked calendar entries --}}
            <div x-show="detail.calendar_entries && detail.calendar_entries.length">
                <p class="text-xs text-slate-400 uppercase tracking-wider mb-2">Linked Content Calendar</p>
                <div class="space-y-2">
                    <template x-for="entry in (detail.calendar_entries ?? [])" :key="entry.id">
                        <div class="flex items-center gap-3 bg-slate-800/50 rounded-lg px-3 py-2 text-sm">
                            <span x-text="{ tiktok:'🎵', instagram:'📸', facebook:'👍', twitter:'🐦', linkedin:'💼' }[entry.platform] ?? '📱'"></span>
                            <span class="text-slate-300 flex-1 truncate" x-text="entry.title"></span>
                            <span class="text-xs text-slate-500 capitalize" x-text="entry.status"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function campaignsApp() {
    return {
        ...dashboardState(),
        campaigns: [],
        summary: {},
        statusFilter: '',
        typeFilter: '',
        search: '',
        loading: false,
        showCreate: false,
        showDetail: false,
        detail: {},
        detailData: {},
        saving: false,
        createError: '',
        newCampaign: { name: '', type: 'email', audience: '', subject: '', schedule_at: '' },

        async init() {
            const saved = JSON.parse(localStorage.getItem('filters_campaigns') ?? '{}');
            this.statusFilter = saved.statusFilter ?? '';
            this.typeFilter   = saved.typeFilter   ?? '';
            this.search       = saved.search       ?? '';
            await this.load();
        },

        async load() {
            this.loading = true;
            localStorage.setItem('filters_campaigns', JSON.stringify({ statusFilter: this.statusFilter, typeFilter: this.typeFilter, search: this.search }));
            this.clearMessages();
            try {
                const params = new URLSearchParams();
                if (this.statusFilter) params.set('status', this.statusFilter);
                if (this.typeFilter)   params.set('type', this.typeFilter);
                if (this.search)       params.set('search', this.search);
                const data = this.applyMeta(await apiGet('/dashboard/api/campaigns?' + params));
                this.campaigns = data.data ?? [];
                this.summary   = data.summary ?? {};
                updateTimestamp();
            } catch (err) { this.handleError(err); }
            finally { this.loading = false; }
        },

        openCreate() {
            this.newCampaign = { name: '', type: 'email', audience: '', subject: '', schedule_at: '' };
            this.createError = '';
            this.showCreate  = true;
        },

        async createCampaign() {
            this.saving = true; this.createError = '';
            try {
                const r = await apiPost('/dashboard/api/campaigns', this.newCampaign);
                if (r.error) { this.createError = r.error; return; }
                this.showCreate = false;
                await this.load();
            } catch (e) { this.createError = e.message; }
            finally { this.saving = false; }
        },

        async viewDetail(campaign) {
            this.detail = campaign;
            this.detailData = {};
            this.showDetail = true;
            try {
                const r = await apiGet(`/dashboard/api/campaigns/${campaign.id}/detail`);
                this.detailData = r;
            } catch (e) { /* non-fatal — chart will show empty */ }
            this.$nextTick(() => this.renderDetailChart());
        },

        renderDetailChart() {
            const ctx = document.getElementById('campaignDetailChart');
            if (! ctx) return;
            if (ctx._chart) ctx._chart.destroy();

            // Build last-7-days labels
            const days = [];
            const dayLabels = [];
            for (let i = 6; i >= 0; i--) {
                const d = new Date(); d.setDate(d.getDate() - i);
                days.push(d.toISOString().slice(0, 10));
                dayLabels.push(d.toLocaleDateString('en-US', { weekday: 'short' }));
            }

            // Count agent jobs per day from real API data
            const jobs = this.detailData.jobs ?? [];
            const jobsByDay = Object.fromEntries(days.map(d => [d, 0]));
            jobs.forEach(j => {
                const day = (j.created_at ?? '').slice(0, 10);
                if (day in jobsByDay) jobsByDay[day]++;
            });

            // Count generated outputs per day
            const outputs = this.detailData.outputs ?? [];
            const outputsByDay = Object.fromEntries(days.map(d => [d, 0]));
            outputs.forEach(o => {
                const day = (o.created_at ?? '').slice(0, 10);
                if (day in outputsByDay) outputsByDay[day]++;
            });

            ctx._chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: dayLabels,
                    datasets: [
                        { label: 'Agent runs', data: days.map(d => jobsByDay[d]), backgroundColor: 'rgba(139,92,246,0.6)', borderColor: '#8b5cf6', borderWidth: 1 },
                        { label: 'Outputs', data: days.map(d => outputsByDay[d]), backgroundColor: 'rgba(16,185,129,0.5)', borderColor: '#10b981', borderWidth: 1 },
                    ]
                },
                options: {
                    responsive: true,
                    plugins: { title: { display: false }, subtitle: { display: false }, legend: { labels: { color: '#94a3b8', font: { size: 11 } } } },
                    scales: {
                        x: { ticks: { color: '#64748b' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        y: { ticks: { color: '#64748b', precision: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                    }
                }
            });
        },

        async pauseCampaign(id) {
            await apiPost(`/dashboard/api/campaigns/${id}/pause`, {});
            await this.load();
        },

        async resumeCampaign(id) {
            await apiPost(`/dashboard/api/campaigns/${id}/resume`, {});
            await this.load();
        },

        statusBadge,
    };
}
</script>
@endpush
