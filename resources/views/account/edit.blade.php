<x-layouts.app title="Account Settings">
    <header class="border-b border-slate-800 pb-7"><p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-400">Account</p><h1 class="mt-2 text-3xl font-semibold text-white">Profile & security</h1></header>
    @if(session('success'))<div class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 p-4 text-sm text-emerald-200">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-xl border border-red-400/20 bg-red-400/10 p-4 text-sm text-red-200">{{ $errors->first() }}</div>@endif
    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('account.update') }}" class="space-y-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-6">@csrf @method('PUT')
            <h2 class="text-xl font-semibold text-white">Account details</h2>
            <label class="block text-sm text-slate-300">Name<input name="name" value="{{ old('name', $user->name) }}" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">Email<input type="email" name="email" value="{{ old('email', $user->email) }}" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <p class="text-xs text-slate-500">Changing your email requires verification again.</p>
            <button class="rounded-xl bg-sky-400 px-5 py-3 font-semibold text-slate-950">Save Account</button>
        </form>
        <form method="POST" action="{{ route('account.password.update') }}" class="space-y-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-6">@csrf @method('PUT')
            <h2 class="text-xl font-semibold text-white">Change password</h2>
            <label class="block text-sm text-slate-300">Current password<input type="password" name="current_password" required autocomplete="current-password" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">New password<input type="password" name="password" required autocomplete="new-password" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <label class="block text-sm text-slate-300">Confirm new password<input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></label>
            <button class="rounded-xl bg-emerald-400 px-5 py-3 font-semibold text-slate-950">Update Password</button>
        </form>
    </div>
</x-layouts.app>
