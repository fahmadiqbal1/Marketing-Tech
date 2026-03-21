@extends('layouts.app')
@section('title', 'Agent Jobs')
@section('subtitle', 'Recent agent execution records and queue pressure')

@section('content')
<div x-data="jobsApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="stat-card"><p class="text-xs text-slate-400 uppercase">Pending</p><p class="text-3xl font-bold" x-text="summary.pending ?? 0"></p></div>
        <div class="stat-card"><p class="text-xs text-slate-400 uppercase">Running</p><p class="text-3xl font-bold" x-text="summary.running ?? 0"></p></div>
        <div class="stat-card"><p class="text-xs text-slate-400 uppercase">Failed</p><p class="text-3xl font-bold" x-text="summary.failed ?? 0"></p></div>
    </div>

    <div class="stat-card">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold">Recent agent jobs</h3>
            <button @click="load()" class="text-xs text-brand-400">Refresh</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-slate-400 text-xs uppercase border-b border-slate-700/60">
                    <tr>
                        <th class="text-left py-3">Agent</th>
                        <th class="text-left py-3">Status</th>
                        <th class="text-left py-3">Workflow</th>
                        <th class="text-left py-3">Steps</th>
                        <th class="text-left py-3">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="!jobs.length"><tr><td colspan="5" class="py-8 text-center text-slate-500">No agent jobs available.</td></tr></template>
                    <template x-for="job in jobs" :key="job.id">
                        <tr class="border-b border-slate-800/60">
                            <td class="py-3">
                                <div class="font-medium text-slate-200" x-text="job.agent_type"></div>
                                <div class="text-xs text-slate-500" x-text="job.short_description || 'No summary provided'"></div>
                            </td>
                            <td class="py-3"><span class="badge" :class="statusBadge(job.status)" x-text="job.status"></span></td>
                            <td class="py-3 text-slate-400 text-xs" x-text="job.workflow_id || '–'"></td>
                            <td class="py-3 text-slate-400" x-text="job.steps_taken ?? 0"></td>
                            <td class="py-3 text-slate-400 text-xs" x-text="relativeTime(job.created_at)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function jobsApp() {
    return {
        ...dashboardState(),
        jobs: [],
        summary: {},
        async init() { await this.load(); },
        async load() {
            this.clearMessages();
            try {
                const data = this.applyMeta(await apiGet('/dashboard/api/jobs'));
                this.jobs = data.jobs ?? [];
                this.summary = data.by_status ?? {};
                updateTimestamp();
            } catch (error) {
                this.handleError(error);
            }
        },
        statusBadge, relativeTime,
    }
}
</script>
@endsection
