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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

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
            50% { opacity: .5; transform: scale(1.3); }
        }
    </style>
</head>

<body class="h-full bg-slate-950 text-slate-100 flex">
<aside class="w-60 min-h-screen bg-slate-900 border-r border-slate-800 flex flex-col flex-shrink-0 fixed inset-y-0 left-0 z-30">
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

    <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
        <p class="px-3 py-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Overview</p>
        <a href="/dashboard" class="sidebar-link {{ request()->is('dashboard') ? 'active' : '' }}">Overview</a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Operations</p>
        <a href="/dashboard/workflows" class="sidebar-link {{ request()->is('dashboard/workflows') ? 'active' : '' }}">Workflows</a>
        <a href="/dashboard/jobs" class="sidebar-link {{ request()->is('dashboard/jobs') ? 'active' : '' }}">Agent Jobs</a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Business</p>
        <a href="/dashboard/campaigns" class="sidebar-link {{ request()->is('dashboard/campaigns') ? 'active' : '' }}">Campaigns</a>
        <a href="/dashboard/candidates" class="sidebar-link {{ request()->is('dashboard/candidates') ? 'active' : '' }}">Candidates</a>
        <a href="/dashboard/content" class="sidebar-link {{ request()->is('dashboard/content') ? 'active' : '' }}">Content</a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">System</p>
        <a href="/dashboard/system" class="sidebar-link {{ request()->is('dashboard/system') ? 'active' : '' }}">System Events</a>
        <a href="/dashboard/settings" class="sidebar-link {{ request()->is('dashboard/settings') ? 'active' : '' }}">Settings</a>
        <a href="/horizon" target="_blank" class="sidebar-link">Horizon Queue</a>
    </nav>

    <div class="px-4 py-3 border-t border-slate-800">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot"></span>
            <span class="text-xs text-slate-400">Dashboard shell online</span>
        </div>
        <p class="text-xs text-slate-600 mt-0.5">Degrades gracefully when services are unavailable.</p>
    </div>
</aside>

<div class="ml-60 flex-1 flex flex-col min-h-screen">
    <header class="sticky top-0 z-20 bg-slate-950/80 backdrop-blur border-b border-slate-800/60 px-6 py-3 flex items-center justify-between">
        <div>
            <h1 class="text-base font-semibold text-white">@yield('title', 'Overview')</h1>
            <p class="text-xs text-slate-500">@yield('subtitle', 'Autonomous Business Operations Platform')</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-500" id="last-updated">–</span>
            <div class="h-4 w-px bg-slate-700"></div>
            <a href="/dashboard/settings" class="text-xs text-slate-400 hover:text-white transition-colors">Configure</a>
        </div>
    </header>

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
            completed: 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
            failed: 'bg-red-500/20 text-red-400 border border-red-500/30',
            cancelled: 'bg-slate-500/20 text-slate-400 border border-slate-500/30',
            owner_approval: 'bg-orange-500/20 text-orange-400 border border-orange-500/30',
            pending: 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30',
            running: 'bg-blue-500/20 text-blue-400 border border-blue-500/30',
            draft: 'bg-slate-500/20 text-slate-400 border border-slate-500/30',
            ready: 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30',
            published: 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
            active: 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
            paused: 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30',
            error: 'bg-red-500/20 text-red-400 border border-red-500/30',
            warning: 'bg-orange-500/20 text-orange-400 border border-orange-500/30',
            info: 'bg-blue-500/20 text-blue-400 border border-blue-500/30',
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
