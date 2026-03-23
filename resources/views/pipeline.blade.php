@extends('layouts.app')
@section('title', 'Agent Pipeline')
@section('subtitle', 'Live visibility into all agents, their capabilities, and real-time execution')

@section('content')
<div x-data="pipelineApp()" x-init="init()" x-cloak>

    {{-- ── Prompt Editor Modal ──────────────────────────────────────── --}}
    <template x-if="editModal.show">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
            <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl shadow-2xl">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                    <div>
                        <h3 class="text-base font-semibold text-white capitalize" x-text="'Edit — ' + editModal.agent + ' Agent'"></h3>
                        <p class="text-xs text-slate-500 mt-0.5">Modify the system prompt to improve agent skills and behaviour</p>
                    </div>
                    <button @click="editModal.show = false" class="text-slate-500 hover:text-white transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="px-6 py-4">
                    <label class="text-xs text-slate-400 uppercase mb-2 block">System Prompt</label>
                    <textarea x-model="editModal.prompt" rows="12"
                              class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-sm text-slate-200 font-mono leading-relaxed focus:outline-none focus:border-brand-500 resize-none"></textarea>
                    <p class="text-xs text-slate-600 mt-1" x-text="(editModal.prompt || '').length + ' characters'"></p>
                </div>
                <div class="px-6 pb-4 flex gap-3 justify-end">
                    <button @click="editModal.show = false"
                            class="px-4 py-2 border border-slate-700 text-slate-400 text-sm rounded-lg hover:border-slate-500 hover:text-white transition">Cancel</button>
                    <button @click="savePrompt()" :disabled="editModal.saving"
                            class="px-5 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-500 transition disabled:opacity-50">
                        <span x-show="editModal.saving" class="inline-block animate-spin mr-1">↻</span>
                        <span x-text="editModal.saving ? 'Saving...' : 'Save Prompt'"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- ── Error Banner ──────────────────────────────────────────────── --}}
    <div x-show="loadError" x-cloak class="mb-4 rounded-lg bg-red-900/40 border border-red-700 px-4 py-3 text-sm text-red-300 flex items-center gap-2">
        <span>⚠</span>
        <span x-text="loadError"></span>
    </div>

    {{-- ── Top Stats Bar ────────────────────────────────────────────── --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        {{-- Skeleton when loading for the first time --}}
        <template x-if="skeletonVisible">
            <template x-for="i in [1,2,3]" :key="i">
                <div class="stat-card animate-pulse">
                    <div class="h-3 bg-slate-700 rounded w-24 mb-3"></div>
                    <div class="h-8 bg-slate-700 rounded w-12 mb-2"></div>
                    <div class="h-3 bg-slate-700/60 rounded w-40"></div>
                </div>
            </template>
        </template>
        <template x-if="!skeletonVisible">
            <template x-for="stat in statCards" :key="stat.label">
                <div class="stat-card">
                    <p class="text-xs text-slate-400 uppercase mb-1" x-text="stat.label"></p>
                    <p class="text-3xl font-bold text-white tabular-nums" x-text="stat.value">–</p>
                    <p class="text-xs text-slate-500 mt-1" x-text="stat.sub"></p>
                </div>
            </template>
        </template>
    </div>

    {{-- ── Agent Cards Grid ─────────────────────────────────────────── --}}

    {{-- Skeleton cards --}}
    <template x-if="skeletonVisible">
        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
            <template x-for="i in [1,2,3,4,5,6]" :key="i">
                <div class="stat-card animate-pulse space-y-3">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-9 h-9 rounded-xl bg-slate-700 flex-shrink-0"></div>
                        <div class="flex-1">
                            <div class="h-4 bg-slate-700 rounded w-32 mb-1.5"></div>
                            <div class="h-3 bg-slate-700/60 rounded w-20"></div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <div class="h-5 bg-slate-700/60 rounded w-20"></div>
                        <div class="h-5 bg-slate-700/60 rounded w-24"></div>
                    </div>
                    <div class="h-3 bg-slate-700/40 rounded w-full"></div>
                    <div class="h-3 bg-slate-700/40 rounded w-3/4"></div>
                </div>
            </template>
        </div>
    </template>

    <template x-if="!skeletonVisible">
        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
            <template x-for="agent in agents" :key="agent.name">
                <div class="stat-card flex flex-col gap-0 relative"
                     :class="{
                         'border-violet-500/40': agent.provider === 'anthropic',
                         'border-emerald-500/30': agent.provider === 'openai' && agent.active_jobs > 0,
                         'border-blue-500/30': agent.provider === 'gemini',
                     }">

                    {{-- Card Header + Live Progress --}}
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                                 :class="{
                                     'bg-violet-500/15': agent.provider === 'anthropic',
                                     'bg-emerald-500/15': agent.provider === 'openai',
                                     'bg-blue-500/15': agent.provider === 'gemini',
                                 }">
                                <svg class="w-5 h-5"
                                     :class="{
                                         'text-violet-400': agent.provider === 'anthropic',
                                         'text-emerald-400': agent.provider === 'openai',
                                         'text-blue-400': agent.provider === 'gemini',
                                     }"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-white capitalize" x-text="agent.name + ' Agent'"></h3>
                                    <template x-if="agent.current_job">
                                        <span class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot"></span>
                                    </template>
                                </div>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="badge text-xs"
                                          :class="{
                                              'bg-violet-500/20 text-violet-400 border border-violet-500/30': agent.provider === 'anthropic',
                                              'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30': agent.provider === 'openai',
                                              'bg-blue-500/20 text-blue-400 border border-blue-500/30': agent.provider === 'gemini',
                                          }"
                                          x-text="agent.provider"></span>
                                    <span class="text-xs text-slate-500 font-mono" x-text="agent.model"></span>
                                </div>
                            </div>
                        </div>
                        <button @click="openEdit(agent)"
                                class="p-1.5 text-slate-500 hover:text-white hover:bg-slate-700 rounded-lg transition flex-shrink-0"
                                title="Edit system prompt / improve skills">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </button>
                    </div>

                    <template x-if="agent.current_job">
                        <div class="mb-2">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-emerald-400 font-semibold">Current Task:</span>
                                <span class="truncate text-xs text-white max-w-[220px]" x-text="agent.current_job.instruction?.length > 80 ? agent.current_job.instruction.substring(0,80)+'…' : agent.current_job.instruction"></span>
                            </div>
                            <div class="w-full h-2 bg-slate-700 rounded-full mt-2">
                                <div class="h-full rounded-full bg-emerald-500 transition-all duration-700"
                                     :style="'width:' + (agent.current_job.max_steps ? Math.round(100 * (agent.current_job.steps_taken || 0) / agent.current_job.max_steps) : 0) + '%'">
                                </div>
                            </div>
                            <div class="text-xs text-slate-500 mt-1">Started <span x-text="relativeTime(agent.current_job.started_at)"></span></div>
                        </div>
                    </template>
                    <template x-if="!agent.current_job">
                        <div class="mb-2 flex items-center gap-2">
                            <span class="badge text-xs bg-slate-700/60 text-slate-400 border border-slate-700">Idle</span>
                            <template x-if="agent.last_completed_job">
                                <span class="text-xs text-slate-400">Last job:</span>
                                <span class="truncate text-xs text-white max-w-[180px]" x-text="agent.last_completed_job.instruction?.length > 60 ? agent.last_completed_job.instruction.substring(0,60)+'…' : agent.last_completed_job.instruction"></span>
                                <span class="text-xs text-slate-500 ml-1" x-text="relativeTime(agent.last_completed_job.completed_at)"></span>
                            </template>
                        </div>
                    </template>

                    {{-- Queue + Steps Meta --}}
                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <span class="badge text-xs bg-slate-700/60 text-slate-400 border border-slate-700">queue: <span x-text="agent.queue"></span></span>
                        <span class="badge text-xs bg-slate-700/60 text-slate-400 border border-slate-700">max <span x-text="agent.max_steps"></span> steps</span>
                        <span x-show="agent.active_jobs > 0"
                              class="badge text-xs bg-emerald-500/20 text-emerald-400 border border-emerald-500/30"
                              x-text="agent.active_jobs + ' running'"></span>
                        {{-- Knowledge count badge --}}
                        <span x-show="agent.knowledge_count > 0"
                              class="badge text-xs bg-violet-500/20 text-violet-400 border border-violet-500/30"
                              x-text="agent.knowledge_count + ' knowledge items'"></span>
                        <span x-show="agent.knowledge_count === 0"
                              class="badge text-xs bg-amber-500/20 text-amber-400 border border-amber-500/30"
                              title="Add entries in the Knowledge tab">⚠ no knowledge</span>
                    </div>

                    {{-- Hover detail tooltip --}}
                    <div x-show="hoveredAgent === agent.name"
                         class="absolute -top-1 left-full ml-2 z-30 w-64 bg-slate-800 border border-slate-600 rounded-xl shadow-2xl p-3 text-xs pointer-events-none hidden xl:block">
                        <p class="text-slate-400 font-semibold uppercase mb-2">Quick Stats</p>
                        <div class="space-y-1.5">
                            <div class="flex justify-between">
                                <span class="text-slate-500">Active jobs</span>
                                <span class="text-white font-medium" x-text="agent.active_jobs ?? 0"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">Knowledge items</span>
                                <span class="text-white font-medium" x-text="agent.knowledge_count ?? 0"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">Tools</span>
                                <span class="text-white font-medium" x-text="(agent.tools ?? []).length"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">Recent steps</span>
                                <span class="text-white font-medium" x-text="(agent.recent_steps ?? []).length"></span>
                            </div>
                        </div>
                        <template x-if="agent.recent_steps && agent.recent_steps.length > 0">
                            <div class="mt-2 pt-2 border-t border-slate-700">
                                <p class="text-slate-500 mb-1">Last action</p>
                                <code class="text-brand-400" x-text="agent.recent_steps[0]?.action ?? '—'"></code>
                                <span class="text-slate-600 ml-2" x-text="relativeTime(agent.recent_steps[0]?.created_at)"></span>
                            </div>
                        </template>
                    </div>

                    {{-- Inline expand on hover (mobile/tablet) --}}
                    <div @mouseenter="hoveredAgent = agent.name" @mouseleave="hoveredAgent = null">

                        {{-- Capabilities / Tools --}}
                        <div class="mb-3">
                            <p class="text-xs font-semibold text-slate-500 uppercase mb-1.5">Capabilities</p>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-if="agent.tools && agent.tools.length > 0">
                                    <template x-for="tool in agent.tools" :key="tool">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-slate-800 text-slate-300 border border-slate-700/60 hover:border-slate-600 transition cursor-default"
                                              x-text="tool.replace(/_/g,' ')"></span>
                                    </template>
                                </template>
                                <template x-if="!agent.tools || agent.tools.length === 0">
                                    <span class="text-xs text-amber-400 italic">No capabilities defined</span>
                                </template>
                            </div>
                        </div>

                        {{-- Instructions Preview --}}
                        <div class="mb-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-xs font-semibold text-slate-500 uppercase">Instructions</p>
                                <button x-show="agent.system_prompt"
                                        @click="agent._showPrompt = !agent._showPrompt"
                                        class="text-xs text-slate-500 hover:text-slate-300 transition">
                                    <span x-text="agent._showPrompt ? 'hide ▲' : 'show ▼'"></span>
                                </button>
                            </div>
                            <template x-if="!agent.system_prompt">
                                <p class="text-xs text-amber-400 italic">No instructions configured — add a system prompt via the edit button above</p>
                            </template>
                            <template x-if="agent.system_prompt">
                                <div>
                                    <p class="text-xs text-slate-400 italic truncate"
                                       x-text="agent.system_prompt.length > 120 ? agent.system_prompt.substring(0, 120) + '…' : agent.system_prompt"></p>
                                    <div x-show="agent._showPrompt" class="mt-2 p-3 bg-slate-900/70 rounded-xl border border-slate-800">
                                        <p class="text-xs text-slate-300 font-mono whitespace-pre-wrap leading-relaxed"
                                           x-text="agent.system_prompt"></p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Recent Steps Mini-Feed --}}
                        <div x-show="agent.recent_steps && agent.recent_steps.length > 0">
                            <p class="text-xs font-semibold text-slate-500 uppercase mb-1.5">Recent Activity</p>
                            <div class="space-y-1">
                                <template x-for="(step, idx) in agent.recent_steps.slice(0,3)" :key="idx">
                                    <div class="flex items-center gap-2 text-xs py-1 px-2 rounded-lg bg-slate-900/60">
                                        <span class="badge py-0 text-xs"
                                              :class="{
                                                  'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30': step.status === 'completed',
                                                  'bg-blue-500/20 text-blue-400 border border-blue-500/30': step.status === 'running',
                                                  'bg-red-500/20 text-red-400 border border-red-500/30': step.status === 'failed',
                                                  'bg-slate-500/20 text-slate-400 border border-slate-700': !['completed','running','failed'].includes(step.status),
                                              }"
                                              x-text="step.status"></span>
                                        <code class="text-brand-400 truncate max-w-[120px]" x-text="step.action || '—'"></code>
                                        <span class="text-slate-600 ml-auto flex-shrink-0" x-text="relativeTime(step.created_at)"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div x-show="!agent.recent_steps || agent.recent_steps.length === 0"
                             class="text-xs text-slate-600 italic mt-1">No recent activity</div>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- ── Global Live Feed ─────────────────────────────────────────── --}}
    <div class="stat-card">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <h3 class="text-sm font-semibold text-white">Live Execution Feed</h3>
                <span class="flex items-center gap-1.5 text-xs text-slate-500">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 pulse-dot"></span>
                    Auto-refreshing every 2s
                </span>
            </div>
            <span class="text-xs text-slate-500" x-text="allSteps.length + ' steps loaded'"></span>
        </div>

        {{-- Skeleton feed rows --}}
        <template x-if="skeletonVisible">
            <div class="space-y-2 animate-pulse">
                <template x-for="i in [1,2,3,4,5]" :key="i">
                    <div class="flex gap-3 py-2 border-b border-slate-800/40">
                        <div class="h-4 bg-slate-700 rounded w-6"></div>
                        <div class="h-4 bg-slate-700 rounded w-20"></div>
                        <div class="h-4 bg-slate-700 rounded w-28"></div>
                        <div class="h-4 bg-slate-700/60 rounded flex-1"></div>
                        <div class="h-4 bg-slate-700 rounded w-16"></div>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="!skeletonVisible">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase border-b border-slate-800">
                            <th class="pb-2 text-left pr-3 w-8">#</th>
                            <th class="pb-2 text-left pr-3">Agent</th>
                            <th class="pb-2 text-left pr-3">Action / Tool</th>
                            <th class="pb-2 text-left pr-3">Thought</th>
                            <th class="pb-2 text-left pr-3">Status</th>
                            <th class="pb-2 text-left pr-3">Tool Result</th>
                            <th class="pb-2 text-left pr-3">Tokens</th>
                            <th class="pb-2 text-left pr-3">Latency</th>
                            <th class="pb-2 text-left pr-3">RAG / Cache</th>
                            <th class="pb-2 text-left">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(step, idx) in allSteps.slice(0, 40)" :key="step.id || idx">
                            <tr class="border-b border-slate-800/40 hover:bg-slate-800/20 transition" :class="step.tool_success === false ? 'bg-red-900/10' : ''">
                                <td class="py-2 pr-3 text-slate-600 text-xs" x-text="step.step_number || '–'"></td>
                                <td class="py-2 pr-3">
                                    <span class="badge text-xs"
                                          :class="{
                                              'bg-violet-500/20 text-violet-400 border border-violet-500/30': providerOf(step.agent_name) === 'anthropic',
                                              'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30': providerOf(step.agent_name) === 'openai',
                                              'bg-blue-500/20 text-blue-400 border border-blue-500/30': providerOf(step.agent_name) === 'gemini',
                                              'bg-slate-500/20 text-slate-400 border border-slate-700': !providerOf(step.agent_name),
                                          }"
                                          x-text="step.agent_name || 'master'"></span>
                                </td>
                                <td class="py-2 pr-3">
                                    <code class="text-xs text-brand-400" x-text="step.action || '—'"></code>
                                </td>
                                <td class="py-2 pr-3 text-slate-400 text-xs max-w-xs truncate" x-text="step.thought || '—'"></td>
                                <td class="py-2 pr-3">
                                    <span class="badge text-xs" :class="statusBadgeClass(step.status)" x-text="step.status"></span>
                                    <template x-if="step.status === 'failed' && step.id">
                                        <button @click="skipStep(step.id)" class="ml-1 text-xs text-slate-500 hover:text-amber-400 transition" title="Mark as skipped">skip</button>
                                    </template>
                                </td>
                                <td class="py-2 pr-3 text-xs">
                                    <template x-if="step.tool_success === true">
                                        <span class="text-emerald-400 font-bold" title="Tool succeeded">✓</span>
                                    </template>
                                    <template x-if="step.tool_success === false">
                                        <span class="text-red-400 font-bold" :title="step.tool_error || 'Tool failed'">✗</span>
                                    </template>
                                    <template x-if="step.tool_success === null || step.tool_success === undefined">
                                        <span class="text-slate-700">—</span>
                                    </template>
                                </td>
                                <td class="py-2 pr-3 text-slate-500 text-xs" x-text="step.tokens_used ? step.tokens_used + ' tok' : '—'"></td>
                                <td class="py-2 pr-3 text-slate-500 text-xs" x-text="step.latency_ms ? step.latency_ms + 'ms' : '—'"></td>
                                <td class="py-2 pr-3 text-xs">
                                    <template x-if="step.knowledge_chunks_used && step.knowledge_chunks_used.length > 0">
                                        <span class="badge bg-violet-500/20 text-violet-400 border border-violet-500/30"
                                              :title="'Chunks: ' + (step.knowledge_chunks_used || []).join(', ')"
                                              x-text="step.knowledge_chunks_used.length + ' chunk' + (step.knowledge_chunks_used.length > 1 ? 's' : '') + (avgScore(step.knowledge_scores) ? ' @ ' + avgScore(step.knowledge_scores) : '')"></span>
                                    </template>
                                    <template x-if="step.from_cache">
                                        <span class="badge bg-amber-500/20 text-amber-400 border border-amber-500/30 ml-1">cached</span>
                                    </template>
                                    <template x-if="!step.knowledge_chunks_used?.length && !step.from_cache">
                                        <span class="text-slate-700">—</span>
                                    </template>
                                </td>
                                <td class="py-2 text-slate-600 text-xs" x-text="relativeTime(step.created_at)"></td>
                            </tr>
                        </template>
                        <tr x-show="allSteps.length === 0">
                            <td colspan="10" class="py-8 text-center text-slate-500 text-sm">
                                No agent steps yet. Run a task from the <a href="/agent" class="text-brand-400 hover:text-brand-300">Agent page</a> to see live activity here.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</div>
