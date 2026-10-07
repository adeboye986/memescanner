@if($enginePositions->isNotEmpty())
    <section class="mb-8 rounded-2xl border border-cyan-400/20 bg-slate-900/60 p-5 sm:p-6">
        <div class="mb-5">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">Trading Engine PAPER</p>
            <h2 class="mt-2 text-xl font-semibold text-white">Engine-owned positions</h2>
            <p class="mt-1 text-sm text-slate-400">Financial state and lifecycle authority are held by the TypeScript engine. Legacy manual close is unavailable.</p>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach($enginePositions as $position)
                <article class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-semibold text-white">{{ $position->symbol ?: 'Solana token' }}</h3>
                            <p class="mt-1 max-w-64 truncate text-xs text-slate-500">{{ $position->asset_address }}</p>
                        </div>
                        <span class="rounded-full bg-cyan-400/10 px-2.5 py-1 text-xs font-semibold uppercase text-cyan-300">{{ $position->state }}</span>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-500">Cost basis</dt><dd class="mt-1 text-slate-200">{{ rtrim(rtrim($position->cost_basis_native, '0'), '.') }} SOL</dd></div>
                        <div><dt class="text-slate-500">Entry market cap</dt><dd class="mt-1 text-slate-200">${{ number_format((float) $position->entry_market_cap_usd, 2) }}</dd></div>
                        <div><dt class="text-slate-500">Wallet available</dt><dd class="mt-1 text-slate-200">{{ rtrim(rtrim($position->wallet->available_balance_native, '0'), '.') }} SOL</dd></div>
                        <div><dt class="text-slate-500">Wallet invested</dt><dd class="mt-1 text-slate-200">{{ rtrim(rtrim($position->wallet->invested_balance_native, '0'), '.') }} SOL</dd></div>
                    </dl>
                </article>
            @endforeach
        </div>
    </section>
@endif
