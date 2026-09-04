<x-layouts.app title="Reset Password">
    <div class="mx-auto w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/80 p-7">
        <h1 class="text-2xl font-semibold text-white">Choose a new password</h1>
        @if($errors->any())<div class="mt-5 rounded-xl border border-red-400/20 bg-red-400/10 p-3 text-sm text-red-200">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">@csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="block text-sm text-slate-300">Email<input type="email" name="email" value="{{ old('email', $email) }}" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">New password<input type="password" name="password" required autocomplete="new-password" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <button class="w-full rounded-xl bg-emerald-400 px-4 py-3 font-semibold text-slate-950">Reset Password</button>
        </form>
    </div>
</x-layouts.app>
