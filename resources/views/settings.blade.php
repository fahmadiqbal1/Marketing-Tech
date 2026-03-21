@extends('layouts.app')
@section('title', 'Settings')
@section('subtitle', 'Configure AI platforms and system preferences')

@section('content')
<div x-data="settingsApp()" x-init="init()" x-cloak>

    {{-- ── Success / Error Banner ──────────────────────────────────── --}}
    <template x-if="toast.show">
        <div class="fixed top-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-xl text-sm font-medium transition-all"
             :class="toast.error ? 'bg-red-500/20 border border-red-500/40 text-red-300' : 'bg-emerald-500/20 border border-emerald-500/40 text-emerald-300'">
            <span x-text="toast.message"></span>
        </div>
    </template>

    {{-- ── AI Platforms ─────────────────────────────────────────────── --}}
    <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4">AI Platforms</h2>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">

        {{-- OpenAI --}}
        <div class="stat-card" :class="config.openai_configured ? 'border-emerald-500/30' : 'border-slate-700/50'">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.843-3.369 2.02-1.168a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.402-.681zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white">OpenAI</p>
                        <p class="text-xs text-slate-500">GPT-4o, Embeddings</p>
                    </div>
                </div>
                <span class="badge text-xs"
                      :class="config.openai_configured ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-slate-500/20 text-slate-400 border border-slate-700'"
                      x-text="config.openai_configured ? 'Connected' : 'Not Set'"></span>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">API Key</label>
                    <input type="password" x-model="forms.openai.api_key" placeholder="sk-..."
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-emerald-500 transition">
                </div>
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">Default Model</label>
                    <select x-model="forms.openai.model"
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500">
                        <option value="gpt-4o">gpt-4o (Recommended)</option>
                        <option value="gpt-4o-mini">gpt-4o-mini (Fast)</option>
                        <option value="gpt-4-turbo">gpt-4-turbo</option>
                    </select>
                </div>
                <button @click="savePlatform('openai')" :disabled="saving.openai"
                        class="w-full py-2 bg-emerald-600/80 hover:bg-emerald-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50">
                    <span x-text="saving.openai ? 'Saving...' : 'Save OpenAI Config'"></span>
                </button>
            </div>
        </div>

        {{-- Anthropic --}}
        <div class="stat-card" :class="config.anthropic_configured ? 'border-violet-500/30' : 'border-slate-700/50'">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-violet-500/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white">Anthropic</p>
                        <p class="text-xs text-slate-500">Claude Opus / Haiku</p>
                    </div>
                </div>
                <span class="badge text-xs"
                      :class="config.anthropic_configured ? 'bg-violet-500/20 text-violet-400 border border-violet-500/30' : 'bg-slate-500/20 text-slate-400 border border-slate-700'"
                      x-text="config.anthropic_configured ? 'Connected' : 'Not Set'"></span>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">API Key</label>
                    <input type="password" x-model="forms.anthropic.api_key" placeholder="sk-ant-..."
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-violet-500 transition">
                </div>
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">Default Model</label>
                    <select x-model="forms.anthropic.model"
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-violet-500">
                        <option value="claude-opus-4-5">claude-opus-4-5 (Best)</option>
                        <option value="claude-haiku-4-5-20251001">claude-haiku-4-5 (Fast)</option>
                        <option value="claude-sonnet-4-6">claude-sonnet-4-6</option>
                    </select>
                </div>
                <button @click="savePlatform('anthropic')" :disabled="saving.anthropic"
                        class="w-full py-2 bg-violet-600/80 hover:bg-violet-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50">
                    <span x-text="saving.anthropic ? 'Saving...' : 'Save Anthropic Config'"></span>
                </button>
            </div>
        </div>

        {{-- Gemini --}}
        <div class="stat-card" :class="config.gemini_configured ? 'border-blue-500/30' : 'border-slate-700/50'">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-blue-500/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white">Google Gemini</p>
                        <p class="text-xs text-slate-500">Gemini 1.5 / 2.0</p>
                    </div>
                </div>
                <span class="badge text-xs"
                      :class="config.gemini_configured ? 'bg-blue-500/20 text-blue-400 border border-blue-500/30' : 'bg-slate-500/20 text-slate-400 border border-slate-700'"
                      x-text="config.gemini_configured ? 'Connected' : 'Not Set'"></span>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">API Key</label>
                    <input type="password" x-model="forms.gemini.api_key" placeholder="AIza..."
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-blue-500 transition">
                </div>
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">Default Model</label>
                    <select x-model="forms.gemini.model"
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500">
                        <option value="gemini-2.0-flash">gemini-2.0-flash (Recommended)</option>
                        <option value="gemini-1.5-pro">gemini-1.5-pro (Powerful)</option>
                        <option value="gemini-1.5-flash">gemini-1.5-flash (Fast)</option>
                    </select>
                </div>
                <button @click="savePlatform('gemini')" :disabled="saving.gemini"
                        class="w-full py-2 bg-blue-600/80 hover:bg-blue-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50">
                    <span x-text="saving.gemini ? 'Saving...' : 'Save Gemini Config'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── Agent Model Assignments ──────────────────────────────────── --}}
    <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4">Agent Model Assignments</h2>
    <div class="stat-card mb-8">
        <p class="text-xs text-slate-500 mb-4">Each agent is pre-assigned a provider and model. Visit the <a href="/dashboard/pipeline" class="text-brand-400 hover:text-brand-300">Agent Pipeline</a> page to edit system prompts.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-slate-500 uppercase border-b border-slate-800">
                        <th class="pb-2 text-left pr-4">Agent</th>
                        <th class="pb-2 text-left pr-4">Provider</th>
                        <th class="pb-2 text-left pr-4">Model</th>
                        <th class="pb-2 text-left pr-4">Queue</th>
                        <th class="pb-2 text-left">Max Steps</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="agent in agents" :key="agent.name">
                        <tr class="border-b border-slate-800/50">
                            <td class="py-2.5 pr-4">
                                <span class="font-medium text-white capitalize" x-text="agent.name"></span>
                            </td>
                            <td class="py-2.5 pr-4">
                                <span class="badge text-xs"
                                      :class="{
                                          'bg-violet-500/20 text-violet-400 border border-violet-500/30': agent.provider === 'anthropic',
                                          'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30': agent.provider === 'openai',
                                          'bg-blue-500/20 text-blue-400 border border-blue-500/30': agent.provider === 'gemini',
                                      }"
                                      x-text="agent.provider"></span>
                            </td>
                            <td class="py-2.5 pr-4 text-slate-300 font-mono text-xs" x-text="agent.model"></td>
                            <td class="py-2.5 pr-4 text-slate-400 text-xs" x-text="agent.queue"></td>
                            <td class="py-2.5 text-slate-400 text-xs" x-text="agent.max_steps + ' steps'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Telegram & Other Settings ────────────────────────────────── --}}
    <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4">Telegram Integration</h2>
    <div class="stat-card mb-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Bot Token</label>
                <input type="password" x-model="forms.telegram.bot_token" placeholder="1234567890:AAF..."
                       class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Admin Chat ID</label>
                <input type="text" x-model="forms.telegram.admin_chat_id" placeholder="123456789"
                       class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
            </div>
        </div>
        <div class="flex gap-3 mt-4">
            <button @click="saveTelegram()" :disabled="saving.telegram"
                    class="px-4 py-2 bg-brand-600/80 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50">
                <span x-text="saving.telegram ? 'Saving...' : 'Save Telegram Config'"></span>
            </button>
            <button @click="registerWebhook()" :disabled="saving.webhook"
                    class="px-4 py-2 border border-slate-600 hover:border-slate-500 text-slate-300 text-sm font-medium rounded-lg transition disabled:opacity-50">
                <span x-text="saving.webhook ? 'Registering...' : 'Register Webhook'"></span>
            </button>
        </div>
        <p x-show="webhookResult" class="mt-2 text-xs text-slate-400" x-text="webhookResult"></p>
    </div>

