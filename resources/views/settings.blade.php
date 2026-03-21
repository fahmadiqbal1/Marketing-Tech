@extends('layouts.app')
@section('title', 'Settings')
@section('subtitle', 'Environment-backed settings and masked secret status')

@section('content')
<div x-data="settingsApp()" x-init="init()" x-cloak class="space-y-4 max-w-4xl">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>
    <div x-show="success" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200" x-text="success"></div>

    <form @submit.prevent="save()" class="space-y-4">
        <div class="grid md:grid-cols-2 gap-4">
            <div class="stat-card space-y-3">
                <h3 class="font-semibold">Application</h3>
                <label class="block text-sm">APP_URL<input x-model="form.APP_URL" class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm"></label>
                <label class="block text-sm">APP_ENV<input x-model="form.APP_ENV" class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm"></label>
                <label class="block text-sm">QUEUE_CONNECTION<input x-model="form.QUEUE_CONNECTION" class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm"></label>
                <label class="block text-sm">CACHE_STORE<input x-model="form.CACHE_STORE" class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm"></label>
            </div>
            <div class="stat-card space-y-3">
                <h3 class="font-semibold">Integrations</h3>
                <label class="block text-sm">OpenAI key<input x-model="form.OPENAI_API_KEY" class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm"></label>
                <label class="block text-sm">Anthropic key<input x-model="form.ANTHROPIC_API_KEY" class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm"></label>
                <label class="block text-sm">Telegram token<input x-model="form.TELEGRAM_BOT_TOKEN" class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm"></label>
                <label class="block text-sm">Webhook secret<input x-model="form.TELEGRAM_WEBHOOK_SECRET" class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm"></label>
            </div>
        </div>
        <div class="stat-card space-y-3">
            <h3 class="font-semibold">Database overview</h3>
            <div class="grid md:grid-cols-4 gap-3 text-sm text-slate-300">
                <div><div class="text-slate-500 text-xs uppercase">Connection</div><div x-text="form.DB_CONNECTION"></div></div>
                <div><div class="text-slate-500 text-xs uppercase">Host</div><div x-text="form.DB_HOST"></div></div>
                <div><div class="text-slate-500 text-xs uppercase">Port</div><div x-text="form.DB_PORT"></div></div>
                <div><div class="text-slate-500 text-xs uppercase">Database</div><div x-text="form.DB_DATABASE"></div></div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button class="rounded-lg bg-brand-600 hover:bg-brand-500 px-4 py-2 text-sm font-medium">Save settings</button>
            <button type="button" @click="registerWebhook()" class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800">Register Telegram webhook</button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
function settingsApp() {
    return {
        ...dashboardState(),
        success: '',
        form: {
            APP_URL: '', APP_ENV: '', QUEUE_CONNECTION: '', CACHE_STORE: '',
            OPENAI_API_KEY: '', ANTHROPIC_API_KEY: '', TELEGRAM_BOT_TOKEN: '', TELEGRAM_WEBHOOK_SECRET: '',
            DB_CONNECTION: '', DB_HOST: '', DB_PORT: '', DB_DATABASE: '', DB_USERNAME: ''
        },
        async init() { await this.load(); },
        async load() {
            this.clearMessages();
            this.success = '';
            try {
                const data = await apiGet('/dashboard/api/settings');
                this.form = { ...this.form, ...data };
                updateTimestamp();
            } catch (error) { this.handleError(error); }
        },
        async save() {
            this.clearMessages();
            this.success = '';
            try {
                const result = await apiPost('/dashboard/api/settings', this.form);
                this.warning = (result.warnings ?? []).join(' ');
                this.success = 'Settings saved.';
                await this.load();
            } catch (error) { this.handleError(error); }
        },
        async registerWebhook() {
            this.clearMessages();
            this.success = '';
            try {
                const result = await apiPost('/dashboard/api/settings/telegram/webhook');
                this.success = result.output || 'Webhook registration completed.';
            } catch (error) { this.handleError(error); }
        }
    }
}
</script>
@endsection
