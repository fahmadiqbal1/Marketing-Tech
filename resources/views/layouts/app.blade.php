<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>@yield('title', 'Dashboard') — Autonomous Ops</title>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Space Grotesk', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50:  '#f5f3ff',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                        }
                    },
                    keyframes: {
                        'float':        { '0%,100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-6px)' } },
                        'glow-pulse':   { '0%,100%': { boxShadow: '0 0 5px #7c3aed30' }, '50%': { boxShadow: '0 0 20px #7c3aed70, 0 0 40px #7c3aed20' } },
                        'gradient-x':   { '0%,100%': { backgroundPosition: '0% 50%' }, '50%': { backgroundPosition: '100% 50%' } },
                        'slide-up':     { from: { opacity: '0', transform: 'translateY(16px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
                        'slide-in-right': { from: { opacity: '0', transform: 'translateX(16px)' }, to: { opacity: '1', transform: 'translateX(0)' } },
                        'border-glow':  { '0%,100%': { borderColor: '#7c3aed30' }, '50%': { borderColor: '#7c3aed80' } },
                        'count-up':     { from: { opacity: '0' }, to: { opacity: '1' } },
                        'shimmer':      { '0%': { backgroundPosition: '200% 0' }, '100%': { backgroundPosition: '-200% 0' } },
                    },
                    animation: {
                        'float':         'float 4s ease-in-out infinite',
                        'glow-pulse':    'glow-pulse 3s ease-in-out infinite',
                        'gradient-x':    'gradient-x 4s ease infinite',
                        'slide-up':      'slide-up 0.4s ease forwards',
                        'slide-in-right':'slide-in-right 0.3s ease forwards',
                        'border-glow':   'border-glow 2s ease-in-out infinite',
                        'shimmer':       'shimmer 1.5s infinite',
                    },
                }
            }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
    {{-- GSAP — globally loaded (80KB gzipped), deferred so it doesn't block render --}}
    <script defer src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>

    @stack('head-scripts')

    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }

        /* ── Glassmorphism cards ─────────────────────────────────── */
        .glass-card {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(148, 163, 184, 0.09);
            box-shadow: 0 4px 24px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.04);
        }

        /* ── Gradient glow border ────────────────────────────────── */
        .glow-border { position: relative; }
        .glow-border::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            background: linear-gradient(135deg, #7c3aed30, #06b6d430, #7c3aed30);
            background-size: 200% 200%;
            animation: gradient-x 4s ease infinite;
            z-index: -1;
            pointer-events: none;
        }

        /* ── 3D icon containers ──────────────────────────────────── */
        .icon-3d {
            transform-style: preserve-3d;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .icon-3d:hover {
            transform: rotateY(12deg) rotateX(-8deg) scale(1.08);
            box-shadow: 4px 8px 20px rgba(124, 58, 237, 0.3);
        }

        /* ── Card hover lift ─────────────────────────────────────── */
        .card-lift {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card-lift:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(124, 58, 237, 0.18);
        }

        /* ── Gradient text heading ───────────────────────────────── */
        .gradient-heading {
            background: linear-gradient(135deg, #a78bfa, #38bdf8, #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            background-size: 200% 200%;
            animation: gradient-x 5s ease infinite;
        }

        /* ── Sidebar nav links ───────────────────────────────────── */
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.75rem;
            border-radius: 0.5rem;
            color: #94a3b8;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.15s ease;
            position: relative;
        }
        .sidebar-link:hover {
            color: #fff;
            background: rgba(100, 116, 139, 0.25);
        }
        .sidebar-link.active {
            color: #fff;
            background: rgba(124, 58, 237, 0.15);
            border-left: 2px solid #a78bfa;
            padding-left: calc(0.75rem - 2px);
        }
        .sidebar-link.active .sidebar-icon {
            color: #a78bfa;
            filter: drop-shadow(0 0 6px #7c3aed80);
        }

        /* ── Stat cards (legacy alias → glass-card) ──────────────── */
        .stat-card {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(148, 163, 184, 0.09);
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(124, 58, 237, 0.15);
        }

        /* ── Badges ──────────────────────────────────────────────── */
        .badge { display: inline-flex; align-items: center; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }

        /* ── Scrollbar ───────────────────────────────────────────── */
        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 2px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }

        /* ── Skeleton shimmer ────────────────────────────────────── */
        .skeleton {
            background: linear-gradient(90deg, #1e293b 25%, #334155 50%, #1e293b 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 4px;
        }
        .skeleton-row { height: 1rem; border-radius: 4px; }

        /* ── Animated status ring ────────────────────────────────── */
        .pulse-dot { animation: pulse-dot 2s infinite; }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); box-shadow: 0 0 0 0 rgba(52,211,153,0.4); }
            50%       { opacity: .7; transform: scale(1.2); box-shadow: 0 0 0 4px rgba(52,211,153,0); }
        }

        /* ── Buttons ─────────────────────────────────────────────── */
        .btn-primary {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: #fff;
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid rgba(167,139,250,0.2);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #6d28d9, #5b21b6);
            box-shadow: 0 4px 16px rgba(124,58,237,0.4);
            transform: translateY(-1px);
        }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* ── Form inputs ─────────────────────────────────────────── */
        .form-input {
            background: rgba(30,41,59,0.8);
            border: 1px solid rgba(100,116,139,0.4);
            color: #e2e8f0;
            font-size: 0.875rem;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            outline: none;
            width: 100%;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .form-input::placeholder { color: #64748b; }
        .form-input:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124,58,237,0.15);
        }

        /* ── Animated page entrance ──────────────────────────────── */
        .page-enter { animation: slide-up 0.35s ease forwards; }
    </style>