</div>
@endsection

@section('scripts')
<script>
function settingsApp() {
    return {
        config: {
            openai_configured: false,
            anthropic_configured: false,
            gemini_configured: false,
        },
        agents: [],
        forms: {
            openai:    { api_key: '', model: 'gpt-4o' },
            anthropic: { api_key: '', model: 'claude-opus-4-5' },
            gemini:    { api_key: '', model: 'gemini-2.0-flash' },
            telegram:  { bot_token: '', admin_chat_id: '' },
        },
        saving: { openai: false, anthropic: false, gemini: false, telegram: false, webhook: false },
        toast: { show: false, message: '', error: false },
        webhookResult: '',

        async init() {
            // Load config status
            try {
                const r = await fetch('/agent/config');
                this.config = await r.json();
            } catch(e) {}

            // Load agent assignments from pipeline endpoint
            try {
                const r = await fetch('/dashboard/api/pipeline');
                const d = await r.json();
                this.agents = (d.agents || []).map(a => ({
                    name: a.name,
                    provider: a.provider,
                    model: a.model,
                    queue: a.queue,
                    max_steps: a.max_steps,
                }));
            } catch(e) {}
        },

        async savePlatform(provider) {
            const form = this.forms[provider];
            if (!form.api_key.trim()) {
                this.showToast('Please enter an API key.', true);
                return;
            }
            this.saving[provider] = true;
            try {
                const r = await apiPost('/dashboard/api/platform', {
                    provider,
                    api_key: form.api_key,
                    model: form.model,
                });
                if (r.saved) {
                    this.config[provider + '_configured'] = true;
                    form.api_key = '';
                    this.showToast(provider.charAt(0).toUpperCase() + provider.slice(1) + ' config saved.');
                } else {
                    this.showToast('Save failed.', true);
                }
            } catch(e) {
                this.showToast('Error: ' + e.message, true);
            }
            this.saving[provider] = false;
        },

        async saveTelegram() {
            this.saving.telegram = true;
            try {
                const data = {};
                if (this.forms.telegram.bot_token) data.TELEGRAM_BOT_TOKEN = this.forms.telegram.bot_token;
                if (this.forms.telegram.admin_chat_id) data.TELEGRAM_ADMIN_CHAT_ID = this.forms.telegram.admin_chat_id;
                const r = await apiPost('/dashboard/api/settings', data);
                if (r.saved) {
                    this.forms.telegram.bot_token = '';
                    this.showToast('Telegram config saved.');
                }
            } catch(e) {
                this.showToast('Error: ' + e.message, true);
            }
            this.saving.telegram = false;
        },

        async registerWebhook() {
            this.saving.webhook = true;
            try {
                const r = await apiPost('/dashboard/api/settings/telegram/webhook');
                this.webhookResult = r.output || (r.success ? 'Webhook registered.' : 'Failed.');
                this.showToast(r.success ? 'Webhook registered.' : 'Webhook failed.', !r.success);
            } catch(e) {
                this.showToast('Error: ' + e.message, true);
            }
            this.saving.webhook = false;
        },

        showToast(message, error = false) {
            this.toast = { show: true, message, error };
            setTimeout(() => this.toast.show = false, 3500);
        },
    };
}
</script>
@endsection
