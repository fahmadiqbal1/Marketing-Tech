<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Marketing Tech</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <style>
        body { background: #020617; }
        .input-field { @apply w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 transition text-sm; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 flex items-center justify-center px-4">

    <div class="w-full max-w-md">
        {{-- Logo / Brand --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-violet-600/20 border border-violet-500/30 mb-4">
                <svg class="w-7 h-7 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white">Marketing Tech</h1>
            <p class="text-slate-500 text-sm mt-1">AI-powered marketing platform</p>
        </div>

        {{-- Card --}}
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl">
            <h2 class="text-lg font-semibold text-white mb-6">Sign in to your account</h2>

            @if ($errors->any())
                <div class="mb-4 bg-red-500/10 border border-red-500/30 rounded-xl px-4 py-3">
                    <p class="text-red-400 text-sm">{{ $errors->first() }}</p>
                </div>
            @endif

            @if (session('status'))
                <div class="mb-4 bg-emerald-500/10 border border-emerald-500/30 rounded-xl px-4 py-3">
                    <p class="text-emerald-400 text-sm">{{ session('status') }}</p>
                </div>
            @endif

            <form method="POST" action="/login" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Email address</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 transition text-sm"
                           placeholder="you@company.com">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Password</label>
                    <input type="password" name="password" required
                           class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 transition text-sm"
                           placeholder="••••••••">
                </div>
                <div class="flex items-center justify-between pt-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="remember" class="w-4 h-4 rounded border-slate-600 bg-slate-800 text-violet-600 focus:ring-violet-500">
                        <span class="text-sm text-slate-400">Remember me</span>
                    </label>
                </div>
                <button type="submit"
                        class="w-full bg-violet-600 hover:bg-violet-500 text-white font-medium py-3 rounded-xl transition text-sm mt-2">
                    Sign in
                </button>
            </form>

            <p class="text-center text-sm text-slate-500 mt-6">
                New business?
                <a href="/register" class="text-violet-400 hover:text-violet-300 transition">Create an account</a>
            </p>
        </div>
    </div>

</body>
</html>
