@extends('layouts.app')

@section('title', 'Marketing Agent')
@section('subtitle', 'AI-powered marketing task automation')

@section('content')
<div x-data="agentApp()" x-cloak>

    {{-- ── Task Input ──────────────────────────────────────────────── --}}
    <div class="stat-card mb-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-brand-500 to-violet-700 flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <h2 class="text-lg font-semibold text-white">New Task</h2>
        </div>

        <div class="flex gap-3">
            <input type="text" x-model="prompt"
                   @keydown.enter="startTask()"
                   class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition"
                   placeholder="e.g. Promote a dental clinic in Faisalabad on social media"
                   :disabled="isRunning">

            <select x-model="provider"
                    class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white focus:outline-none focus:border-brand-500"
                    :disabled="isRunning">
                <option value="openai">OpenAI</option>
                <option value="gemini">Gemini</option>
            </select>

            <button @click="startTask()"
                    :disabled="isRunning || !prompt.trim()"
                    class="px-6 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-2">
                <svg x-show="!isRunning" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/></svg>
                <svg x-show="isRunning" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                <span x-text="isRunning ? 'Running...' : 'Run Task'"></span>
            </button>
        </div>

        {{-- Pause / Resume --}}
        <div class="flex gap-2 mt-3" x-show="taskId">
            <button @click="pauseTask()" x-show="taskStatus === 'running'"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-yellow-500/40 text-yellow-400 hover:bg-yellow-500/10 transition">
                Pause
            </button>
            <button @click="resumeTask()" x-show="taskStatus === 'paused'"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-500/40 text-emerald-400 hover:bg-emerald-500/10 transition">
                Resume
            </button>
        </div>
    </div>

    {{-- ── API Key Alert ───────────────────────────────────────────── --}}
    <template x-if="apiNeedsUpdate">
        <div class="stat-card mb-6 border-yellow-500/40">
            <div class="flex items-center gap-2 mb-3">
                <svg class="w-5 h-5 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <p class="text-sm font-semibold text-yellow-400">API Key Required</p>
            </div>
            <p class="text-xs text-slate-400 mb-3" x-text="'The ' + apiProvider + ' API key is not configured. Enter it below to proceed.'"></p>
            <div class="flex gap-3">
                <input type="password" x-model="newApiKey"
                       class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-yellow-500"
                       placeholder="Paste your API key here">
                <button @click="updateApiKey()"
                        class="px-4 py-2 bg-yellow-600 text-white text-sm font-medium rounded-lg hover:bg-yellow-500 transition">
                    Save Key
                </button>
            </div>
        </div>
    </template>

    {{-- ── Error Banner ────────────────────────────────────────────── --}}
    <template x-if="errorMessage">
        <div class="mb-6 p-4 bg-red-500/10 border border-red-500/30 rounded-xl text-sm text-red-400">
            <span x-text="errorMessage"></span>
        </div>
    </template>

    {{-- ── Step Progress ───────────────────────────────────────────── --}}
    <div class="mb-6" x-show="steps.length > 0">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-white">Agent Progress</h2>
            <div class="flex items-center gap-2">
                <span class="badge" :class="statusBadgeClass(taskStatus)" x-text="taskStatus"></span>
                <span class="text-xs text-slate-500" x-show="totalTokens > 0" x-text="totalTokens.toLocaleString() + ' tokens'"></span>
            </div>
        </div>

        {{-- Step cards --}}
        <div class="space-y-3">
            <template x-for="(step, idx) in steps" :key="step.id || idx">
                <div class="stat-card transition-all duration-300"
                     :class="{
                         'border-brand-500/50 shadow-lg shadow-brand-500/5': step.status === 'running',
                         'border-emerald-500/30': step.status === 'completed',
                         'border-red-500/30': step.status === 'failed'
                     }">
                    {{-- Step header --}}
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-slate-500" x-text="'#' + step.step_number"></span>
                            <span class="badge bg-violet-500/20 text-violet-400 border border-violet-500/30" x-text="step.agent_name"></span>
                            <span class="badge" :class="statusBadgeClass(step.status)" x-text="step.status"></span>
                            <span x-show="step.status === 'running'" class="w-2 h-2 rounded-full bg-brand-400 pulse-dot"></span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            <span x-show="step.tokens_used" x-text="step.tokens_used + ' tok'"></span>
                            <span x-show="step.latency_ms" x-text="step.latency_ms + 'ms'"></span>
                            <span x-show="step.retry_count > 0" class="text-yellow-400" x-text="'retry:' + step.retry_count"></span>
                        </div>
                    </div>

                    {{-- Thought --}}
                    <div x-show="step.thought" class="mb-2">
                        <p class="text-xs font-semibold text-slate-500 uppercase mb-0.5">Thought</p>
                        <p class="text-sm text-slate-300" x-text="step.thought"></p>
                    </div>

                    {{-- Action + Parameters --}}
                    <div x-show="step.action" class="mb-2">
                        <p class="text-xs font-semibold text-slate-500 uppercase mb-0.5">Action</p>
                        <div class="flex items-center gap-2">
                            <code class="text-xs bg-slate-800 text-brand-400 px-2 py-0.5 rounded" x-text="step.action"></code>
                        </div>
                    </div>

                    <div x-show="step.parameters && Object.keys(step.parameters || {}).length > 0" class="mb-2">
                        <p class="text-xs font-semibold text-slate-500 uppercase mb-0.5">Parameters</p>
                        <pre class="text-xs bg-slate-900/60 text-slate-300 p-2 rounded overflow-x-auto max-h-32 overflow-y-auto" x-text="formatJson(step.parameters)"></pre>
                    </div>

                    {{-- Result (collapsible for completed steps) --}}
                    <div x-show="step.result" x-data="{ open: false }">
                        <button @click="open = !open" class="text-xs font-semibold text-slate-500 uppercase mb-0.5 flex items-center gap-1 hover:text-slate-300 transition">
                            <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-90': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            Result
                        </button>
                        <pre x-show="open" x-transition class="text-xs bg-slate-900/60 text-emerald-300 p-2 rounded overflow-x-auto max-h-60 overflow-y-auto mt-1" x-text="formatJson(step.result)"></pre>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- ── Final Output ────────────────────────────────────────────── --}}
    <div x-show="taskStatus === 'completed' && finalOutput" class="stat-card border-emerald-500/30 mb-6" x-transition>
        <div class="flex items-center gap-2 mb-4">
            <svg class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <h2 class="text-base font-semibold text-emerald-400">Final Result</h2>
        </div>

        {{-- Render structured output --}}
        <template x-if="finalOutput && finalOutput.title">
            <div>
                <h3 class="text-lg font-bold text-white mb-2" x-text="finalOutput.title"></h3>
                <p class="text-sm text-slate-300 mb-4" x-show="finalOutput.executive_summary" x-text="finalOutput.executive_summary"></p>

                <div x-show="finalOutput.key_messages" class="mb-4">
                    <p class="text-xs font-semibold text-slate-500 uppercase mb-1">Key Messages</p>
                    <ul class="list-disc list-inside text-sm text-slate-300 space-y-1">
                        <template x-for="msg in (finalOutput.key_messages || [])" :key="msg">
                            <li x-text="msg"></li>
                        </template>
                    </ul>
                </div>

                <div x-show="finalOutput.action_items" class="mb-4">
                    <p class="text-xs font-semibold text-slate-500 uppercase mb-1">Action Items</p>
                    <template x-for="item in (finalOutput.action_items || [])" :key="item.action">
                        <div class="flex items-center gap-2 py-1">
                            <span class="badge text-xs"
                                  :class="{
                                      'bg-red-500/20 text-red-400 border border-red-500/30': item.priority === 'high',
                                      'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30': item.priority === 'medium',
                                      'bg-slate-500/20 text-slate-400 border border-slate-500/30': item.priority === 'low'
                                  }"
                                  x-text="item.priority"></span>
                            <span class="text-sm text-slate-300" x-text="item.action"></span>
                            <span class="text-xs text-slate-500" x-text="item.timeline"></span>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        {{-- Fallback: raw JSON --}}
        <template x-if="finalOutput && !finalOutput.title">
            <pre class="text-xs bg-slate-900/60 text-emerald-300 p-3 rounded overflow-x-auto max-h-96 overflow-y-auto" x-text="formatJson(finalOutput)"></pre>
        </template>
    </div>

    {{-- ── Recent Tasks ────────────────────────────────────────────── --}}
    <div class="stat-card">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-white">Recent Tasks</h2>
            <button @click="loadRecentTasks()" class="text-xs text-slate-400 hover:text-white transition">Refresh</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-slate-500 uppercase border-b border-slate-800">
                        <th class="pb-2 pr-4">ID</th>
                        <th class="pb-2 pr-4">Task</th>
                        <th class="pb-2 pr-4">Status</th>
                        <th class="pb-2 pr-4">Steps</th>
                        <th class="pb-2 pr-4">Provider</th>
                        <th class="pb-2">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="t in recentTasks" :key="t.id">
                        <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 cursor-pointer transition" @click="loadTask(t.id)">
                            <td class="py-2 pr-4 text-slate-400" x-text="'#' + t.id"></td>
                            <td class="py-2 pr-4 text-slate-300 max-w-xs truncate" x-text="t.user_input"></td>
                            <td class="py-2 pr-4"><span class="badge" :class="statusBadgeClass(t.status)" x-text="t.status"></span></td>
                            <td class="py-2 pr-4 text-slate-400" x-text="t.current_step"></td>
                            <td class="py-2 pr-4 text-slate-400" x-text="t.ai_provider"></td>
                            <td class="py-2 text-slate-500 text-xs" x-text="relativeTime(t.created_at)"></td>
                        </tr>
                    </template>
                    <tr x-show="recentTasks.length === 0">
                        <td colspan="6" class="py-4 text-center text-slate-500 text-sm">No tasks yet. Run your first task above.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function agentApp() {
    return {
        // State
        prompt: '',
        provider: 'openai',
        taskId: null,
        taskStatus: '',
        steps: [],
        finalOutput: null,
        totalTokens: 0,
        errorMessage: '',
        apiNeedsUpdate: false,
        apiProvider: 'openai',
        newApiKey: '',
        recentTasks: @json($recentTasks ?? []),
        pollTimer: null,

        get isRunning() {
            return this.taskStatus === 'running' || this.taskStatus === 'pending';
        },

        // ── Start Task ──────────────────────────────────────────
        async startTask() {
            if (!this.prompt.trim() || this.isRunning) return;

            this.steps = [];
            this.finalOutput = null;
            this.errorMessage = '';
            this.taskStatus = 'pending';
            this.totalTokens = 0;
            this.apiNeedsUpdate = false;

            try {
                const res = await apiPost('/agent/run', {
                    prompt: this.prompt,
                    provider: this.provider,
                });

                if (res.error) {
                    if (res.api_needs_update) {
                        this.apiNeedsUpdate = true;
                        this.apiProvider = res.provider || this.provider;
                        this.taskStatus = '';
                    } else {
                        this.errorMessage = res.error;
                        this.taskStatus = '';
                    }
                    return;
                }

                this.taskId = res.task_id;
                this.taskStatus = 'running';
                this.startPolling();
            } catch (e) {
                this.errorMessage = 'Network error: ' + e.message;
                this.taskStatus = '';
            }
        },

        // ── Polling ─────────────────────────────────────────────
        startPolling() {
            this.stopPolling();
            this.pollTimer = setInterval(() => this.pollStatus(), 1500);
        },

        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        async pollStatus() {
            if (!this.taskId) return;

            try {
                const r = await fetch('/agent/status/' + this.taskId);
                const data = await r.json();

                this.taskStatus = data.status;
                this.steps = data.steps || [];
                this.totalTokens = data.total_tokens || 0;

                if (data.final_output) {
                    this.finalOutput = data.final_output;
                }

                if (data.error_message && data.status === 'failed') {
                    this.errorMessage = data.error_message;
                }

                // Stop polling when task is terminal
                if (['completed', 'failed'].includes(data.status)) {
                    this.stopPolling();
                    this.loadRecentTasks();
                }
            } catch (e) {
                console.error('Poll error:', e);
            }
        },

        // ── Pause / Resume ──────────────────────────────────────
        async pauseTask() {
            if (!this.taskId) return;
            await apiPost('/agent/pause/' + this.taskId);
            this.taskStatus = 'paused';
        },

        async resumeTask() {
            if (!this.taskId) return;
            await apiPost('/agent/resume/' + this.taskId);
            this.taskStatus = 'running';
            this.startPolling();
        },

        // ── Load existing task ──────────────────────────────────
        async loadTask(id) {
            this.taskId = id;
            this.errorMessage = '';
            this.finalOutput = null;
            await this.pollStatus();

            if (['running', 'pending'].includes(this.taskStatus)) {
                this.startPolling();
            }
        },

        // ── API Key Update ──────────────────────────────────────
        async updateApiKey() {
            if (!this.newApiKey.trim()) return;

            try {
                await apiPost('/agent/update-api', {
                    api_key: this.newApiKey,
                    provider: this.apiProvider,
                });
                this.apiNeedsUpdate = false;
                this.newApiKey = '';
                this.errorMessage = '';
            } catch (e) {
                this.errorMessage = 'Failed to update API key: ' + e.message;
            }
        },

        // ── Recent Tasks ────────────────────────────────────────
        async loadRecentTasks() {
            try {
                const r = await fetch('/agent/tasks');
                const data = await r.json();
                this.recentTasks = data.tasks || [];
            } catch (e) {
                console.error('Failed to load tasks:', e);
            }
        },

        // ── Helpers ─────────────────────────────────────────────
        formatJson(obj) {
            if (!obj) return '';
            try {
                return JSON.stringify(obj, null, 2);
            } catch {
                return String(obj);
            }
        },

        statusBadgeClass(status) {
            const map = {
                completed: 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
                failed:    'bg-red-500/20 text-red-400 border border-red-500/30',
                running:   'bg-blue-500/20 text-blue-400 border border-blue-500/30',
                pending:   'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30',
                paused:    'bg-orange-500/20 text-orange-400 border border-orange-500/30',
                skipped:   'bg-slate-500/20 text-slate-400 border border-slate-500/30',
            };
            return map[status] || 'bg-slate-500/20 text-slate-400 border border-slate-500/30';
        },
    }
}
</script>
@endsection
