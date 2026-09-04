<x-layouts.app title="Verify Email">
    <div class="mx-auto w-full max-w-lg rounded-2xl border border-amber-400/20 bg-slate-900/80 p-7">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-300">One step remaining</p>
        <h1 class="mt-2 text-2xl font-semibold text-white">Verify your email</h1>
        <p class="mt-3 text-sm leading-6 text-slate-300">Check your inbox for a verification link. You can explore onboarding now, but Telegram connections, scans, approvals, and AUTO mode require verification.</p>
        @if(session('success'))<div class="mt-5 rounded-xl border border-emerald-400/20 bg-emerald-400/10 p-3 text-sm text-emerald-200">{{ session('success') }}</div>@endif
        <div class="mt-6 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('verification.send') }}">@csrf<button class="rounded-xl bg-amber-300 px-5 py-3 font-semibold text-slate-950">Resend Email</button></form>
            <a href="{{ route('onboarding') }}" class="rounded-xl border border-slate-700 px-5 py-3 font-semibold text-slate-200">Continue Setup</a>
        </div>
    </div>
</x-layouts.app>
