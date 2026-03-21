@extends('layouts.app')
@section('title', 'System Events')
@section('subtitle', 'Recent platform events, warnings, and errors')

@section('content')
<div x-data="systemApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    <div class="stat-card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-xs uppercase border-b border-slate-700/60">
                <tr>
                    <th class="text-left py-3">Level</th>
                    <th class="text-left py-3">Event</th>
                    <th class="text-left py-3">Source</th>
                    <th class="text-left py-3">Message</th>
                    <th class="text-left py-3">When</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="!events.length"><tr><td colspan="5" class="py-8 text-center text-slate-500">No system events available.</td></tr></template>
                <template x-for="event in events" :key="event.id">
                    <tr class="border-b border-slate-800/60 align-top">
                        <td class="py-3"><span class="badge" :class="statusBadge(event.level)" x-text="event.level"></span></td>
                        <td class="py-3 text-slate-300" x-text="event.event"></td>
                        <td class="py-3 text-slate-400 text-xs" x-text="event.source || 'app'"></td>
                        <td class="py-3 text-slate-400" x-text="event.message"></td>
                        <td class="py-3 text-slate-400 text-xs" x-text="relativeTime(event.created_at)"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
@endsection

@section('scripts')
<script>
function systemApp() {
    return {
        ...dashboardState(),
        events: [],
        async init() { await this.load(); },
        async load() {
            this.clearMessages();
            try {
                const data = this.applyMeta(await apiGet('/dashboard/api/system-events'));
                this.events = data.data ?? [];
                updateTimestamp();
            } catch (error) { this.handleError(error); }
        },
        statusBadge, relativeTime,
    }
}
</script>
@endsection
