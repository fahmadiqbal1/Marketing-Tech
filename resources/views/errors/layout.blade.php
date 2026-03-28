<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>@yield('code') — Autonomous Ops</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={theme:{extend:{fontFamily:{sans:['Inter','sans-serif']},colors:{brand:{400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed'}}}}}</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet"/>
    <style>
        body{font-family:'Inter',sans-serif;background:#020617;}
        .gradient-heading{background:linear-gradient(135deg,#a78bfa,#38bdf8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
        .glass-card{background:rgba(15,23,42,0.7);backdrop-filter:blur(16px);border:1px solid rgba(148,163,184,0.1);box-shadow:0 4px 24px rgba(0,0,0,0.4),inset 0 1px 0 rgba(255,255,255,0.04);}
        @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
        .float{animation:float 4s ease-in-out infinite;}
        @keyframes glow{0%,100%{box-shadow:0 0 5px #7c3aed30}50%{box-shadow:0 0 30px #7c3aed60,0 0 60px #7c3aed20}}
        .glow{animation:glow 3s ease-in-out infinite;}
        @keyframes gradient-x{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
    </style>
</head>
<body class="h-full text-slate-100 flex items-center justify-center min-h-screen p-4">

    {{-- Animated background gradient --}}
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -left-40 w-96 h-96 rounded-full opacity-20"
             style="background:radial-gradient(circle,#7c3aed,transparent);animation:float 6s ease-in-out infinite;"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 rounded-full opacity-15"
             style="background:radial-gradient(circle,#0ea5e9,transparent);animation:float 8s ease-in-out infinite reverse;"></div>
    </div>

    <div class="relative z-10 text-center max-w-lg w-full">
        {{-- Error code badge --}}
        <div class="inline-flex items-center justify-center w-24 h-24 rounded-3xl mb-8 float glow"
             style="background:linear-gradient(135deg,#7c3aed20,#0ea5e920);border:1px solid #7c3aed40;">
            <span class="gradient-heading text-5xl font-bold" style="font-family:'Space Grotesk',sans-serif;">@yield('code')</span>
        </div>

        <div class="glass-card rounded-2xl p-8 mx-auto">
            <h1 class="text-2xl font-bold text-white mb-3" style="font-family:'Space Grotesk',sans-serif;">@yield('title')</h1>
            <p class="text-slate-400 text-sm leading-relaxed mb-6">@yield('message')</p>

            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="/dashboard"
                   class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium text-white transition-all duration-200"
                   style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    Back to Dashboard
                </a>
                <button onclick="history.back()"
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium text-slate-300 hover:text-white border border-slate-700 hover:border-slate-500 transition-all duration-200">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Go Back
                </button>
            </div>
        </div>

        <p class="mt-6 text-xs text-slate-600">Autonomous Ops Platform · If this persists, check system logs</p>
    </div>
</body>
</html>
