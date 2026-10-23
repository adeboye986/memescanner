<section class="mb-8 rounded-2xl border border-cyan-400/20 bg-slate-900/60 p-5 sm:p-6">
    <div class="mb-5">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">TypeScript Trading Engine · Simulated PAPER</p>
        <h2 class="mt-2 text-xl font-semibold text-white">Engine-owned PAPER portfolio</h2>
        <p class="mt-1 text-sm text-slate-400">These projections are separate from legacy Laravel wallets and positions. They do not represent LIVE assets, and legacy manual-close controls do not apply.</p>
    </div>

    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <h3 class="font-semibold text-white">Engine-owned PAPER wallets</h3>
            <span class="text-xs text-slate-500">Laravel read-only projections</span>
        </div>
        <div class="mt-3 grid gap-3 md:grid-cols-2">
            @forelse($engineWallets as $wallet)
                <article class="rounded-xl border border-cyan-400/15 bg-slate-950/60 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <strong class="text-slate-100">{{ $wallet->network_id }}</strong>
                        <span class="rounded-full bg-cyan-400/10 px-2.5 py-1 text-xs font-semibold uppercase text-cyan-300">{{ $wallet->currency }}</span>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div><dt class="text-slate-500">Opening</dt><dd class="mt-1 text-slate-200">{{ rtrim(rtrim($wallet->opening_balance_native, '0'), '.') ?: '0' }}</dd></div>
                        <div><dt class="text-slate-500">Available</dt><dd class="mt-1 text-emerald-300">{{ rtrim(rtrim($wallet->available_balance_native, '0'), '.') ?: '0' }}</dd></div>
                        <div><dt class="text-slate-500">Invested</dt><dd class="mt-1 text-amber-300">{{ rtrim(rtrim($wallet->invested_balance_native, '0'), '.') ?: '0' }}</dd></div>
                        <div><dt class="text-slate-500">Realized P&amp;L</dt><dd class="mt-1 text-slate-200">{{ rtrim(rtrim($wallet->realized_pnl_native, '0'), '.') ?: '0' }}</dd></div>
                    </dl>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-700 px-4 py-5 text-sm text-slate-500 md:col-span-2">No engine-owned PAPER wallet projection exists for this user.</p>
            @endforelse
        </div>
    </div>

    @foreach([
        ['title' => 'Open engine-owned PAPER positions', 'positions' => $engineOpenPositions, 'empty' => 'No open engine-owned PAPER positions.'],
        ['title' => 'Closed engine-owned PAPER positions', 'positions' => $engineClosedPositions, 'empty' => 'No closed engine-owned PAPER positions.'],
    ] as $group)
        <div class="mt-6 border-t border-slate-800 pt-5">
            <h3 class="font-semibold text-white">{{ $group['title'] }}</h3>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                @forelse($group['positions'] as $position)
                    <article class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h4 class="font-semibold text-white">{{ $position->symbol ?: 'Solana token' }}</h4>
                                <p class="mt-1 max-w-64 truncate text-xs text-slate-500" title="{{ $position->asset_address }}">{{ $position->asset_address }}</p>
                            </div>
                            <span class="rounded-full bg-cyan-400/10 px-2.5 py-1 text-xs font-semibold uppercase text-cyan-300">{{ $position->lifecycleState?->state ?? $position->state }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-slate-500">Cost basis</dt><dd class="mt-1 text-slate-200">{{ rtrim(rtrim($position->cost_basis_native, '0'), '.') ?: '0' }} SOL</dd></div>
                            <div><dt class="text-slate-500">Entry market cap</dt><dd class="mt-1 text-slate-200">${{ number_format((float) $position->entry_market_cap_usd, 2) }}</dd></div>
                            <div><dt class="text-slate-500">Last market cap</dt><dd class="mt-1 text-slate-200">{{ $position->lifecycleState?->last_market_cap_usd !== null ? '$'.number_format((float) $position->lifecycleState->last_market_cap_usd, 2) : 'Awaiting authoritative observation' }}</dd></div>
                            <div><dt class="text-slate-500">Protection</dt><dd class="mt-1 uppercase text-slate-200">{{ str_replace('_', ' ', $position->lifecycleState?->protection_state ?? 'none') }}</dd></div>
                            <div><dt class="text-slate-500">Exit proceeds</dt><dd class="mt-1 text-slate-200">{{ $position->exitSettlement ? (rtrim(rtrim($position->exitSettlement->proceeds_native, '0'), '.') ?: '0').' SOL' : 'Not settled' }}</dd></div>
                            <div><dt class="text-slate-500">Realized P&amp;L</dt><dd class="mt-1 text-slate-200">{{ $position->exitSettlement ? (rtrim(rtrim($position->exitSettlement->realized_pnl_native, '0'), '.') ?: '0').' SOL' : 'Not settled' }}</dd></div>
                        </dl>
                    </article>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-700 px-4 py-5 text-sm text-slate-500 lg:col-span-2">{{ $group['empty'] }}</p>
                @endforelse
            </div>
        </div>
    @endforeach
</section>
