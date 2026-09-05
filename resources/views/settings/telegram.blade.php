<x-dynamic-component :component="auth()->user()->is_admin ? 'layouts.admin' : 'layouts.app'" title="Telegram">
    <header class="border-b border-slate-800 pb-7">
        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-400">Telegram Integration</p>
        <h1 class="mt-2 text-3xl font-semibold text-white">Connect Telegram</h1>
        <p class="mt-2 text-sm text-slate-400">Use the platform's official bot. You do not need to create or manage your own Telegram bot.</p>
    </header>

    @if (session('success')) <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200">{{ session('error') }}</div> @endif

    <section class="grid gap-6 lg:grid-cols-[0.85fr_1.15fr]">
        <div class="rounded-3xl border border-slate-800 bg-slate-900/70 p-6">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">How it works</p>
            <ol class="mt-4 list-decimal space-y-3 pl-5 text-sm text-slate-300">
                <li>Click <strong class="text-white">Connect Telegram</strong>.</li>
                <li>Telegram opens a private chat with the official platform bot.</li>
                <li>Press <strong class="text-white">Start</strong> to securely link your Telegram account.</li>
                <li>Your alerts, scans, positions and controls remain tied only to your platform account.</li>
            </ol>
            <p class="mt-5 text-xs leading-5 text-slate-500">Other customers use the same bot, but private chats and platform data remain isolated by Telegram identity.</p>
        </div>

        <div class="rounded-3xl border border-sky-400/20 bg-slate-900/70 p-6">
            <div class="rounded-2xl border border-slate-700 bg-slate-950/60 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Official Bot</p>
                        <p class="mt-2 text-lg font-semibold text-white">{{ $botUsername ? '@'.$botUsername : 'Not configured' }}</p>
                    </div>
                    <span class="rounded-full px-3 py-1.5 text-xs font-bold {{ $platformBotAvailable ? 'bg-emerald-400/10 text-emerald-300' : 'bg-amber-400/10 text-amber-300' }}">{{ $platformBotAvailable ? 'ONLINE' : 'UNAVAILABLE' }}</span>
                </div>

                @if($identity)
                    <div class="mt-5 border-t border-slate-800 pt-5">
                        <p class="text-sm font-semibold text-emerald-300">✓ Telegram account linked</p>
                        <p class="mt-2 text-sm text-slate-400">{{ $identity->telegram_username ? '@'.$identity->telegram_username : ($identity->display_name ?: 'Telegram user') }} · ID ••••{{ substr($identity->telegram_user_id, -4) }}</p>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                @if($identity)
                    <form method="POST" action="{{ route('telegram.unlink') }}">@csrf @method('DELETE')<button class="rounded-xl border border-amber-400/30 px-5 py-3 text-sm font-semibold text-amber-300">Unlink Telegram</button></form>
                @elseif($platformBotAvailable)
                    <form method="POST" action="{{ route('telegram.link') }}">@csrf<button class="rounded-xl bg-sky-400 px-5 py-3 text-sm font-bold text-slate-950">Connect Telegram</button></form>
                @else
                    <p class="text-sm text-amber-300">The official Telegram bot is temporarily unavailable. Please try again later.</p>
                @endif
            </div>

            @if(session('telegram_link_url'))
                <a href="{{ session('telegram_link_url') }}" target="_blank" rel="noopener noreferrer" class="mt-5 block rounded-xl bg-emerald-400 px-4 py-3 text-center text-sm font-bold text-slate-950">Open Telegram and finish linking</a>
                <p class="mt-2 text-center text-xs text-slate-500">This secure link expires in 10 minutes and can only be used once.</p>
            @endif
        </div>
    </section>
</x-dynamic-component>