</head>

<body class="h-full bg-slate-950 text-slate-100 flex" x-data="{ mobileOpen: false }" @keydown.escape.window="mobileOpen = false">

{{-- ── Mobile overlay backdrop ────────────────────────────────── --}}
<div x-show="mobileOpen"
     x-transition:enter="transition-opacity ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="mobileOpen = false"
     class="fixed inset-0 z-20 bg-black/70 backdrop-blur-sm md:hidden"
     x-cloak></div>

{{-- ── Sidebar ──────────────────────────────────────────────────── --}}
<aside class="w-60 min-h-screen bg-slate-900 border-r border-slate-800 flex flex-col flex-shrink-0 fixed inset-y-0 left-0 z-30
              md:translate-x-0 transition-transform duration-200 ease-out"
       :class="mobileOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
       x-cloak>

    {{-- Logo --}}
    <div class="px-5 py-5 border-b border-slate-800/60">
        <div class="flex items-center gap-3">
            <div class="icon-3d w-9 h-9 rounded-xl flex items-center justify-center shadow-lg flex-shrink-0"
                 style="background:linear-gradient(135deg,#7c3aed,#4f46e5);box-shadow:0 0 20px rgba(124,58,237,0.4);">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <div>
                <p class="font-semibold text-sm leading-tight text-white" style="font-family:'Space Grotesk',sans-serif;">Autonomous Ops</p>
                <p class="text-xs" style="color:#a78bfa;opacity:0.8;">Marketing Platform</p>
            </div>
        </div>
    </div>

    {{-- Nav --}}
    <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
        <p class="px-3 py-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Overview</p>

        <a href="/dashboard" class="sidebar-link {{ request()->is('dashboard') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Overview
        </a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Operations</p>

        <a href="/dashboard/pipeline" class="sidebar-link {{ request()->is('dashboard/pipeline') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
            Agent Pipeline
        </a>
        <a href="/dashboard/workflows" class="sidebar-link {{ request()->is('dashboard/workflows') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            Workflows
        </a>
        <a href="/dashboard/jobs" class="sidebar-link {{ request()->is('dashboard/jobs') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
            Agent Jobs
        </a>
        <a href="/dashboard/knowledge" class="sidebar-link {{ request()->is('dashboard/knowledge') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
            Knowledge / RAG
        </a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Business</p>

        <a href="/dashboard/campaigns" class="sidebar-link {{ request()->is('dashboard/campaigns') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
            Campaigns
        </a>
        <a href="/dashboard/candidates" class="sidebar-link {{ request()->is('dashboard/candidates') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Candidates
        </a>
        <a href="/dashboard/content" class="sidebar-link {{ request()->is('dashboard/content') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Content
        </a>
        <a href="/dashboard/social" class="sidebar-link {{ request()->is('dashboard/social*') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
            Social
        </a>

        <p class="px-3 pt-3 pb-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">System</p>

        <a href="/dashboard/intelligence" class="sidebar-link {{ request()->is('dashboard/intelligence') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
            Intelligence
        </a>
        <a href="/dashboard/system" class="sidebar-link {{ request()->is('dashboard/system') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            System Events
        </a>
        <a href="/dashboard/settings" class="sidebar-link {{ request()->is('dashboard/settings') ? 'active' : '' }}">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <a href="/horizon" target="_blank" class="sidebar-link">
            <svg class="w-4 h-4 flex-shrink-0 sidebar-icon transition-all duration-150" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Horizon Queue
            <svg class="w-3 h-3 ml-auto text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        </a>
    </nav>

    {{-- Footer --}}
    <div class="px-4 py-3 border-t border-slate-800/60" style="background:rgba(15,23,42,0.4);">
        <div class="flex items-center gap-2">
            <span id="api-health-dot" class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot flex-shrink-0"></span>
            <span class="text-xs text-slate-400" id="api-health-label">API connected</span>
        </div>
        <p class="text-xs mt-0.5" style="color:#334155;font-size:10px;">Degrades gracefully on service outage</p>
    </div>
