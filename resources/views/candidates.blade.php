@extends('layouts.app')
@section('title', 'Candidates')
@section('subtitle', 'Hiring pipeline visibility and candidate readiness')

@section('content')
<div x-data="candidatesApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    <div class="stat-card">
        <h3 class="font-semibold mb-4">Pipeline stages</h3>
        <div class="flex flex-wrap gap-2">
            <template x-if="Object.keys(byStage).length === 0"><span class="text-slate-500 text-sm">No pipeline records available.</span></template>
            <template x-for="(count, stage) in byStage" :key="stage">
                <span class="badge bg-slate-800 border border-slate-700 text-slate-200" x-text="stage + ': ' + count"></span>
            </template>
        </div>
    </div>

    <div class="stat-card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-xs uppercase border-b border-slate-700/60">
                <tr>
                    <th class="text-left py-3">Candidate</th>
                    <th class="text-left py-3">Stage</th>
                    <th class="text-left py-3">Score</th>
                    <th class="text-left py-3">Current role</th>
                    <th class="text-left py-3">Created</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="!candidates.length"><tr><td colspan="5" class="py-8 text-center text-slate-500">No candidates available.</td></tr></template>
                <template x-for="candidate in candidates" :key="candidate.id">
                    <tr class="border-b border-slate-800/60">
                        <td class="py-3"><div class="font-medium" x-text="candidate.name"></div><div class="text-xs text-slate-500" x-text="candidate.email || 'No email'"></div></td>
                        <td class="py-3"><span class="badge" :class="statusBadge(candidate.pipeline_stage)" x-text="candidate.pipeline_stage"></span></td>
                        <td class="py-3 text-slate-400" x-text="candidate.score ?? '–'"></td>
                        <td class="py-3 text-slate-400" x-text="[candidate.current_title, candidate.current_company].filter(Boolean).join(' @ ') || '–'"></td>
                        <td class="py-3 text-slate-400 text-xs" x-text="relativeTime(candidate.created_at)"></td>
                    </tr>
                </template>
            </tbody>
        </table>
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
        async init() { await this.load(); },
        async load() {
            this.clearMessages();
            try {
                const data = this.applyMeta(await apiGet('/dashboard/api/candidates'));
                this.candidates = data.data ?? [];
                this.byStage = data.by_stage ?? {};
                updateTimestamp();
            } catch (error) { this.handleError(error); }
        },
        statusBadge, relativeTime,
    }
}
</script>
@endsection
