<x-layouts.app title="Telegram Bot">
    <header class="border-b border-slate-800 pb-7">
        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-400">Personal Integration</p>
        <h1 class="mt-2 text-3xl font-semibold text-white">Telegram Bot</h1>
        <p class="mt-2 text-sm text-slate-400">Connect the BotFather bot owned by your platform account.</p>
    </header>

    @if (session('success')) <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200">{{ session('error') }}</div> @endif
    @if ($errors->any()) <div class="rounded-xl border border-red-400/20 bg-red-400/10 p-4 text-sm text-red-200"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

    <section class="grid gap-6 lg:grid-cols-[0.8fr_1.2fr]">
        <div class="rounded-3xl border border-slate-800 bg-slate-900/70 p-6">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Setup</p>
            <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm text-slate-300">
                <li>Open @BotFather in Telegram.</li><li>Run /newbot and create your bot.</li><li>Paste its token and username here.</li><li>Connect, then link your Telegram account.</li>
            </ol>
            <div class="mt-6 rounded-2xl border border-slate-700 bg-slate-950/60 p-4 text-sm">
                <p>Status: <strong class="{{ $bot?->enabled && $bot?->webhook_configured_at ? 'text-emerald-300' : 'text-amber-300' }}">{{ $bot?->enabled && $bot?->webhook_configured_at ? 'Webhook active' : ($bot ? 'Disconnected' : 'Not connected') }}</strong></p>
                @if($bot)<p class="mt-2 text-slate-400">@{{ $bot->bot_username }} · {{ $bot->display_name }}</p>@endif
                @if($bot?->identity)<p class="mt-2 text-emerald-300">Account linked{{ $bot->identity->telegram_username ? ' to @'.$bot->identity->telegram_username : '' }} · ID ••••{{ substr($bot->identity->telegram_user_id, -4) }}</p>@endif
            </div>
        </div>

        <div class="rounded-3xl border border-sky-400/20 bg-slate-900/70 p-6">
            <form method="POST" action="{{ route('telegram.connect') }}" class="space-y-4">@csrf @method('PUT')
                <x-settings.secret name="bot_token" label="Bot Token" :masked="$bot ? '••••••••••••••••'.substr($bot->telegram_bot_id, -4) : null" />
                <x-settings.input name="bot_username" label="Bot Username" :value="$bot?->bot_username" />
                <p class="text-xs text-slate-500">Leave the token blank to keep the encrypted token already stored.</p>
                <button class="rounded-xl bg-sky-400 px-5 py-3 text-sm font-bold text-slate-950">{{ $bot ? 'Update Bot' : 'Connect Telegram Bot' }}</button>
            </form>
            @if($bot)
                <div class="mt-6 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('telegram.test') }}">@csrf<button class="rounded-xl border border-slate-700 px-4 py-2 text-sm">Test Bot</button></form>
                    @if($bot->identity)<form method="POST" action="{{ route('telegram.unlink') }}">@csrf @method('DELETE')<button class="rounded-xl border border-amber-400/30 px-4 py-2 text-sm text-amber-300">Unlink Account</button></form>@else<form method="POST" action="{{ route('telegram.link') }}">@csrf<button class="rounded-xl border border-emerald-400/30 px-4 py-2 text-sm text-emerald-300">Link Telegram Account</button></form>@endif
                    @if($bot->enabled)<form method="POST" action="{{ route('telegram.disconnect') }}">@csrf @method('DELETE')<button class="rounded-xl border border-red-400/30 px-4 py-2 text-sm text-red-300">Disconnect Bot</button></form>@endif
                </div>
            @endif
            @if(session('telegram_link_url'))<a href="{{ session('telegram_link_url') }}" target="_blank" rel="noopener noreferrer" class="mt-5 block rounded-xl bg-emerald-400 px-4 py-3 text-center text-sm font-bold text-slate-950">Open Telegram to finish linking</a>@endif
        </div>
    </section>
</x-layouts.app>