</aside>

{{-- ── Main ─────────────────────────────────────────────────────── --}}
<div class="md:ml-60 flex-1 flex flex-col min-h-screen w-full">

    {{-- Topbar --}}
    <header class="sticky top-0 z-20 bg-slate-950/80 backdrop-blur border-b border-slate-800/60 px-4 md:px-6 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            {{-- Mobile hamburger --}}
            <button @click="mobileOpen = !mobileOpen"
                    class="md:hidden p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                    aria-label="Toggle menu">
                <svg x-show="!mobileOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                <svg x-show="mobileOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" x-cloak><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <h1 class="text-base font-semibold text-white">@yield('title', 'Overview')</h1>
                <p class="text-xs text-slate-500 hidden sm:block">@yield('subtitle', 'Autonomous Business Operations Platform')</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-500" id="last-updated">–</span>
            <div class="h-4 w-px bg-slate-700"></div>
            {{-- Business name (session auth) --}}
            @auth
                <span class="text-xs text-slate-400 hidden sm:inline">
                    {{ auth()->user()->business?->name ?? auth()->user()->name }}
                </span>
                <div class="h-4 w-px bg-slate-700"></div>
            @endauth
            <a href="/dashboard/settings" class="text-xs text-slate-400 hover:text-white flex items-center gap-1.5 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Configure
            </a>
            @auth
                <form method="POST" action="/logout" class="inline">
                    @csrf
                    <button type="submit" class="text-xs text-slate-400 hover:text-red-400 transition-colors flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Sign out
                    </button>
                </form>
            @endauth
            <a href="/dashboard/workflows?status=owner_approval" id="approval-bell" class="relative text-slate-400 hover:text-white transition-colors hidden">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span id="approval-count" class="absolute -top-1 -right-1 w-4 h-4 bg-orange-500 rounded-full text-xs text-white flex items-center justify-center font-bold hidden"></span>
            </a>
        </div>
    </header>

    {{-- Page content --}}
    <main class="flex-1 px-4 md:px-6 py-6">
        <div class="page-enter">
            @yield('content')
        </div>
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

    async function apiPut(url, data = {}) {
        const response = await fetch(url, {
            method: 'PUT',
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

    async function apiPatch(url, data = {}) {
        const response = await fetch(url, {
            method: 'PATCH',
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

    async function apiDelete(url) {
        const response = await fetch(url, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
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

    // ── Global API health state ─────────────────────────────────────────
    let _apiHealthy = true;

    function _setApiHealth(healthy) {
        if (_apiHealthy === healthy) return;
        _apiHealthy = healthy;
        const dot   = document.getElementById('api-health-dot');
        const label = document.getElementById('api-health-label');
        if (!dot || !label) return;
        if (healthy) {
            dot.classList.remove('bg-orange-400');
            dot.classList.add('bg-emerald-400');
            label.textContent = 'API connected';
        } else {
            dot.classList.remove('bg-emerald-400');
            dot.classList.add('bg-orange-400');
            label.textContent = 'API degraded';
        }
    }

    document.addEventListener('api:error', () => _setApiHealth(false));
    document.addEventListener('api:ok',    () => _setApiHealth(true));

    // ── Toast Notification System ───────────────────────────────────────
    function showToast(message, type = 'success', duration = 4000) {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const palettes = {
            success: { bg: 'bg-emerald-500/20', border: 'border-emerald-500/40', text: 'text-emerald-300', icon: '✓', iconBg: 'bg-emerald-500/30' },
            error:   { bg: 'bg-red-500/20',     border: 'border-red-500/40',     text: 'text-red-300',     icon: '✗', iconBg: 'bg-red-500/30'     },
            warning: { bg: 'bg-amber-500/20',   border: 'border-amber-500/40',   text: 'text-amber-300',   icon: '⚠', iconBg: 'bg-amber-500/30'   },
            info:    { bg: 'bg-sky-500/20',      border: 'border-sky-500/40',     text: 'text-sky-300',     icon: 'ℹ', iconBg: 'bg-sky-500/30'      },
        };
        const p = palettes[type] ?? palettes.info;

        const toast = document.createElement('div');
        toast.className = [
            'pointer-events-auto flex items-start gap-3 px-4 py-3 rounded-xl border shadow-xl',
            'backdrop-blur-sm text-sm transition-transform duration-300 ease-out',
            p.bg, p.border,
        ].join(' ');
        toast.style.transform = 'translateX(110%)';

        toast.innerHTML = `
            <span class="flex-shrink-0 w-6 h-6 rounded-full ${p.iconBg} ${p.text} flex items-center justify-center text-xs font-bold mt-0.5">${p.icon}</span>
            <span class="flex-1 text-slate-200 leading-snug pt-0.5">${message}</span>
            <button class="flex-shrink-0 text-slate-500 hover:text-slate-300 transition-colors text-base leading-none mt-0.5" aria-label="Close">&times;</button>
        `;

        const closeBtn = toast.querySelector('button');
        const dismiss = () => {
            toast.style.transform = 'translateX(110%)';
            setTimeout(() => toast.remove(), 320);
        };
        closeBtn.addEventListener('click', dismiss);

        container.appendChild(toast);
        // Animate in on next frame
        requestAnimationFrame(() => {
            requestAnimationFrame(() => { toast.style.transform = 'translateX(0)'; });
        });

        if (duration > 0) setTimeout(dismiss, duration);
    }

    // ── Global Confirmation Modal ───────────────────────────────────────
    function confirmAction(title, body, okLabel = 'Confirm', okClass = 'bg-red-600 hover:bg-red-500') {
        return new Promise((resolve) => {
            const modal    = document.getElementById('confirm-modal');
            const titleEl  = document.getElementById('confirm-title');
            const bodyEl   = document.getElementById('confirm-body');
            const okBtn    = document.getElementById('confirm-ok');
            const cancelBtn = document.getElementById('confirm-cancel');
            const backdrop = document.getElementById('confirm-backdrop');

            if (!modal) { resolve(false); return; }

            titleEl.textContent  = title;
            bodyEl.textContent   = body;
            okBtn.textContent    = okLabel;

            // Reset and apply ok button classes
            okBtn.className = 'px-4 py-2 text-sm rounded-lg text-white transition-colors font-medium ' + okClass;

            modal.classList.remove('hidden');

            const cleanup = (result) => {
                modal.classList.add('hidden');
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                backdrop.removeEventListener('click', onCancel);
                resolve(result);
            };

            const onOk     = () => cleanup(true);
            const onCancel = () => cleanup(false);

            okBtn.addEventListener('click',     onOk);
            cancelBtn.addEventListener('click', onCancel);
            backdrop.addEventListener('click',  onCancel);
        });
    }

    // ── Approval Bell Polling ───────────────────────────────────────────
    function pollApprovalBell() {
        const bell  = document.getElementById('approval-bell');
        const badge = document.getElementById('approval-count');
        if (!bell || !badge) return;

        const refresh = () => {
            fetch('/dashboard/api/stats', { headers: { 'Accept': 'application/json' } })
                .then(r => r.ok ? r.json() : Promise.reject(r))
                .then(data => {
                    document.dispatchEvent(new Event('api:ok'));
                    const count = data?.pending_approvals ?? data?.owner_approval_count ?? 0;
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.classList.remove('hidden');
                        bell.classList.remove('hidden');
                    } else {
                        badge.classList.add('hidden');
                        bell.classList.add('hidden');
                    }
                })
                .catch(() => {
                    document.dispatchEvent(new Event('api:error'));
                });
        };

        refresh();
        setInterval(refresh, 30000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        pollApprovalBell();
    });
</script>

@yield('scripts')
@stack('scripts')

{{-- ── Global Toast Container ─────────────────────────────────────── --}}
<div id="toast-container" class="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none" style="min-width:280px;max-width:360px;"></div>

{{-- ── Global Confirmation Modal ──────────────────────────────────── --}}
<div id="confirm-modal" class="hidden fixed inset-0 z-[90] flex items-center justify-center">
    <div id="confirm-backdrop" class="fixed inset-0 bg-black/70 backdrop-blur-sm"></div>
    <div class="relative z-10 bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl p-6 w-full max-w-sm mx-4">
        <h3 id="confirm-title" class="text-base font-semibold text-white mb-2"></h3>
        <p id="confirm-body" class="text-sm text-slate-400 mb-5"></p>
        <div class="flex justify-end gap-3">
            <button id="confirm-cancel" class="px-4 py-2 text-sm rounded-lg border border-slate-600 text-slate-400 hover:text-white hover:border-slate-500 transition-colors">Cancel</button>
            <button id="confirm-ok" class="px-4 py-2 text-sm rounded-lg bg-red-600 text-white hover:bg-red-500 transition-colors font-medium">Confirm</button>
        </div>
    </div>
</div>
</body>
</html>
