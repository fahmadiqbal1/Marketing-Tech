@extends('layouts.app')

@section('title', 'Intelligence Dashboard')
@section('subtitle', 'Strategic decisions, model performance, and outcome learning')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
@endpush

@section('content')
<div x-data="intelligenceApp()" x-init="init()" class="space-y-6">

    {{-- Status bar --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs font-medium"
             :class="stats.layer_enabled ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-400' : 'border-slate-700 bg-slate-800 text-slate-400'">
            <span class="w-1.5 h-1.5 rounded-full" :class="stats.layer_enabled ? 'bg-emerald-400 pulse-dot' : 'bg-slate-600'"></span>
            Strategic Layer: <span class="ml-1 uppercase" x-text="stats.layer_enabled ? stats.strategic_mode : 'disabled'"></span>
        </div>
        <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs font-medium"
             :class="stats.bandit_enabled ? 'border-violet-500/40 bg-violet-500/10 text-violet-400' : 'border-slate-700 bg-slate-800 text-slate-400'">
            <span class="w-1.5 h-1.5 rounded-full" :class="stats.bandit_enabled ? 'bg-violet-400' : 'bg-slate-600'"></span>
            Bandit Routing: <span class="ml-1" x-text="stats.bandit_enabled ? 'active' : 'off'"></span>
        </div>
        <button @click="load()" class="ml-auto btn-secondary text-xs px-3 py-1.5">Refresh</button>
    </div>

    {{-- Row 1: Decision KPIs --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="stat-card text-center">
            <p class="text-2xl font-bold text-white" x-text="(stats.decisions?.total ?? 0).toLocaleString()"></p>
            <p class="text-xs text-slate-400 mt-1">Decisions (30d)</p>
        </div>
        <div class="stat-card text-center">
            <p class="text-2xl font-bold text-violet-400" x-text="((stats.decisions?.avg_confidence ?? 0) * 100).toFixed(1) + '%'"></p>
            <p class="text-xs text-slate-400 mt-1">Avg Confidence</p>
        </div>
        <div class="stat-card text-center">
            <p class="text-2xl font-bold text-emerald-400" x-text="stats.decisions?.avg_outcome !== null ? ((stats.decisions?.avg_outcome ?? 0) * 100).toFixed(1) + '%' : '—'"></p>
            <p class="text-xs text-slate-400 mt-1">Avg Outcome Score</p>
        </div>
        <div class="stat-card text-center">
            <p class="text-2xl font-bold text-amber-400"
               x-text="stats.decisions?.total > 0 ? (((stats.decisions?.modified ?? 0) / stats.decisions.total) * 100).toFixed(1) + '%' : '—'"></p>
            <p class="text-xs text-slate-400 mt-1">Modification Rate</p>
            <p class="text-xs text-slate-500 mt-0.5">↑ = system improving inputs</p>
        </div>
    </div>

    {{-- Row 2: Action distribution + Domain outcomes --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">Decision Actions (30d)</h3>
            <template x-if="!stats.decisions">
                <p class="text-xs text-slate-500 text-center py-4">No decisions recorded yet.</p>
            </template>
            <div class="space-y-2.5">
                <template x-for="[action, color] in [['approved','emerald'],['modified','violet'],['rejected','red'],['delayed','amber']]" :key="action">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-20 text-slate-400 capitalize" x-text="action"></span>
                        <div class="flex-1 h-2 rounded-full bg-slate-800">
                            <div class="h-full rounded-full transition-all duration-700"
                                 :class="`bg-${color}-500`"
                                 :style="'width:' + (stats.decisions?.total > 0 ? ((stats.decisions?.[action] ?? 0) / stats.decisions.total * 100).toFixed(1) : 0) + '%'"></div>
                        </div>
                        <span class="w-8 text-right text-slate-300" x-text="stats.decisions?.[action] ?? 0"></span>
                    </div>
                </template>
            </div>
        </div>

        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">Domain Performance (30d)</h3>
            <template x-if="!stats.outcomes?.length">
                <p class="text-xs text-slate-500 text-center py-4">No outcomes recorded yet.</p>
            </template>
            <div class="space-y-2.5 max-h-48 overflow-y-auto pr-1">
                <template x-for="row in (stats.outcomes ?? [])" :key="row.domain">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-20 text-slate-300 capitalize truncate" x-text="row.domain"></span>
                        <div class="flex-1 h-2 rounded-full bg-slate-800">
                            <div class="h-full rounded-full bg-violet-500 transition-all duration-700"
                                 :style="'width:' + (row.avg_score * 100).toFixed(1) + '%'"></div>
                        </div>
                        <span class="w-10 text-right text-slate-300" x-text="(row.avg_score * 100).toFixed(1) + '%'"></span>
                        <span class="text-slate-600" x-text="'(' + row.count + ')'"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Row 3: Model performance + Budget --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">Model Performance (UCB1)</h3>
            <template x-if="!stats.model_performance?.length">
                <p class="text-xs text-slate-500 text-center py-4">No model data yet. Enable BANDIT_MODEL_SELECTION to start learning.</p>
            </template>
            <div class="space-y-2 max-h-56 overflow-y-auto pr-1">
                <template x-for="row in (stats.model_performance ?? [])" :key="row.model_name + row.task_type">
                    <div class="flex items-start gap-3 py-1.5 border-b border-slate-700/40 last:border-0">
                        <div class="flex-1 min-w-0">
                            <p class="text-xs text-white font-medium truncate" x-text="row.model_name"></p>
                            <p class="text-xs text-slate-500" x-text="row.task_type + ' · ' + row.pulls + ' pulls'"></p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-xs font-semibold"
                               :class="row.score >= 0.7 ? 'text-emerald-400' : row.score >= 0.4 ? 'text-amber-400' : 'text-red-400'"
                               x-text="(row.score * 100).toFixed(0) + ' pts'"></p>
                            <p class="text-xs text-slate-600" x-text="'$' + Number(row.avg_cost_usd).toFixed(5)"></p>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">Budget Allocation</h3>
            <template x-if="!stats.budgets?.length">
                <p class="text-xs text-slate-500 text-center py-4">No budget data yet.</p>
            </template>
            <div class="space-y-2.5 max-h-56 overflow-y-auto pr-1">
                <template x-for="b in (stats.budgets ?? [])" :key="b.domain">
                    <div>
                        <div class="flex justify-between text-xs mb-0.5">
                            <span class="text-slate-300 capitalize" x-text="b.domain"></span>
                            <span class="text-slate-400" x-text="'$' + b.used_today + ' / $' + b.daily_budget"></span>
                        </div>
                        <div class="h-1.5 bg-slate-800 rounded-full">
                            <div class="h-full rounded-full transition-all duration-700"
                                 :class="b.utilisation >= 90 ? 'bg-red-500' : b.utilisation >= 70 ? 'bg-amber-500' : 'bg-emerald-500'"
                                 :style="'width:' + Math.min(100, b.utilisation) + '%'"></div>
                        </div>
                        <div class="flex justify-between text-xs mt-0.5">
                            <span class="text-slate-600" x-text="b.utilisation + '% used'"></span>
                            <span class="text-slate-600" x-text="'ROI ' + (b.roi_score * 100).toFixed(0) + '%'"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Row 4: Active insights + User feedback --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">Active Strategic Insights</h3>
            <template x-if="!stats.insights?.length">
                <p class="text-xs text-slate-500 text-center py-4">No insights yet. ExtractStrategicInsights runs daily at 04:00.</p>
            </template>
            <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                <template x-for="ins in (stats.insights ?? [])" :key="ins.insight">
                    <div class="p-2.5 rounded-lg bg-slate-800/60 border border-slate-700/40">
                        <div class="flex items-start gap-2">
                            <span class="badge mt-0.5 flex-shrink-0 text-xs" style="background:rgba(124,58,237,0.2);color:#a78bfa;" x-text="ins.domain"></span>
                            <p class="text-xs text-slate-300 leading-relaxed flex-1" x-text="ins.insight"></p>
                        </div>
                        <div class="flex items-center gap-2 mt-1.5 text-xs text-slate-600">
                            <span x-text="'Confidence: ' + (ins.confidence * 100).toFixed(0) + '%'"></span>
                            <span>·</span>
                            <span x-text="'n=' + ins.sample_size"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="stat-card">
            <h3 class="text-sm font-semibold text-white mb-3">User Feedback Loop (30d)</h3>
            <template x-if="!Object.keys(stats.feedback ?? {}).length">
                <p class="text-xs text-slate-500 text-center py-4">No feedback recorded yet.</p>
            </template>
            <div class="space-y-3">
                <template x-for="[action, count] in Object.entries(stats.feedback ?? {})" :key="action">
                    <div class="flex items-center gap-3 text-xs">
                        <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0"
                              :class="action === 'approved' ? 'bg-emerald-500/20 text-emerald-400' :
                                      action === 'edited' ? 'bg-amber-500/20 text-amber-400' :
                                      'bg-red-500/20 text-red-400'">
                            <template x-if="action === 'approved'">✓</template>
                            <template x-if="action === 'edited'">✎</template>
                            <template x-if="action !== 'approved' && action !== 'edited'">✕</template>
                        </span>
                        <span class="capitalize text-slate-300 flex-1" x-text="action"></span>
                        <span class="font-semibold text-white" x-text="count"></span>
                    </div>
                </template>
                <div class="mt-3 p-2 bg-slate-800/40 rounded text-xs text-slate-500 leading-relaxed">
                    High edit rate = agents need better prompts or brand context.<br>
                    High approval rate = system producing quality outputs.
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function intelligenceApp() {
    return {
        stats: {},
        loading: false,

        async init() { await this.load(); },

        async load() {
            this.loading = true;
            try {
                const r = await apiGet('/dashboard/api/intelligence/stats');
                this.stats = r;
            } catch (e) {
                showToast('Failed to load intelligence stats: ' + e.message, 'error');
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endpush