@endsection

@section('scripts')
<script>
function pipelineApp() {
    return {
        agents: [],
        allSteps: [],
        totalRunning: 0,
        stepsToday: 0,
        loadError: '',
        pollTimer: null,
        refreshTimer: null,
        skeletonVisible: true,
        hoveredAgent: null,

        // Animated counter targets
        statCards: [
            { label: 'Total Agents',  value: '–', sub: 'marketing · content · hiring · media · growth · knowledge' },
            { label: 'Running Jobs',  value: '–', sub: 'across all agent queues' },
            { label: 'Steps Today',   value: '–', sub: 'tool executions logged' },
        ],

        // Modal
        editModal: { show: false, agent: '', prompt: '', saving: false },

        // Agent provider lookup map
        providerMap: {},

        async init() {
            await this.load();
            // Fast 2s poll for live feed
            this.pollTimer = setInterval(() => this.load(), 2000);
            // Slower 20s full-page refresh for agents panel
            this.refreshTimer = setInterval(() => this.load(), 20000);
        },

        async load() {
            try {
                const d = await apiGet('/dashboard/api/pipeline');

                const prevAgentCount   = this.agents.length;
                const prevRunning      = this.totalRunning;
                const prevStepsToday   = this.stepsToday;

                this.agents       = d.agents || [];
                this.totalRunning = d.total_running || 0;
                this.stepsToday   = d.total_steps_today || 0;
                this.loadError    = '';

                // Animate stat counters when values change
                this.animateCounter(0, prevAgentCount,   this.agents.length,   v => this.statCards[0].value = v);
                this.animateCounter(1, prevRunning,      this.totalRunning,     v => this.statCards[1].value = v);
                this.animateCounter(2, prevStepsToday,   this.stepsToday,       v => this.statCards[2].value = v);

                // Build provider lookup
                this.providerMap = {};
                this.agents.forEach(a => { this.providerMap[a.name] = a.provider; });

                // Flatten all steps from all agents for the global feed
                const all = [];
                this.agents.forEach(a => {
                    (a.recent_steps || []).forEach(s => all.push(s));
                });
                // Sort by created_at desc
                all.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
                this.allSteps = all;

                this.skeletonVisible = false;
                updateTimestamp();
            } catch(e) {
                this.loadError = 'Failed to load pipeline data: ' + e.message;
                console.error('Pipeline poll error:', e);
                this.skeletonVisible = false;
            }
        },

        animateCounter(idx, from, to, setter) {
            if (from === to || String(from) === '–') { setter(to); return; }
            const steps = 20;
            const delta = (to - from) / steps;
            let current = from;
            let step = 0;
            const timer = setInterval(() => {
                step++;
                current += delta;
                setter(step >= steps ? to : Math.round(current));
                if (step >= steps) clearInterval(timer);
            }, 30);
        },

        providerOf(agentName) {
            return this.providerMap[agentName] || null;
        },

        openEdit(agent) {
            this.editModal = {
                show: true,
                agent: agent.name,
                prompt: agent.system_prompt || '',
                saving: false,
            };
        },

        async savePrompt() {
            if (!this.editModal.prompt.trim()) return;
            this.editModal.saving = true;
            try {
                const r = await apiPost('/dashboard/api/agents/' + this.editModal.agent + '/prompt', {
                    prompt: this.editModal.prompt,
                });
                if (r.saved) {
                    // Update local state
                    const agent = this.agents.find(a => a.name === this.editModal.agent);
                    if (agent) agent.system_prompt = this.editModal.prompt;
                    this.editModal.show = false;
                    showToast('Prompt saved for ' + this.editModal.agent + ' agent', 'success');
                } else {
                    showToast('Save failed — server returned an error', 'error');
                }
            } catch(e) {
                console.error('Save prompt error:', e);
                showToast('Failed to save prompt: ' + e.message, 'error');
            }
            this.editModal.saving = false;
        },

        statusBadgeClass(status) {
            const map = {
                completed: 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
                failed:    'bg-red-500/20 text-red-400 border border-red-500/30',
                running:   'bg-blue-500/20 text-blue-400 border border-blue-500/30',
                pending:   'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30',
                paused:    'bg-orange-500/20 text-orange-400 border border-orange-500/30',
                skipped:   'bg-slate-500/20 text-slate-400 border border-slate-700',
            };
            return map[status] || 'bg-slate-500/20 text-slate-400 border border-slate-700';
        },

        async skipStep(stepId) {
            const ok = await confirmAction(
                'Skip step?',
                'This will mark the step as skipped so the agent can continue past it.',
                'Skip Step',
                'bg-amber-600 hover:bg-amber-500 text-white'
            );
            if (!ok) return;
            try {
                await apiPost('/dashboard/api/pipeline/steps/' + stepId + '/skip', {});
                // Update local status
                this.allSteps.forEach(s => { if (s.id === stepId) s.status = 'skipped'; });
                showToast('Step marked as skipped', 'warning');
            } catch(e) {
                console.error('Skip step error:', e);
                showToast('Failed to skip step: ' + e.message, 'error');
            }
        },

        avgScore(knowledgeScores) {
            if (!knowledgeScores || !knowledgeScores.length) return null;
            const scores = knowledgeScores.map(k => typeof k === 'object' ? k.score : k).filter(v => v != null);
            if (!scores.length) return null;
            const avg = scores.reduce((a, b) => a + b, 0) / scores.length;
            return avg.toFixed(2);
        },

        relativeTime, // from layout
    };
}
</script>
@endsection
