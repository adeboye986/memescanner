<x-layouts.app title="Sign In">
    <div class="mx-auto w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/80 p-7 shadow-2xl">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-400">Welcome back</p>
        <h1 class="mt-2 text-2xl font-semibold text-white">Sign in to your account</h1>
        <p class="mt-2 text-sm text-slate-400">Access your paper trading workspace and Telegram setup.</p>
        @if(session('success'))<div class="mt-5 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div>@endif
        @if ($errors->any())
            <div class="mt-5 rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
            @csrf
            <label class="block text-sm text-slate-300">Email<input type="email" name="email" value="{{ old('email') }}" required autofocus class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">Password<input type="password" name="password" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <button class="w-full rounded-xl bg-emerald-400 px-4 py-3 font-semibold text-slate-950 hover:bg-emerald-300">Sign In</button>
        </form>
        <div class="mt-5 flex justify-between text-sm"><a href="{{ route('password.request') }}" class="text-slate-400 hover:text-white">Forgot password?</a><a href="{{ route('register') }}" class="font-semibold text-emerald-300">Create account</a></div>
    </div>
</x-layouts.app>
