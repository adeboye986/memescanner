<x-layouts.app title="Create Account">
    <div class="mx-auto w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/80 p-7 shadow-2xl">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-400">Paper Trading</p>
        <h1 class="mt-2 text-2xl font-semibold text-white">Create your account</h1>
        <p class="mt-2 text-sm text-slate-400">Start with isolated SOL and ETH paper wallets. Live trading remains disabled.</p>
        @if ($errors->any())
            <div class="mt-5 rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-4">
            @csrf
            <label class="block text-sm text-slate-300">Name<input name="name" value="{{ old('name') }}" required autofocus autocomplete="name" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">Email<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">Password<input type="password" name="password" required autocomplete="new-password" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <button class="w-full rounded-xl bg-emerald-400 px-4 py-3 font-semibold text-slate-950 hover:bg-emerald-300">Create Account</button>
        </form>
        <p class="mt-5 text-center text-sm text-slate-400">Already registered? <a href="{{ route('login') }}" class="font-semibold text-emerald-300">Sign in</a></p>
    </div>
</x-layouts.app>
