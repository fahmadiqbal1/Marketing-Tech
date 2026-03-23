@extends('layouts.app')
@section('title', 'Settings')
@section('subtitle', 'Configure AI platforms, system preferences, and integrations')

@section('content')
<div x-data="settingsApp()" x-init="init()" x-cloak>

    {{-- ── Unsaved changes sticky banner ──────────────────────────────── --}}
    <div x-show="hasChanges" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex items-center gap-4 px-5 py-3 rounded-xl bg-amber-500/20 border border-amber-500/40 shadow-2xl text-sm backdrop-blur-sm">
        <span class="text-amber-300 font-medium">You have unsaved changes</span>
        <button @click="saveSysSettings()"
                class="px-3 py-1.5 bg-amber-500 hover:bg-amber-400 text-slate-900 text-xs font-bold rounded-lg transition-colors">
            Save Now
        </button>
        <button @click="discardChanges()"
                class="px-3 py-1.5 border border-amber-500/50 text-amber-400 hover:text-amber-300 text-xs rounded-lg transition-colors">
            Discard
        </button>
    </div>

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
                    <div class="relative">
                        <input :type="showKey.openai ? 'text' : 'password'" x-model="forms.openai.api_key"
                               @input="fieldErrors.openai_key = ''"
                               placeholder="sk-..."
                               class="w-full bg-slate-900 border rounded-lg px-3 py-2 pr-9 text-sm text-white placeholder-slate-600 focus:outline-none transition"
                               :class="fieldErrors.openai_key ? 'border-red-500 focus:border-red-400' : 'border-slate-700 focus:border-emerald-500'">
                        <button type="button" @click="showKey.openai = !showKey.openai"
                                class="absolute right-2.5 top-2.5 text-slate-500 hover:text-slate-300 transition-colors">
                            <svg x-show="!showKey.openai" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <svg x-show="showKey.openai" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                        </button>
                    </div>
                    <p x-show="fieldErrors.openai_key" class="text-xs text-red-400 mt-1" x-text="fieldErrors.openai_key"></p>
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
                <div class="flex gap-2">
                    <button @click="savePlatform('openai')" :disabled="saving.openai"
                            class="flex-1 py-2 bg-emerald-600/80 hover:bg-emerald-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50 flex items-center justify-center gap-2">
                        <span x-show="saving.openai" class="inline-block animate-spin text-sm">↻</span>
                        <span x-text="saving.openai ? 'Saving...' : 'Save Config'"></span>
                    </button>
                    <button @click="testConnection('openai')" :disabled="testing.openai"
                            class="px-3 py-2 border border-emerald-500/40 text-emerald-400 text-sm rounded-lg hover:bg-emerald-500/10 transition disabled:opacity-50"
                            title="Test connection">
                        <span x-show="!testing.openai">⚡</span>
                        <span x-show="testing.openai" class="inline-block animate-spin">↻</span>
                    </button>
                </div>
                <div x-show="testResult.openai" class="text-xs rounded-lg px-3 py-2 mt-1"
                     :class="testResult.openai?.success ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'">
                    <span x-text="(testResult.openai?.success ? '✓ ' : '✗ ') + (testResult.openai?.message || '') + (testResult.openai?.latency_ms ? ' (' + testResult.openai.latency_ms + 'ms)' : '')"></span>
                </div>
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
                    <div class="relative">
                        <input :type="showKey.anthropic ? 'text' : 'password'" x-model="forms.anthropic.api_key"
                               @input="fieldErrors.anthropic_key = ''"
                               placeholder="sk-ant-..."
                               class="w-full bg-slate-900 border rounded-lg px-3 py-2 pr-9 text-sm text-white placeholder-slate-600 focus:outline-none transition"
                               :class="fieldErrors.anthropic_key ? 'border-red-500 focus:border-red-400' : 'border-slate-700 focus:border-violet-500'">
                        <button type="button" @click="showKey.anthropic = !showKey.anthropic"
                                class="absolute right-2.5 top-2.5 text-slate-500 hover:text-slate-300 transition-colors">
                            <svg x-show="!showKey.anthropic" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <svg x-show="showKey.anthropic" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                        </button>
                    </div>
                    <p x-show="fieldErrors.anthropic_key" class="text-xs text-red-400 mt-1" x-text="fieldErrors.anthropic_key"></p>
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
                <div class="flex gap-2">
                    <button @click="savePlatform('anthropic')" :disabled="saving.anthropic"
                            class="flex-1 py-2 bg-violet-600/80 hover:bg-violet-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50 flex items-center justify-center gap-2">
                        <span x-show="saving.anthropic" class="inline-block animate-spin text-sm">↻</span>
                        <span x-text="saving.anthropic ? 'Saving...' : 'Save Config'"></span>
                    </button>
                    <button @click="testConnection('anthropic')" :disabled="testing.anthropic"
                            class="px-3 py-2 border border-violet-500/40 text-violet-400 text-sm rounded-lg hover:bg-violet-500/10 transition disabled:opacity-50"
                            title="Test connection">
                        <span x-show="!testing.anthropic">⚡</span>
                        <span x-show="testing.anthropic" class="inline-block animate-spin">↻</span>
                    </button>
                </div>
                <div x-show="testResult.anthropic" class="text-xs rounded-lg px-3 py-2 mt-1"
                     :class="testResult.anthropic?.success ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'">
                    <span x-text="(testResult.anthropic?.success ? '✓ ' : '✗ ') + (testResult.anthropic?.message || '') + (testResult.anthropic?.latency_ms ? ' (' + testResult.anthropic.latency_ms + 'ms)' : '')"></span>
                </div>
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
                    <div class="relative">
                        <input :type="showKey.gemini ? 'text' : 'password'" x-model="forms.gemini.api_key"
                               @input="fieldErrors.gemini_key = ''"
                               placeholder="AIza..."
                               class="w-full bg-slate-900 border rounded-lg px-3 py-2 pr-9 text-sm text-white placeholder-slate-600 focus:outline-none transition"
                               :class="fieldErrors.gemini_key ? 'border-red-500 focus:border-red-400' : 'border-slate-700 focus:border-blue-500'">
                        <button type="button" @click="showKey.gemini = !showKey.gemini"
                                class="absolute right-2.5 top-2.5 text-slate-500 hover:text-slate-300 transition-colors">
                            <svg x-show="!showKey.gemini" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <svg x-show="showKey.gemini" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                        </button>
                    </div>
                    <p x-show="fieldErrors.gemini_key" class="text-xs text-red-400 mt-1" x-text="fieldErrors.gemini_key"></p>
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
                <div class="flex gap-2">
                    <button @click="savePlatform('gemini')" :disabled="saving.gemini"
                            class="flex-1 py-2 bg-blue-600/80 hover:bg-blue-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50 flex items-center justify-center gap-2">
                        <span x-show="saving.gemini" class="inline-block animate-spin text-sm">↻</span>
                        <span x-text="saving.gemini ? 'Saving...' : 'Save Config'"></span>
                    </button>
                    <button @click="testConnection('gemini')" :disabled="testing.gemini"
                            class="px-3 py-2 border border-blue-500/40 text-blue-400 text-sm rounded-lg hover:bg-blue-500/10 transition disabled:opacity-50"
                            title="Test connection">
                        <span x-show="!testing.gemini">⚡</span>
                        <span x-show="testing.gemini" class="inline-block animate-spin">↻</span>
                    </button>
                </div>
                <div x-show="testResult.gemini" class="text-xs rounded-lg px-3 py-2 mt-1"
                     :class="testResult.gemini?.success ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'">
                    <span x-text="(testResult.gemini?.success ? '✓ ' : '✗ ') + (testResult.gemini?.message || '') + (testResult.gemini?.latency_ms ? ' (' + testResult.gemini.latency_ms + 'ms)' : '')"></span>
                </div>
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
                            <td class="py-2.5 pr-4 font-medium text-white capitalize" x-text="agent.name"></td>
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

    {{-- ── Custom AI Platforms ──────────────────────────────────────── --}}
    <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4">Custom AI Platforms</h2>
    <div class="stat-card mb-8">
        <p class="text-xs text-slate-500 mb-4">Connect any OpenAI-compatible API endpoint (local models, Together.ai, Fireworks, etc.). On failure, agents automatically fall back to the default built-in provider.</p>

        {{-- Existing platforms list --}}
        <div x-show="customPlatforms.length > 0" class="mb-4 space-y-2">
            <template x-for="p in customPlatforms" :key="p.id">
                <div class="flex items-center justify-between px-3 py-2.5 bg-slate-800/60 border border-slate-700/50 rounded-lg">
                    <div class="flex items-center gap-3">
                        <div class="w-2 h-2 rounded-full" :class="p.configured ? 'bg-emerald-400' : 'bg-slate-600'"></div>
                        <div>
                            <p class="text-sm font-medium text-white" x-text="p.name"></p>
                            <p class="text-xs text-slate-500 font-mono" x-text="p.api_base_url + ' · ' + p.default_model"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge text-xs" :class="p.configured ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-slate-700 text-slate-400 border border-slate-600'"
                              x-text="p.configured ? 'Key Set' : 'No Key'"></span>
                        <button @click="deleteCustomPlatform(p.id)"
                                class="p-1.5 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        {{-- Add new custom platform form --}}
        <div x-show="!showAddCustomPlatform">
            <button @click="showAddCustomPlatform = true"
                    class="px-4 py-2 border border-slate-700 text-slate-400 hover:text-white hover:border-slate-500 text-sm rounded-lg transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Custom Platform
            </button>
        </div>

        <div x-show="showAddCustomPlatform" class="border border-slate-700/60 rounded-lg p-4 space-y-3">
            <p class="text-xs font-semibold text-slate-300 uppercase">New Custom Platform</p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">Platform Name <span class="text-red-400">*</span></label>
                    <input type="text" x-model="customForm.name" @input="customPlatformError = ''" placeholder="e.g. Together AI"
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                </div>
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">Website (optional)</label>
                    <input type="url" x-model="customForm.website_url" placeholder="https://together.ai"
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                </div>
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">API Base URL <span class="text-red-400">*</span></label>
                <input type="url" x-model="customForm.api_base_url" placeholder="https://api.together.xyz/v1"
                       class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">Default Model <span class="text-red-400">*</span></label>
                    <input type="text" x-model="customForm.default_model" placeholder="meta-llama/Llama-3-8b-chat-hf"
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                </div>
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">API Key Env Var <span class="text-red-400">*</span></label>
                    <input type="text" x-model="customForm.api_key_env" placeholder="TOGETHER_API_KEY"
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition font-mono">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-slate-400 mb-1 block">Auth Type</label>
                    <select x-model="customForm.auth_type"
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500">
                        <option value="bearer">Bearer Token</option>
                        <option value="x-api-key">Custom Header</option>
                    </select>
                </div>
                <div x-show="customForm.auth_type === 'x-api-key'">
                    <label class="text-xs text-slate-400 mb-1 block">Header Name</label>
                    <input type="text" x-model="customForm.auth_header" placeholder="X-API-Key"
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                </div>
            </div>
            <div class="flex gap-3">
                <button @click="showAddCustomPlatform = false; customForm = { name:'', website_url:'', api_base_url:'', default_model:'', api_key_env:'', auth_type:'bearer', auth_header:'' }"
                        class="px-3 py-2 border border-slate-700 text-slate-400 text-sm rounded-lg hover:border-slate-500 hover:text-white transition">Cancel</button>
                <button @click="addCustomPlatform()" :disabled="saving.customPlatform"
                        class="px-4 py-2 bg-brand-600/80 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50 flex items-center gap-2">
                    <span x-show="saving.customPlatform" class="inline-block animate-spin text-sm">↻</span>
                    <span x-text="saving.customPlatform ? 'Adding...' : 'Add Platform'"></span>
                </button>
            </div>
            <div x-show="customPlatformError" class="text-xs text-red-400 mt-1" x-text="customPlatformError"></div>
        </div>
    </div>

    {{-- ── Telegram Integration ─────────────────────────────────────── --}}
    <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4">Telegram Integration</h2>
    <div class="stat-card mb-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Bot Token</label>
                <div class="relative">
                    <input :type="showKey.telegram ? 'text' : 'password'" x-model="sysForm.TELEGRAM_BOT_TOKEN"
                           @input="markChanged()"
                           placeholder="1234567890:AAF..."
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 pr-9 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
                    <button type="button" @click="showKey.telegram = !showKey.telegram"
                            class="absolute right-2.5 top-2.5 text-slate-500 hover:text-slate-300 transition-colors">
                        <svg x-show="!showKey.telegram" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <svg x-show="showKey.telegram" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                    </button>
                </div>
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Admin Chat ID</label>
                <input type="text" x-model="sysForm.TELEGRAM_ADMIN_CHAT_ID"
                       @input="markChanged()"
                       placeholder="123456789"
                       class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 transition">
            </div>
        </div>
        <div class="flex gap-3 mt-4">
            <button @click="saveSysSettings()" :disabled="saving.system"
                    class="px-4 py-2 bg-brand-600/80 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50 flex items-center gap-2">
                <span x-show="saving.system" class="inline-block animate-spin text-sm">↻</span>
                <span x-text="saving.system ? 'Saving...' : 'Save Telegram Config'"></span>
            </button>
            <button @click="testConnection('telegram')" :disabled="testing.telegram"
                    class="px-3 py-2 border border-brand-500/40 text-brand-400 text-sm rounded-lg hover:bg-brand-500/10 transition disabled:opacity-50"
                    title="Test Telegram bot token">
                <span x-show="!testing.telegram">⚡ Test</span>
                <span x-show="testing.telegram" class="inline-block animate-spin">↻</span>
            </button>
            <button @click="registerWebhook()" :disabled="saving.webhook"
                    class="px-4 py-2 border border-slate-600 hover:border-slate-500 text-slate-300 text-sm font-medium rounded-lg transition disabled:opacity-50 flex items-center gap-2">
                <span x-show="saving.webhook" class="inline-block animate-spin text-sm">↻</span>
                <span x-text="saving.webhook ? 'Registering...' : 'Register Webhook'"></span>
            </button>
        </div>
        <p x-show="webhookResult" class="mt-2 text-xs text-slate-400" x-text="webhookResult"></p>
        <div x-show="testResult.telegram" class="text-xs rounded-lg px-3 py-2 mt-2"
             :class="testResult.telegram?.success ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'">
            <span x-text="(testResult.telegram?.success ? '✓ ' : '✗ ') + (testResult.telegram?.message || '') + (testResult.telegram?.latency_ms ? ' (' + testResult.telegram.latency_ms + 'ms)' : '')"></span>
        </div>
    </div>

    {{-- ── System / Application Settings ──────────────────────────── --}}
    <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4">Application Settings</h2>
    <div class="stat-card mb-8">
        <div x-show="sysWarning" class="mb-3 rounded-lg border border-orange-500/30 bg-orange-500/10 px-3 py-2 text-sm text-orange-200" x-text="sysWarning"></div>
        <div x-show="sysSuccess" class="mb-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200" x-text="sysSuccess"></div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="text-xs text-slate-400 mb-1 block">APP_URL</label>
                <input x-model="sysForm.APP_URL" @input="markChanged()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500"
                       :class="fieldErrors.app_url ? 'border-red-500' : 'border-slate-700'">
                <p x-show="fieldErrors.app_url" class="text-xs text-red-400 mt-1" x-text="fieldErrors.app_url"></p>
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">APP_ENV</label>
                <input x-model="sysForm.APP_ENV" @input="markChanged()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500">
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">QUEUE_CONNECTION</label>
                <input x-model="sysForm.QUEUE_CONNECTION" @input="markChanged()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500">
            </div>
        </div>
        <div class="stat-card bg-slate-900/60 mb-4">
            <p class="text-xs font-semibold text-slate-500 uppercase mb-3">Database Overview (read-only)</p>
            <div class="grid grid-cols-4 gap-3 text-sm text-slate-300">
                <div><div class="text-slate-500 text-xs uppercase mb-1">Connection</div><div x-text="sysForm.DB_CONNECTION || '–'"></div></div>
                <div><div class="text-slate-500 text-xs uppercase mb-1">Host</div><div x-text="sysForm.DB_HOST || '–'"></div></div>
                <div><div class="text-slate-500 text-xs uppercase mb-1">Port</div><div x-text="sysForm.DB_PORT || '–'"></div></div>
                <div><div class="text-slate-500 text-xs uppercase mb-1">Database</div><div x-text="sysForm.DB_DATABASE || '–'"></div></div>
            </div>
        </div>
        <button @click="saveSysSettings()" :disabled="saving.system"
                class="px-4 py-2 bg-brand-600/80 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition disabled:opacity-50 flex items-center gap-2">
            <span x-show="saving.system" class="inline-block animate-spin text-sm">↻</span>
            <span x-text="saving.system ? 'Saving...' : 'Save Application Settings'"></span>
        </button>
    </div>

