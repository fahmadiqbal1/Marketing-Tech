<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>@yield('title', 'Dashboard') — Autonomous Ops</title>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#f5f3ff',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                        }
                    }
                }
            }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>

    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
        .sidebar-link { @apply flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-700/60 transition-all duration-150 text-sm font-medium; }
        .sidebar-link.active { @apply text-white bg-brand-600/30 border border-brand-500/30; }
        .stat-card { @apply bg-slate-800/60 border border-slate-700/50 rounded-xl p-5 backdrop-blur-sm; }
        .badge { @apply inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold; }
        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: #1e293b; }
        ::-webkit-scrollbar-thumb { background: #475569; border-radius: 2px; }
        .pulse-dot { animation: pulse-dot 2s infinite; }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: .5; transform: scale(1.3); }
        }
    </style>
</head>

<body class="h-full bg-slate-950 text-slate-100 flex">

{{-- ── Sidebar ──────────────────────────────────────────────────── --}}
<aside class="w-60 min-h-screen bg-slate-900 border-r border-slate-800 flex flex-col flex-shrink-0 fixed inset-y-0 left-0 z-30">

    {{-- Logo --}}
    <div class="px-5 py-5 border-b border-slate-800">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-brand-500 to-violet-700 flex items-center justify-center shadow-lg">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <div>
                <p class="text-white font-semibold text-sm leading-tight">Autonomous Ops</p>
                <p class="text-slate-500 text-xs">Marketing Platform</p>
            </div>
        </div>
    </div>

    {{-- Nav --}}
    <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
        <p class="px-3 py-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Overview</p>

        <a href="/dashboard" class="sidebar-link {{ request()->is('dashboard') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Overview
        </a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Operations</p>

        <a href="/dashboard/pipeline" class="sidebar-link {{ request()->is('dashboard/pipeline') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
            Agent Pipeline
        </a>
        <a href="/dashboard/workflows" class="sidebar-link {{ request()->is('dashboard/workflows') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            Workflows
        </a>
        <a href="/dashboard/jobs" class="sidebar-link {{ request()->is('dashboard/jobs') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
            Agent Jobs
        </a>
        <a href="/dashboard/knowledge" class="sidebar-link {{ request()->is('dashboard/knowledge') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
            Knowledge / RAG
        </a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Business</p>

        <a href="/dashboard/campaigns" class="sidebar-link {{ request()->is('dashboard/campaigns') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
            Campaigns
        </a>
        <a href="/dashboard/candidates" class="sidebar-link {{ request()->is('dashboard/candidates') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Candidates
        </a>
        <a href="/dashboard/content" class="sidebar-link {{ request()->is('dashboard/content') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Content
        </a>
        <a href="/dashboard/social" class="sidebar-link {{ request()->is('dashboard/social*') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
            Social
        </a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">System</p>

        <a href="/dashboard/system" class="sidebar-link {{ request()->is('dashboard/system') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            System Events
        </a>
        <a href="/dashboard/settings" class="sidebar-link {{ request()->is('dashboard/settings') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <a href="/horizon" target="_blank" class="sidebar-link">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Horizon Queue
            <svg class="w-3 h-3 ml-auto text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        </a>
    </nav>

    {{-- Footer --}}
    <div class="px-4 py-3 border-t border-slate-800">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot"></span>
            <span class="text-xs text-slate-400">Dashboard shell online</span>
        </div>
        <p class="text-xs text-slate-600 mt-0.5">Degrades gracefully when services are unavailable.</p>
    </div>
</aside>

{{-- ── Main ─────────────────────────────────────────────────────── --}}
<div class="ml-60 flex-1 flex flex-col min-h-screen">

    {{-- Topbar --}}
    <header class="sticky top-0 z-20 bg-slate-950/80 backdrop-blur border-b border-slate-800/60 px-6 py-3 flex items-center justify-between">
        <div>
            <h1 class="text-base font-semibold text-white">@yield('title', 'Overview')</h1>
            <p class="text-xs text-slate-500">@yield('subtitle', 'Autonomous Business Operations Platform')</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-500" id="last-updated">–</span>
            <div class="h-4 w-px bg-slate-700"></div>
            <a href="/dashboard/settings" class="text-xs text-slate-400 hover:text-white flex items-center gap-1.5 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Configure
            </a>
        </div>
    </header>

    {{-- Page content --}}
    <main class="flex-1 px-6 py-6">
        @yield('content')
    </main>
</div>

<script>
    function updateTimestamp() {
        const el = document.getElementById('last-updated');
        if (el) el.textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    function statusBadge(status) {
        const map = {
            completed:      'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
            failed:         'bg-red-500/20 text-red-400 border border-red-500/30',
            cancelled:      'bg-slate-500/20 text-slate-400 border border-slate-500/30',
            owner_approval: 'bg-orange-500/20 text-orange-400 border border-orange-500/30',
            pending:        'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30',
            running:        'bg-blue-500/20 text-blue-400 border border-blue-500/30',
            draft:          'bg-slate-500/20 text-slate-400 border border-slate-500/30',
            ready:          'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30',
            published:      'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
            active:         'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
            paused:         'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30',
            error:          'bg-red-500/20 text-red-400 border border-red-500/30',
            warning:        'bg-orange-500/20 text-orange-400 border border-orange-500/30',
            info:           'bg-blue-500/20 text-blue-400 border border-blue-500/30',
        };
        ['intake','context_retrieval','planning','task_execution','review','execution','observation','learning'].forEach(s => {
            map[s] = 'bg-violet-500/20 text-violet-400 border border-violet-500/30';
        });
        return map[status] ?? 'bg-slate-500/20 text-slate-400 border border-slate-500/30';
    }

    function relativeTime(dateStr) {
        if (!dateStr) return '–';
        const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
        if (Number.isNaN(diff)) return dateStr;
        if (diff < 60) return diff + 's ago';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    async function apiGet(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const text = await response.text();
        let data = {};
        try {
            data = text ? JSON.parse(text) : {};
        } catch {
            throw new Error(text || `Request failed with status ${response.status}`);
        }
        if (!response.ok) {
            throw new Error(data.message || `Request failed with status ${response.status}`);
        }
        return data;
    }

    async function apiPost(url, data = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(data),
        });
        const text = await response.text();
        const payload = text ? JSON.parse(text) : {};
        if (!response.ok) {
            throw new Error(payload.message || `Request failed with status ${response.status}`);
        }
        return payload;
    }

    function dashboardState() {
        return {
            warning: '',
            error: '',
            applyMeta(data) {
                this.warning = data?.meta?.warning ?? '';
                return data;
            },
            handleError(error) {
                this.error = error?.message ?? 'Something went wrong while loading data.';
                console.error(error);
            },
            clearMessages() {
                this.warning = '';
                this.error = '';
            }
        }
    }
</script>

@yield('scripts')
</body>
</html>
