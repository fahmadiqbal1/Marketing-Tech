@extends('layouts.app')
@section('title', 'Campaign Intelligence')
@section('subtitle', 'AI jobs, outputs, and best-performing content for this campaign')

@section('content')
<div x-data="campaignDetailApp('{{ $campaignId }}')" x-init="init()" x-cloak class="space-y-6">

    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    {{-- Back link --}}
    <a href="/dashboard/campaigns" class="inline-flex items-center gap-1 text-sm text-slate-400 hover:text-white transition">
        ← Back to Campaigns
    </a>

    {{-- Campaign context summary --}}
    <div class="stat-card" x-show="context">
        <p class="text-xs font-semibold text-slate-500 uppercase mb-2">AI Context Summary</p>
        <pre class="text-xs text-slate-300 whitespace-pre-wrap font-mono leading-relaxed bg-slate-900/60 rounded-lg p-4 overflow-x-auto max-h-64" x-text="context"></pre>
    </div>

    {{-- Agent Jobs --}}
    <div class="stat-card">
        <p class="text-sm font-semibold text-white mb-4">Agent Jobs in Campaign</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-slate-500 text-xs uppercase border-b border-slate-800">
                    <tr>
                        <th class="pb-2 text-left pr-3">Agent</th>
                        <th class="pb-2 text-left pr-3">Instruction</th>
                        <th class="pb-2 text-left pr-3">Status</th>
                        <th class="pb-2 text-left pr-3">Steps</th>
                        <th class="pb-2 text-left pr-3">Tokens</th>
                        <th class="pb-2 text-left pr-3">Actions</th>
                        <th class="pb-2 text-left">When</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="job in jobs" :key="job.id">
                        <tr class="border-b border-slate-800/40">
                            <td class="py-2 pr-3"><span class="badge bg-slate-700/60 text-slate-300 border border-slate-700 capitalize" x-text="job.agent_type"></span></td>
                            <td class="py-2 pr-3 text-slate-400 text-xs max-w-xs truncate" x-text="job.instruction"></td>
                            <td class="py-2 pr-3">
                                <span class="badge text-xs"
                                      :class="{
                                          'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30': job.status === 'completed',
                                          'bg-red-500/20 text-red-400 border border-red-500/30': job.status === 'failed',
                                          'bg-blue-500/20 text-blue-400 border border-blue-500/30': job.status === 'running',
                                          'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30': job.status === 'pending',
                                      }" x-text="job.status"></span>
                            </td>
                            <td class="py-2 pr-3 text-slate-500 text-xs" x-text="job.steps_taken ?? 0"></td>
                            <td class="py-2 pr-3 text-slate-500 text-xs" x-text="job.total_tokens ? job.total_tokens + ' tok' : '—'"></td>
                            <td class="py-2 pr-3 text-xs">
                                <template x-if="job.status === 'failed'">
                                    <button @click="retryJob(job.id)" class="text-brand-400 hover:text-brand-300 transition">Retry</button>
                                </template>
                            </td>
                            <td class="py-2 text-slate-600 text-xs" x-text="relativeTime(job.created_at)"></td>
                        </tr>
                    </template>
                    <tr x-show="!jobs.length">
                        <td colspan="7" class="py-8 text-center text-slate-500 text-sm">No jobs found for this campaign.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Best Performing Assets --}}
    <div class="stat-card" x-show="bestAssets.length > 0">
        <p class="text-sm font-semibold text-white mb-4">Best Performing Assets</p>
        <div class="space-y-3">
            <template x-for="(asset, idx) in bestAssets" :key="idx">
                <div class="rounded-xl bg-slate-900/60 border border-slate-700/60 px-4 py-3">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="badge bg-amber-500/20 text-amber-400 border border-amber-500/30 text-xs capitalize" x-text="asset.type"></span>
                        <span class="text-xs text-slate-600" x-text="relativeTime(asset.created_at)"></span>
                    </div>
                    <p class="text-xs text-slate-300 font-mono leading-relaxed" x-text="asset.content ? asset.content.substring(0, 300) + (asset.content.length > 300 ? '…' : '') : '—'"></p>
                </div>
            </template>
        </div>
    </div>

    {{-- Generated Outputs --}}
    <div class="stat-card" x-show="outputs.length > 0">
        <p class="text-sm font-semibold text-white mb-4">Generated Outputs</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-slate-500 text-xs uppercase border-b border-slate-800">
                    <tr>
                        <th class="pb-2 text-left pr-3">Type</th>
                        <th class="pb-2 text-left pr-3">Version</th>
                        <th class="pb-2 text-left pr-3">Winner</th>
                        <th class="pb-2 text-left pr-3">Preview</th>
                        <th class="pb-2 text-left">When</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="output in outputs" :key="output.id">
                        <tr class="border-b border-slate-800/40">
                            <td class="py-2 pr-3"><span class="badge bg-slate-700/60 text-slate-300 border border-slate-700 capitalize" x-text="output.type"></span></td>
                            <td class="py-2 pr-3 text-slate-500 text-xs" x-text="'v' + (output.version ?? 1)"></td>
                            <td class="py-2 pr-3 text-xs">
                                <span x-show="output.is_winner" class="text-amber-400">★</span>
                                <span x-show="!output.is_winner" class="text-slate-700">—</span>
                            </td>
                            <td class="py-2 pr-3 text-slate-400 text-xs max-w-xs truncate" x-text="(output.content || '').substring(0, 80)"></td>
                            <td class="py-2 text-slate-600 text-xs" x-text="relativeTime(output.created_at)"></td>
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
function campaignDetailApp(campaignId) {
    return {
        campaignId,
        jobs: [],
        outputs: [],
        bestAssets: [],
        context: '',
        error: '',

        async init() {
            await this.load();
        },

        async load() {
            try {
                const data = await apiGet('/dashboard/api/campaigns/' + this.campaignId + '/detail');
                this.jobs       = data.jobs       || [];
                this.outputs    = data.outputs    || [];
                this.bestAssets = data.best_assets || [];
                this.context    = data.context    || '';
            } catch(e) {
                this.error = 'Failed to load campaign data: ' + e.message;
            }
        },

        async retryJob(jobId) {
            try {
                await apiPost('/dashboard/api/pipeline/jobs/' + jobId + '/retry', {});
                const job = this.jobs.find(j => j.id === jobId);
                if (job) job.status = 'pending';
            } catch(e) {
                this.error = 'Failed to retry job: ' + e.message;
            }
        },

        relativeTime,
    };
}
</script>
@endsection
