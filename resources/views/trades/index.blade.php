<x-layouts.app title="Trade History">
    <header class="flex flex-col gap-3 border-b border-slate-800 pb-7">
        <p class="text-sm font-semibold uppercase tracking-[0.24em] text-emerald-400">Meme Scanner</p>
        <h1 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">Trade History & Performance</h1>
        <p class="max-w-3xl text-sm leading-6 text-slate-400">Stored results from funded paper positions. No live market APIs are used on this page.</p>
    </header>

    <section aria-labelledby="performance-heading" class="flex flex-col gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Closed Funded Positions</p>
            <h2 id="performance-heading" class="mt-1 text-xl font-semibold text-white">Performance Summary</h2>
        </div>

        @php
            $performanceCards = [
                ['Total Trades', $performance['total_trades'], 'neutral'],
                ['Wins', $performance['wins'], 'profit'],
                ['Losses', $performance['losses'], 'loss'],
                ['Win Rate', $performance['win_rate'] !== null ? number_format($performance['win_rate'], 1).'%' : 'N/A', 'neutral'],
                ['Total Realized P/L', sprintf('%+.4f SOL', $performance['total_pnl']), $performance['total_pnl'] >= 0 ? 'profit' : 'loss'],
                ['Average P/L', $performance['average_pnl'] !== null ? sprintf('%+.4f SOL', $performance['average_pnl']) : 'N/A', ($performance['average_pnl'] ?? 0) >= 0 ? 'profit' : 'loss'],
            ];
        @endphp

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            @foreach ($performanceCards as [$label, $value, $tone])
                <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $label }}</p>
                    <p @class([
                        'mt-3 text-xl font-semibold tabular-nums',
                        'text-white' => $tone === 'neutral',
                        'text-emerald-300' => $tone === 'profit',
                        'text-red-300' => $tone === 'loss',
                    ])>{{ $value }}</p>
                </article>
            @endforeach
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach (['Best Trade' => $performance['best_trade'], 'Worst Trade' => $performance['worst_trade']] as $label => $trade)
                <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $label }}</p>
                    @if ($trade)
                        <div class="mt-3 flex flex-wrap items-baseline justify-between gap-3">
                            <span class="text-lg font-semibold text-white">{{ $trade['symbol'] }}</span>
                            <span class="font-semibold tabular-nums {{ $trade['pnl'] >= 0 ? 'text-emerald-300' : 'text-red-300' }}">
                                {{ sprintf('%+.4f SOL', $trade['pnl']) }}
                                @if ($trade['return_percent'] !== null)
                                    <span class="ml-2 text-sm">{{ sprintf('%+.1f%%', $trade['return_percent']) }}</span>
                                @endif
                            </span>
                        </div>
                    @else
                        <p class="mt-3 text-slate-500">N/A</p>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[1fr_2fr]">
        <section aria-labelledby="exit-breakdown-heading" class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
            <h2 id="exit-breakdown-heading" class="text-lg font-semibold text-white">Exit Breakdown</h2>
            <div class="mt-4 flex flex-col divide-y divide-slate-800">
                @foreach ($performance['exit_breakdown'] as $label => $count)
                    <div class="flex items-center justify-between gap-4 py-3 text-sm">
                        <span class="text-slate-400">{{ $label }}</span>
                        <span class="font-semibold tabular-nums text-white">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="filters-heading" class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
            <h2 id="filters-heading" class="text-lg font-semibold text-white">Filters</h2>
            <form method="GET" action="{{ route('trades.index') }}" class="mt-4 grid gap-4 sm:grid-cols-4">
                <label class="flex flex-col gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Status
                    <select name="status" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm font-medium normal-case tracking-normal text-slate-200 focus:border-emerald-400 focus:outline-none">
                        @foreach (['all' => 'All', 'open' => 'Open', 'closed' => 'Closed'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Result
                    <select name="result" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm font-medium normal-case tracking-normal text-slate-200 focus:border-emerald-400 focus:outline-none">
                        @foreach (['all' => 'All', 'wins' => 'Wins', 'losses' => 'Losses', 'break-even' => 'Break-even'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['result'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Chain
                    <select name="chain" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm font-medium normal-case tracking-normal text-slate-200 focus:border-emerald-400 focus:outline-none">
                        @foreach (['all' => 'All', 'solana' => 'Solana', 'ethereum' => 'Ethereum'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['chain'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Exit Type
                    <select name="exit_type" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm font-medium normal-case tracking-normal text-slate-200 focus:border-emerald-400 focus:outline-none">
                        @foreach (['all' => 'All', 'manual' => 'Manual', 'stop-loss' => 'Stop Loss', 'full-target' => 'Full Target', 'protected-floor' => 'Protected Floor', 'other' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['exit_type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex gap-3 sm:col-span-4">
                    <button type="submit" class="rounded-lg bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-emerald-400">Apply Filters</button>
                    <a href="{{ route('trades.index') }}" class="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:bg-slate-800">Reset</a>
                </div>
            </form>
        </section>
    </div>

    <section aria-labelledby="history-heading" class="flex flex-col gap-4">
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Ledger</p>
                <h2 id="history-heading" class="mt-1 text-xl font-semibold text-white">Trade History</h2>
            </div>
            <span class="text-sm text-slate-500">{{ $trades->total() }} funded trades</span>
        </div>

        @forelse ($trades as $trade)
            @php
                $position = $trade['model'];
                $pnl = (float) $position->trade_pnl_sol;
            @endphp
            <article class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/70 shadow-xl shadow-black/10">
                <div class="flex flex-col gap-4 border-b border-slate-800 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-lg font-semibold text-white">{{ $position->symbol ?: 'Unknown token' }}</h3>
                            <span class="rounded-full bg-violet-400/10 px-2.5 py-1 text-xs font-semibold uppercase text-violet-300">{{ $position->chain->label() }}</span>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase {{ $position->status === 'closed' ? 'bg-slate-700 text-slate-200' : 'bg-emerald-400/10 text-emerald-300' }}">{{ $position->status }}</span>
                            <span @class([
                                'rounded-full px-2.5 py-1 text-xs font-semibold',
                                'bg-blue-400/10 text-blue-300' => $trade['exit_filter'] === 'manual',
                                'bg-red-400/10 text-red-300' => $trade['exit_filter'] === 'stop-loss',
                                'bg-emerald-400/10 text-emerald-300' => $trade['exit_filter'] === 'full-target',
                                'bg-amber-400/10 text-amber-300' => $trade['exit_filter'] === 'protected-floor',
                                'bg-slate-800 text-slate-400' => $trade['exit_filter'] === 'other',
                            ])>{{ $trade['exit_reason'] }}</span>
                        </div>
                        <p class="mt-2 truncate font-mono text-xs text-slate-500" title="{{ $position->address }}">{{ $position->address }}</p>
                    </div>
                    <div class="text-left sm:text-right">
                        <p class="text-xs uppercase tracking-wider text-slate-500">Realized P/L</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums {{ $pnl > 0 ? 'text-emerald-300' : ($pnl < 0 ? 'text-red-300' : 'text-slate-300') }}">{{ sprintf('%+.4f SOL', $pnl) }}</p>
                        <p class="mt-1 text-sm tabular-nums text-slate-400">{{ $trade['return_percent'] !== null ? sprintf('%+.2f%%', $trade['return_percent']) : 'N/A' }}</p>
                    </div>
                </div>

                <dl class="grid gap-px bg-slate-800 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                    @php
                        $metrics = [
                            ['Initial Investment', number_format((float) $position->initial_investment_sol, 4).' SOL'],
                            ['Entry Market Cap', '$'.number_format((float) $position->entry_market_cap, 2)],
                            ['Final / Exit Market Cap', $trade['final_market_cap'] !== null ? '$'.number_format($trade['final_market_cap'], 2) : 'N/A'],
                            ['Entry Time', $position->entry_at?->format('M j, Y H:i:s') ?? 'N/A'],
                            ['Closed Time', $position->closed_at?->format('M j, Y H:i:s') ?? 'N/A'],
                            ['Duration', $trade['duration'] ?? 'N/A'],
                            ['Peak Multiple', (float) $position->peak_multiple > 0 ? number_format((float) $position->peak_multiple, 2).'x' : 'N/A'],
                            ['Highest Profit', $trade['highest_profit_percent'] !== null ? sprintf('%+.2f%%', $trade['highest_profit_percent']) : 'N/A'],
                            ['Exit Mode', $trade['exit_mode']],
                        ];
                    @endphp
                    @foreach ($metrics as [$label, $value])
                        <div class="bg-slate-900/90 px-5 py-4">
                            <dt class="text-xs uppercase tracking-wider text-slate-500">{{ $label }}</dt>
                            <dd class="mt-2 font-semibold text-slate-200">{{ $value }}</dd>
                        </div>
                    @endforeach
                    @if ($position->status === 'open')
                        <div class="flex items-center bg-slate-900/90 px-5 py-4 sm:col-span-2 lg:col-span-3">
                            <a href="{{ route('dashboard') }}#positions-heading" class="text-sm font-semibold text-emerald-300 transition hover:text-emerald-200">View on Dashboard →</a>
                        </div>
                    @endif
                </dl>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/40 px-6 py-16 text-center">
                <h3 class="text-lg font-semibold text-white">No funded trades match these filters</h3>
                <p class="mt-2 text-sm text-slate-400">Adjust the filters to see other paper positions.</p>
            </div>
        @endforelse

        @if ($trades->hasPages())
            <div class="mt-2">{{ $trades->links() }}</div>
        @endif
    </section>
</x-layouts.app>
