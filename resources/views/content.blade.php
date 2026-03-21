@extends('layouts.app')
@section('title', 'Content')
@section('subtitle', 'Drafts, scheduled content, and published output')

@section('content')
<div x-data="contentApp()" x-init="init()" x-cloak class="space-y-4">
    <div x-show="warning" class="rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-200" x-text="warning"></div>
    <div x-show="error" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="error"></div>

    <div class="stat-card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-xs uppercase border-b border-slate-700/60">
                <tr>
                    <th class="text-left py-3">Title</th>
                    <th class="text-left py-3">Type</th>
                    <th class="text-left py-3">Status</th>
                    <th class="text-left py-3">Platform</th>
                    <th class="text-left py-3">Words</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="!items.length"><tr><td colspan="5" class="py-8 text-center text-slate-500">No content items available.</td></tr></template>
                <template x-for="item in items" :key="item.id">
                    <tr class="border-b border-slate-800/60">
                        <td class="py-3"><div class="font-medium" x-text="item.title"></div><div class="text-xs text-slate-500" x-text="relativeTime(item.created_at)"></div></td>
                        <td class="py-3 text-slate-400" x-text="item.type"></td>
                        <td class="py-3"><span class="badge" :class="statusBadge(item.status)" x-text="item.status"></span></td>
                        <td class="py-3 text-slate-400" x-text="item.platform || '–'"></td>
                        <td class="py-3 text-slate-400" x-text="item.word_count ?? 0"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
@endsection

@section('scripts')
<script>
function contentApp() {
    return {
        ...dashboardState(),
        items: [],
        async init() { await this.load(); },
        async load() {
            this.clearMessages();
            try {
                const data = this.applyMeta(await apiGet('/dashboard/api/content'));
                this.items = data.data ?? [];
                updateTimestamp();
            } catch (error) { this.handleError(error); }
        },
        statusBadge, relativeTime,
    }
}
</script>
@endsection
