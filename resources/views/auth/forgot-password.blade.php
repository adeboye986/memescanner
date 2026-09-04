<x-layouts.app title="Forgot Password">
    <div class="mx-auto w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/80 p-7">
        <h1 class="text-2xl font-semibold text-white">Reset your password</h1>
        <p class="mt-2 text-sm text-slate-400">Enter your email and we will send a secure reset link if the account exists.</p>
        @if(session('success'))<div class="mt-5 rounded-xl border border-emerald-400/20 bg-emerald-400/10 p-3 text-sm text-emerald-200">{{ session('success') }}</div>@endif
        @error('email')<div class="mt-5 rounded-xl border border-red-400/20 bg-red-400/10 p-3 text-sm text-red-200">{{ $message }}</div>@enderror
        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">@csrf
            <label class="block text-sm text-slate-300">Email<input type="email" name="email" value="{{ old('email') }}" required autofocus class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <button class="w-full rounded-xl bg-emerald-400 px-4 py-3 font-semibold text-slate-950">Send Reset Link</button>
        </form>
    </div>
</x-layouts.app>
