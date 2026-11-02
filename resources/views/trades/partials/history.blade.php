@php($history = $trading['history'])

<section class="overflow-hidden rounded-lg border border-slate-700/80 bg-slate-900/80 shadow-lg shadow-black/10" aria-labelledby="trade-history-heading">
    <div class="flex items-center justify-between border-b border-slate-800 px-4 py-3">
        <div>
            <h2 id="trade-history-heading" class="text-sm font-semibold text-white">Trade History</h2>
            <p class="mt-0.5 text-[10px] text-slate-500">{{ $trading['source_label'] }} · stored financial records</p>
        </div>
        <span class="text-[10px] tabular-nums text-slate-500">{{ number_format($history->total()) }} total</span>
    </div>

    @if($history->isNotEmpty())
        <div class="hidden overflow-x-auto md:block" data-trading-history-table>
            <table class="w-full min-w-[56rem] text-left">
                <thead class="border-b border-slate-800 bg-slate-950/30 text-[10px] font-medium text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Token</th>
                        <th class="px-3 py-2.5 font-medium">Status / Exit</th>
                        <th class="px-3 py-2.5 text-right font-medium">Cost Basis</th>
                        <th class="px-3 py-2.5 text-right font-medium">Entry Price</th>
                        <th class="px-3 py-2.5 text-right font-medium">Exit Price</th>
                        <th class="px-3 py-2.5 text-right font-medium">Realized P/L</th>
                        <th class="px-4 py-2.5 text-right font-medium">Settled</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/90">
                    @foreach($history as $trade)
                        <tr class="transition hover:bg-slate-800/35">
                            <td class="px-4 py-3">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full border border-slate-700 bg-slate-800 text-[10px] font-bold text-slate-200">{{ mb_strtoupper(mb_substr($trade['symbol'], 0, 1)) }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-xs font-semibold text-white">{{ $trade['symbol'] }}</p>
                                        <p class="truncate text-[10px] text-slate-500" title="{{ $trade['address'] }}">{{ $trade['network'] }} · {{ str($trade['address'])->limit(18) }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <p class="text-[10px] font-medium text-slate-300">{{ $trade['exit_reason'] }}</p>
                                <span @class([
                                    'mt-1 inline-flex rounded px-1.5 py-0.5 text-[9px] font-semibold',
                                    'bg-emerald-400/10 text-emerald-300' => $trade['result'] === 'Win',
                                    'bg-red-400/10 text-red-300' => $trade['result'] === 'Loss',
                                    'bg-slate-800 text-slate-400' => $trade['result'] === 'Break-even',
                                ])>{{ str($trade['status'])->headline() }} · {{ $trade['result'] }}</span>
                            </td>
                            <td class="px-3 py-3 text-right text-[11px] tabular-nums text-slate-300">{{ number_format($trade['cost_basis'], 4) }} {{ $trade['currency'] }}</td>
                            <td class="px-3 py-3 text-right text-[11px] tabular-nums text-slate-300" @if($trade['entry_price'] === '—') aria-label="Unavailable" @endif>{{ $trade['entry_price'] }}</td>
                            <td class="px-3 py-3 text-right text-[11px] tabular-nums text-slate-300" @if($trade['exit_price'] === '—') aria-label="Unavailable" @endif>{{ $trade['exit_price'] }}</td>
                            <td class="px-3 py-3 text-right">
                                <p @class([
                                    'text-[11px] font-semibold tabular-nums',
                                    'text-emerald-300' => $trade['pnl'] > 0,
                                    'text-red-300' => $trade['pnl'] < 0,
                                    'text-slate-300' => $trade['pnl'] == 0,
                                ])>{{ sprintf('%+.4f %s', $trade['pnl'], $trade['currency']) }}</p>
                                <p class="mt-0.5 text-[9px] tabular-nums text-slate-500">{{ $trade['return_percent'] === null ? '—' : sprintf('%+.2f%%', $trade['return_percent']) }}</p>
                            </td>
                            <td class="px-4 py-3 text-right text-[10px] text-slate-500">
                                {{ $trade['settled_at']?->format('M j, Y H:i') ?? '—' }}
                                @if($trade['opportunity_id'])
                                    <a href="{{ route('opportunities.show', $trade['opportunity_id']) }}" class="mt-1 block font-semibold text-sky-300 hover:text-sky-200">View opportunity</a>
                                @endif
                                @if($activeSource === 'legacy' && $trade['status'] === 'open')
                                    <a href="{{ route('dashboard', ['view' => 'tools']) }}#positions-heading" class="mt-1 block font-semibold text-sky-300 hover:text-sky-200">View on Dashboard</a>
                                @endif
                            </td>
                        </tr>
                        <tr class="bg-slate-950/15">
                            <td colspan="7" class="px-4 py-1.5">
                                <details>
                                    <summary class="flex min-h-7 cursor-pointer list-none items-center gap-1.5 text-[10px] font-medium text-slate-500 hover:text-slate-300">
                                        <x-app-icon name="chevron-down" :size="12" />
                                        Stored settlement evidence
                                    </summary>
                                    <dl class="grid gap-2 py-2 sm:grid-cols-3 lg:grid-cols-6">
                                        @foreach([
                                            ['Entry market cap', $trade['entry_market_cap'] ?? null],
                                            ['Observed market cap', $trade['observed_market_cap'] ?? null],
                                            ['Trigger market cap', $trade['trigger_market_cap'] ?? null],
                                            ['Simulated fill market cap', $trade['fill_market_cap'] ?? null],
                                            ['Fill multiple', $trade['fill_multiple'] ?? null],
                                            ['Evidence mode', str($trade['evidence_label'])->headline()],
                                        ] as [$label, $value])
                                            <div class="rounded-md border border-slate-800 bg-slate-900/55 px-2.5 py-2">
                                                <dt class="text-[9px] text-slate-600">{{ $label }}</dt>
                                                <dd class="mt-0.5 truncate text-[10px] font-medium text-slate-300" @if($value === null) aria-label="Unavailable" @endif>
                                                    {{ $value === null ? '—' : ($label === 'Fill multiple' ? number_format($value, 2).'x' : ($label === 'Evidence mode' ? $value : '$'.number_format($value, 2))) }}
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="divide-y divide-slate-800 md:hidden" data-trading-history-mobile-list>
            @foreach($history as $trade)
                <article class="grid gap-3 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-semibold text-white">{{ $trade['symbol'] }}</h3>
                            <p class="mt-0.5 text-[10px] text-slate-500">{{ $trade['network'] }} · {{ $trade['exit_reason'] }}</p>
                        </div>
                        <span class="rounded bg-slate-800 px-2 py-1 text-[9px] font-semibold text-slate-300">{{ $trade['result'] }}</span>
                    </div>
                    <dl class="grid grid-cols-3 gap-2 rounded-md bg-slate-950/30 p-2.5 text-center">
                        @foreach([
                            ['Cost basis', number_format($trade['cost_basis'], 4).' '.$trade['currency']],
                            ['Exit price', $trade['exit_price']],
                            ['P/L', sprintf('%+.4f %s', $trade['pnl'], $trade['currency'])],
                        ] as [$label, $value])
                            <div>
                                <dt class="text-[9px] text-slate-500">{{ $label }}</dt>
                                <dd class="mt-0.5 truncate text-[10px] font-semibold tabular-nums text-slate-200" @if($value === '—') aria-label="Unavailable" @endif>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <details class="rounded-md border border-slate-800 bg-slate-950/20 px-3 py-2">
                        <summary class="cursor-pointer text-[10px] font-semibold text-slate-400">Stored settlement evidence</summary>
                        <p class="mt-2 text-[10px] leading-4 text-slate-500">Observed market cap {{ ($trade['observed_market_cap'] ?? null) === null ? '—' : '$'.number_format($trade['observed_market_cap'], 2) }} · {{ str($trade['evidence_label'])->headline() }}</p>
                    </details>
                    <p class="text-[10px] text-slate-500">Settled {{ $trade['settled_at']?->format('M j, Y H:i') ?? 'not reported' }}</p>
                </article>
            @endforeach
        </div>
    @else
        <div class="px-5 py-12 text-center" data-trading-empty-state>
            <span class="mx-auto flex size-10 items-center justify-center rounded-full border border-slate-700 bg-slate-800/70 text-slate-400"><x-app-icon name="trading" :size="19" /></span>
            <h3 class="mt-3 text-sm font-semibold text-slate-200">No {{ $trading['source_label'] }} trades match</h3>
            <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">Stored simulated trades will appear here when this source has matching records.</p>
        </div>
    @endif
</section>
