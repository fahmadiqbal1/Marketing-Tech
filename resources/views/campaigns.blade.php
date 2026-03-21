@extends('layouts.app')
@section('title', 'Campaigns')
@section('subtitle', 'Campaign health, send activity, and revenue attribution')

@section('content')
<div x-data="campaignsApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="stat-card"><p class="text-xs text-slate-400 uppercase">Draft</p><p class="text-3xl font-bold" x-text="summary.draft ?? 0"></p></div>
        <div class="stat-card"><p class="text-xs text-slate-400 uppercase">Active</p><p class="text-3xl font-bold" x-text="summary.active ?? 0"></p></div>
        <div class="stat-card"><p class="text-xs text-slate-400 uppercase">Sent</p><p class="text-3xl font-bold" x-text="summary.sent ?? 0"></p></div>
    </div>

    <div class="stat-card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-xs uppercase border-b border-slate-700/60">
                <tr>
                    <th class="text-left py-3">Campaign</th>
                    <th class="text-left py-3">Status</th>
                    <th class="text-left py-3">Audience</th>
                    <th class="text-left py-3">Sends</th>
                    <th class="text-left py-3">Revenue</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="!campaigns.length"><tr><td colspan="5" class="py-8 text-center text-slate-500">No campaigns available.</td></tr></template>
                <template x-for="campaign in campaigns" :key="campaign.id">
                    <tr class="border-b border-slate-800/60">
                        <td class="py-3"><div class="font-medium" x-text="campaign.name"></div><div class="text-xs text-slate-500" x-text="campaign.type"></div></td>
                        <td class="py-3"><span class="badge" :class="statusBadge(campaign.status)" x-text="campaign.status"></span></td>
                        <td class="py-3 text-slate-400" x-text="campaign.audience"></td>
                        <td class="py-3 text-slate-400" x-text="campaign.send_count ?? 0"></td>
                        <td class="py-3 text-slate-400" x-text="'$' + Number(campaign.revenue_attributed ?? 0).toFixed(2)"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
@endsection

@section('scripts')
<script>
function campaignsApp() {
    return {
        ...dashboardState(),
        campaigns: [],
        summary: {},
        async init() { await this.load(); },
        async load() {
            this.clearMessages();
            try {
                const data = this.applyMeta(await apiGet('/dashboard/api/campaigns'));
                this.campaigns = data.data ?? [];
                this.summary = data.summary ?? {};
                updateTimestamp();
            } catch (error) { this.handleError(error); }
        },
        statusBadge,
    }
}
</script>
@endsection
