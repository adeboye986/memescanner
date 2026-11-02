@php
    $performance = $trading['performance'];
    $performanceCards = [
        ['Settled Trades', number_format($performance['total_trades']), 'neutral'],
        ['Wins', number_format($performance['wins']), 'positive'],
        ['Losses', number_format($performance['losses']), 'negative'],
        ['Break-even', number_format($performance['break_even']), 'neutral'],
        ['Win Rate', $performance['win_rate'] === null ? '—' : number_format($performance['win_rate'], 1).'%', 'neutral'],
        ['Average P/L', $performance['average_pnl_metric']['value'], $performance['average_pnl_metric']['tone']],
    ];
@endphp

<section class="grid gap-3" aria-labelledby="performance-heading">
    <div class="rounded-lg border border-slate-700/80 bg-slate-900/80 shadow-lg shadow-black/10">
        <div class="border-b border-slate-800 px-4 py-3">
            <h2 id="performance-heading" class="text-sm font-semibold text-white">Performance</h2>
            <p class="mt-0.5 text-[10px] text-slate-500">{{ $trading['source_label'] }} · stored settled outcomes only</p>
        </div>

        <div class="grid grid-cols-2 gap-px bg-slate-800/80 lg:grid-cols-3">
            @foreach($performanceCards as [$label, $value, $tone])
                <article class="bg-slate-900/95 px-4 py-3.5">
                    <p class="text-[10px] text-slate-500">{{ $label }}</p>
                    <p @class([
                        'mt-1.5 truncate text-lg font-semibold tabular-nums',
                        'text-emerald-300' => $tone === 'positive',
                        'text-red-300' => $tone === 'negative',
                        'text-white' => $tone === 'neutral',
                    ]) @if($value === '—') aria-label="Unavailable" @endif>{{ $value }}</p>
                </article>
            @endforeach
        </div>
    </div>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-lg border border-slate-700/80 bg-slate-900/80 p-4 shadow-lg shadow-black/10" aria-labelledby="source-ledgers-heading">
            <div class="flex items-center justify-between gap-3">
                <h3 id="source-ledgers-heading" class="text-xs font-semibold text-white">Source Ledgers</h3>
                <span class="text-[9px] text-slate-500">Never combined across currencies</span>
            </div>
            <div class="mt-3 grid gap-2">
                @forelse($trading['wallets'] as $wallet)
                    <article class="grid grid-cols-2 gap-2 rounded-md border border-slate-800 bg-slate-950/25 p-3 sm:grid-cols-4">
                        <div>
                            <p class="text-[9px] text-slate-500">{{ $wallet['network'] }}</p>
                            <p class="mt-0.5 text-[11px] font-semibold text-slate-200">{{ $wallet['currency'] }}</p>
                        </div>
                        @foreach([
                            ['Available', $wallet['available']],
                            ['Invested', $wallet['invested']],
                            ['Realized P/L', $wallet['realized_pnl']],
                        ] as [$label, $amount])
                            <div>
                                <p class="text-[9px] text-slate-500">{{ $label }}</p>
                                <p class="mt-0.5 truncate text-[11px] font-semibold tabular-nums text-slate-200">{{ number_format($amount, 4) }} {{ $wallet['currency'] }}</p>
                            </div>
                        @endforeach
                    </article>
                @empty
                    <div class="rounded-md border border-dashed border-slate-700 px-4 py-7 text-center text-xs text-slate-500">No wallet ledger has been reported for this source.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-slate-700/80 bg-slate-900/80 p-4 shadow-lg shadow-black/10" aria-labelledby="outcomes-heading">
            <h3 id="outcomes-heading" class="text-xs font-semibold text-white">Recorded Outcomes</h3>
            <div class="mt-3 grid grid-cols-2 gap-2">
                @foreach(['Best Trade' => $performance['best_trade'], 'Worst Trade' => $performance['worst_trade']] as $label => $trade)
                    <article class="rounded-md border border-slate-800 bg-slate-950/25 p-3">
                        <p class="text-[9px] text-slate-500">{{ $label }}</p>
                        @if($trade)
                            <p class="mt-1.5 truncate text-xs font-semibold text-white">{{ $trade['symbol'] }}</p>
                            <p @class([
                                'mt-0.5 text-[11px] font-semibold tabular-nums',
                                'text-emerald-300' => $trade['pnl'] > 0,
                                'text-red-300' => $trade['pnl'] < 0,
                                'text-slate-300' => $trade['pnl'] == 0,
                            ])>{{ sprintf('%+.4f %s', $trade['pnl'], $trade['currency']) }}</p>
                        @else
                            <p class="mt-2 text-xs text-slate-500" aria-label="Unavailable">—</p>
                        @endif
                    </article>
                @endforeach
            </div>

            @if(is_array($performance['exit_breakdown']))
                <div class="mt-3 border-t border-slate-800 pt-3">
                    <p class="text-[9px] font-medium uppercase tracking-wide text-slate-500">Exit reasons</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @forelse($performance['exit_breakdown'] as $label => $count)
                            <span class="rounded border border-slate-800 bg-slate-950/25 px-2 py-1 text-[9px] text-slate-400">{{ $label }} · {{ $count }}</span>
                        @empty
                            <span class="text-[10px] text-slate-500">No exits reported.</span>
                        @endforelse
                    </div>
                </div>
            @else
                <p class="mt-3 border-t border-slate-800 pt-3 text-[10px] leading-4 text-slate-500">Exit-reason categories are not part of the current engine settlement projection, so no breakdown is inferred.</p>
            @endif
        </section>
    </div>
</section>
