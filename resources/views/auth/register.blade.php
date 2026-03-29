<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Marketing Tech</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <style>body { background: #020617; }</style>
</head>
<body class="min-h-screen bg-slate-950 flex items-center justify-center px-4 py-10">

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-violet-600/20 border border-violet-500/30 mb-4">
                <svg class="w-7 h-7 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white">Get started</h1>
            <p class="text-slate-500 text-sm mt-1">Create your business account</p>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl">
            <h2 class="text-lg font-semibold text-white mb-6">Create account</h2>

            @if ($errors->any())
                <div class="mb-4 bg-red-500/10 border border-red-500/30 rounded-xl px-4 py-3">
                    @foreach ($errors->all() as $error)
                        <p class="text-red-400 text-sm">{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="/register" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Business name</label>
                    <input type="text" name="business_name" value="{{ old('business_name') }}" required
                           class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 transition text-sm"
                           placeholder="Acme Corp">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Your name</label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                           class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 transition text-sm"
                           placeholder="Jane Smith">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Email address</label>
                    <input type="email" name="email" value="{{ old('email') }}" required
                           class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 transition text-sm"
                           placeholder="jane@acme.com">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Password</label>
                    <input type="password" name="password" required minlength="8"
                           class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 transition text-sm"
                           placeholder="At least 8 characters">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Confirm password</label>
                    <input type="password" name="password_confirmation" required
                           class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 transition text-sm"
                           placeholder="Repeat password">
                </div>
                <button type="submit"
                        class="w-full bg-violet-600 hover:bg-violet-500 text-white font-medium py-3 rounded-xl transition text-sm mt-2">
                    Create account
                </button>
            </form>

            <p class="text-center text-sm text-slate-500 mt-6">
                Already have an account?
                <a href="/login" class="text-violet-400 hover:text-violet-300 transition">Sign in</a>
            </p>
        </div>
    </div>

</body>
</html>