</div>
@endsection

@section('scripts')
<script>
function settingsApp() {
    return {
        config: { openai_configured: false, anthropic_configured: false, gemini_configured: false },
        agents: [],
        forms: {
            openai:    { api_key: '', model: 'gpt-4o' },
            anthropic: { api_key: '', model: 'claude-opus-4-5' },
            gemini:    { api_key: '', model: 'gemini-2.0-flash' },
        },
        sysForm: {
            APP_URL: '', APP_ENV: '', QUEUE_CONNECTION: '', CACHE_STORE: '',
            TELEGRAM_BOT_TOKEN: '', TELEGRAM_ADMIN_CHAT_ID: '',
            DB_CONNECTION: '', DB_HOST: '', DB_PORT: '', DB_DATABASE: '',
        },
        // Snapshot of sysForm on load — used for discard
        sysFormSnapshot: {},
        hasChanges: false,

        // API key show/hide toggles
        showKey: { openai: false, anthropic: false, gemini: false, telegram: false },

        // Inline field validation errors
        fieldErrors: {
            openai_key: '', anthropic_key: '', gemini_key: '', app_url: '',
        },

        saving:     { openai: false, anthropic: false, gemini: false, system: false, webhook: false, customPlatform: false },
        testing:    { openai: false, anthropic: false, gemini: false, telegram: false },
        testResult: { openai: null, anthropic: null, gemini: null, telegram: null },
        webhookResult: '',
        sysWarning: '',
        sysSuccess: '',
        customPlatforms: [],
        showAddCustomPlatform: false,
        customPlatformError: '',
        customForm: { name: '', website_url: '', api_base_url: '', default_model: '', api_key_env: '', auth_type: 'bearer', auth_header: '' },

        async init() {
            try {
                const d = await apiGet('/agent/config');
                this.config = d;
            } catch(e) {
                console.warn('Could not load agent config:', e.message);
            }

            try {
                const d = await apiGet('/dashboard/api/pipeline');
                this.agents = (d.agents || []).map(a => ({
                    name: a.name, provider: a.provider, model: a.model,
                    queue: a.queue, max_steps: a.max_steps,
                }));
            } catch(e) {
                console.warn('Could not load agent list:', e.message);
            }

            try {
                const d = await apiGet('/dashboard/api/settings');
                this.sysForm = { ...this.sysForm, ...d };
                // Take snapshot for discard
                this.sysFormSnapshot = JSON.parse(JSON.stringify(this.sysForm));
            } catch(e) {
                this.sysWarning = 'Could not load system settings: ' + e.message;
            }

            await this.loadCustomPlatforms();

            // Auto-test configured providers so status badges reflect reality
            const autoTest = [];
            if (this.config.openai_configured)    autoTest.push('openai');
            if (this.config.anthropic_configured)  autoTest.push('anthropic');
            if (this.config.gemini_configured)     autoTest.push('gemini');
            if (this.sysForm.TELEGRAM_BOT_TOKEN && !this.sysForm.TELEGRAM_BOT_TOKEN.includes('*')) autoTest.push('telegram');
            for (const p of autoTest) { this.testConnection(p); }
        },

        markChanged() {
            this.hasChanges = true;
        },

        discardChanges() {
            this.sysForm = JSON.parse(JSON.stringify(this.sysFormSnapshot));
            this.hasChanges = false;
            showToast('Changes discarded', 'info');
        },

        validatePlatform(provider) {
            const key = this.forms[provider].api_key.trim();
            const errorField = provider + '_key';
            if (!key) {
                this.fieldErrors[errorField] = 'API key is required';
                return false;
            }
            if (provider === 'openai' && !key.startsWith('sk-')) {
                this.fieldErrors[errorField] = 'OpenAI keys must start with sk-';
                return false;
            }
            if (provider === 'anthropic' && !key.startsWith('sk-ant-')) {
                this.fieldErrors[errorField] = 'Anthropic keys must start with sk-ant-';
                return false;
            }
            this.fieldErrors[errorField] = '';
            return true;
        },

        validateSysForm() {
            let valid = true;
            if (this.sysForm.APP_URL && !this.sysForm.APP_URL.startsWith('http')) {
                this.fieldErrors.app_url = 'Must be a valid URL (http:// or https://)';
                valid = false;
            } else {
                this.fieldErrors.app_url = '';
            }
            return valid;
        },

        async loadCustomPlatforms() {
            try {
                const d = await apiGet('/dashboard/api/custom-platforms');
                this.customPlatforms = d.data || [];
            } catch(e) {
                console.warn('Could not load custom platforms:', e.message);
            }
        },

        async addCustomPlatform() {
            this.customPlatformError = '';
            if (!this.customForm.name.trim() || !this.customForm.api_base_url.trim() || !this.customForm.default_model.trim() || !this.customForm.api_key_env.trim()) {
                this.customPlatformError = 'Name, API Base URL, Default Model and API Key Env are required.';
                return;
            }
            this.saving.customPlatform = true;
            try {
                const r = await apiPost('/dashboard/api/custom-platforms', this.customForm);
                if (r.created) {
                    this.showAddCustomPlatform = false;
                    this.customForm = { name: '', website_url: '', api_base_url: '', default_model: '', api_key_env: '', auth_type: 'bearer', auth_header: '' };
                    showToast('Custom platform added.', 'success');
                    await this.loadCustomPlatforms();
                }
            } catch(e) {
                this.customPlatformError = e.message || 'Failed to add platform.';
                showToast('Failed to add platform: ' + e.message, 'error');
            }
            this.saving.customPlatform = false;
        },

        async deleteCustomPlatform(id) {
            const ok = await confirmAction(
                'Remove platform?',
                'This will remove the custom platform configuration. Agents using it will fall back to the default provider.',
                'Remove',
                'bg-red-600 hover:bg-red-500 text-white'
            );
            if (!ok) return;
            try {
                await fetch('/dashboard/api/custom-platforms/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                });
                showToast('Platform removed.', 'success');
                await this.loadCustomPlatforms();
            } catch(e) {
                showToast('Error: ' + e.message, 'error');
            }
        },

        async savePlatform(provider) {
            if (!this.validatePlatform(provider)) return;
            const form = this.forms[provider];
            this.saving[provider] = true;
            try {
                const r = await apiPost('/dashboard/api/platform', {
                    provider, api_key: form.api_key, model: form.model,
                });
                if (r.saved) {
                    this.config[provider + '_configured'] = true;
                    form.api_key = '';
                    showToast(provider.charAt(0).toUpperCase() + provider.slice(1) + ' config saved.', 'success');
                } else {
                    showToast('Failed to save settings', 'error');
                }
            } catch(e) {
                showToast('Failed to save settings: ' + e.message, 'error');
            }
            this.saving[provider] = false;
        },

        async testConnection(provider) {
            this.testing[provider] = true;
            this.testResult[provider] = null;
            try {
                const r = await apiPost('/dashboard/api/test-connection', { provider });
                this.testResult[provider] = r;
                if (r.success) {
                    this.config[provider + '_configured'] = true;
                    showToast(provider + ' connection OK', 'success');
                } else {
                    showToast(provider + ' connection failed: ' + (r.message ?? 'unknown error'), 'error');
                }
            } catch(e) {
                this.testResult[provider] = { success: false, message: e.message };
                showToast(provider + ' test error: ' + e.message, 'error');
            }
            this.testing[provider] = false;
        },

        async saveSysSettings() {
            if (!this.validateSysForm()) {
                showToast('Please fix validation errors before saving', 'warning');
                return;
            }
            this.saving.system = true;
            this.sysWarning = '';
            this.sysSuccess = '';
            try {
                const r = await apiPost('/dashboard/api/settings', this.sysForm);
                this.sysWarning = (r.warnings ?? []).join(' ');
                this.sysSuccess = 'Settings saved.';
                this.hasChanges = false;
                // Update snapshot
                this.sysFormSnapshot = JSON.parse(JSON.stringify(this.sysForm));
                showToast('Settings saved', 'success');
            } catch(e) {
                showToast('Failed to save settings', 'error');
            }
            this.saving.system = false;
        },

        async registerWebhook() {
            this.saving.webhook = true;
            try {
                const r = await apiPost('/dashboard/api/settings/telegram/webhook');
                this.webhookResult = r.output || (r.success ? 'Webhook registered.' : 'Failed.');
                showToast(r.success ? 'Webhook registered.' : 'Webhook registration failed.', r.success ? 'success' : 'error');
            } catch(e) {
                showToast('Error: ' + e.message, 'error');
            }
            this.saving.webhook = false;
        },

        // Legacy local toast — kept for compatibility, routes to global showToast
        showToast(message, error = false) {
            showToast(message, error ? 'error' : 'success');
        },
    };
}
</script>
@endsection
