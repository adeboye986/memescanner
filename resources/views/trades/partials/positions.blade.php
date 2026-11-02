@php($positions = $trading['positions'])

<section class="overflow-hidden rounded-lg border border-slate-700/80 bg-slate-900/80 shadow-lg shadow-black/10" aria-labelledby="positions-heading">
    <div class="flex items-center justify-between border-b border-slate-800 px-4 py-3">
        <div>
            <h2 id="positions-heading" class="text-sm font-semibold text-white">Open Positions</h2>
            <p class="mt-0.5 text-[10px] text-slate-500">{{ $trading['source_label'] }} · observed values only</p>
        </div>
        <span class="text-[10px] tabular-nums text-slate-500">{{ number_format($positions->total()) }} total</span>
    </div>

    @if($positions->isNotEmpty())
        <div class="hidden overflow-x-auto md:block" data-trading-position-table>
            <table class="w-full min-w-[54rem] text-left">
                <thead class="border-b border-slate-800 bg-slate-950/30 text-[10px] font-medium text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Token</th>
                        <th class="px-3 py-2.5 font-medium">Amount</th>
                        <th class="px-3 py-2.5 text-right font-medium">Entry Price</th>
                        <th class="px-3 py-2.5 text-right font-medium">Observed Price</th>
                        <th class="px-3 py-2.5 text-right font-medium">Unrealized P/L</th>
                        <th class="px-4 py-2.5 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/90">
                    @foreach($positions as $position)
                        <tr class="transition hover:bg-slate-800/35">
                            <td class="px-4 py-3">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full border border-slate-700 bg-slate-800 text-[10px] font-bold text-slate-200">{{ mb_strtoupper(mb_substr($position['symbol'], 0, 1)) }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-xs font-semibold text-white">{{ $position['symbol'] }}</p>
                                        <p class="truncate text-[10px] text-slate-500" title="{{ $position['address'] }}">{{ $position['network'] }} · {{ str($position['address'])->limit(18) }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-[11px] text-slate-300">{{ $position['amount'] }}</td>
                            <td class="px-3 py-3 text-right text-[11px] font-medium tabular-nums text-slate-200" @if($position['entry_price'] === '—') aria-label="Unavailable" @endif>{{ $position['entry_price'] }}</td>
                            <td class="px-3 py-3 text-right">
                                <p class="text-[11px] font-medium tabular-nums text-slate-200" @if($position['observed_price'] === '—') aria-label="Unavailable" @endif>{{ $position['observed_price'] }}</p>
                                <span @class([
                                    'mt-1 inline-flex rounded px-1.5 py-0.5 text-[9px] font-medium',
                                    'bg-emerald-400/10 text-emerald-300' => $position['observation']['state'] === 'fresh',
                                    'bg-amber-400/10 text-amber-300' => $position['observation']['state'] === 'stale',
                                    'bg-slate-800 text-slate-500' => $position['observation']['state'] === 'unavailable',
                                ])>{{ $position['observation']['label'] }}</span>
                            </td>
                            <td class="px-3 py-3 text-right">
                                @if($position['pnl'] === null)
                                    <p class="text-[11px] text-slate-500" aria-label="Unavailable">—</p>
                                    <p class="mt-0.5 text-[9px] text-slate-600">Needs fresh data</p>
                                @else
                                    <p @class([
                                        'text-[11px] font-semibold tabular-nums',
                                        'text-emerald-300' => $position['pnl'] > 0,
                                        'text-red-300' => $position['pnl'] < 0,
                                        'text-slate-300' => $position['pnl'] == 0,
                                    ])>{{ sprintf('%+.4f %s', $position['pnl'], $position['currency']) }}</p>
                                    <p class="mt-0.5 text-[9px] tabular-nums text-slate-500">{{ sprintf('%+.2f%%', $position['pnl_percent']) }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($position['manual_close'])
                                    <button
                                        type="button"
                                        class="open-close-modal inline-flex min-h-8 items-center rounded-md border border-red-400/25 bg-red-500/10 px-3 text-[10px] font-semibold text-red-200 transition hover:bg-red-500/20"
                                        data-action="{{ $position['close_url'] }}"
                                        data-symbol="{{ $position['symbol'] }}"
                                        data-return="{{ $position['pnl_percent'] === null ? '—' : sprintf('%+.2f%%', $position['pnl_percent']) }}"
                                        data-remaining="{{ $position['remaining'] }}"
                                        data-value="{{ $position['current_value'] === null ? '—' : number_format($position['current_value'], 4).' '.$position['currency'] }}"
                                    >Close</button>
                                @elseif($position['opportunity_id'])
                                    <a href="{{ route('opportunities.show', $position['opportunity_id']) }}" class="inline-flex min-h-8 items-center rounded-md border border-blue-400/20 bg-blue-500/10 px-3 text-[10px] font-semibold text-blue-200 transition hover:bg-blue-500/20">View</a>
                                @else
                                    <span class="text-[10px] text-slate-600" aria-label="No action available">—</span>
                                @endif
                            </td>
                        </tr>
                        <tr class="bg-slate-950/15">
                            <td colspan="6" class="px-4 py-1.5">
                                <details class="group">
                                    <summary class="flex min-h-7 cursor-pointer list-none items-center gap-1.5 text-[10px] font-medium text-slate-500 hover:text-slate-300">
                                        <x-app-icon name="chevron-down" :size="12" />
                                        Position evidence &amp; risk
                                    </summary>
                                    <dl class="grid gap-2 py-2 sm:grid-cols-3 lg:grid-cols-6">
                                        @foreach([
                                            ['Cost basis', number_format($position['cost_basis'], 4).' '.$position['currency']],
                                            ['Entry market cap', $position['entry_market_cap'] === null ? '—' : '$'.number_format($position['entry_market_cap'], 2)],
                                            ['Observed market cap', $position['last_market_cap'] === null ? '—' : '$'.number_format($position['last_market_cap'], 2)],
                                            ['Protection', str($position['protection'])->headline()],
                                            ['Opened', $position['opened_at']?->format('M j, Y H:i') ?? '—'],
                                            ['Observation', $position['observation']['observed_at']?->format('M j, Y H:i:s') ?? '—'],
                                        ] as [$label, $value])
                                            <div class="rounded-md border border-slate-800 bg-slate-900/55 px-2.5 py-2">
                                                <dt class="text-[9px] text-slate-600">{{ $label }}</dt>
                                                <dd class="mt-0.5 truncate text-[10px] font-medium text-slate-300">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                    @if(! empty($position['strategy']))
                                        <p class="pb-2 text-[9px] text-slate-500">A server-recorded strategy snapshot is attached to this position. Full policy controls remain available through Trading settings.</p>
                                    @endif
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="divide-y divide-slate-800 md:hidden" data-trading-position-mobile-list>
            @foreach($positions as $position)
                <article class="grid gap-3 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full border border-slate-700 bg-slate-800 text-[11px] font-bold text-slate-200">{{ mb_strtoupper(mb_substr($position['symbol'], 0, 1)) }}</span>
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-white">{{ $position['symbol'] }}</h3>
                                <p class="truncate text-[10px] text-slate-500">{{ $position['network'] }} · {{ $position['amount'] }}</p>
                            </div>
                        </div>
                        <span @class([
                            'rounded px-1.5 py-1 text-[9px] font-medium',
                            'bg-emerald-400/10 text-emerald-300' => $position['observation']['state'] === 'fresh',
                            'bg-amber-400/10 text-amber-300' => $position['observation']['state'] === 'stale',
                            'bg-slate-800 text-slate-500' => $position['observation']['state'] === 'unavailable',
                        ])>{{ $position['observation']['label'] }}</span>
                    </div>
                    <dl class="grid grid-cols-3 gap-2 rounded-md bg-slate-950/30 p-2.5 text-center">
                        @foreach([
                            ['Entry', $position['entry_price']],
                            ['Observed', $position['observed_price']],
                            ['P/L', $position['pnl'] === null ? '—' : sprintf('%+.4f %s', $position['pnl'], $position['currency'])],
                        ] as [$label, $value])
                            <div>
                                <dt class="text-[9px] text-slate-500">{{ $label }}</dt>
                                <dd class="mt-0.5 truncate text-[10px] font-semibold tabular-nums text-slate-200" @if($value === '—') aria-label="Unavailable" @endif>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <details class="rounded-md border border-slate-800 bg-slate-950/20 px-3 py-2">
                        <summary class="cursor-pointer text-[10px] font-semibold text-slate-400">Position evidence &amp; risk</summary>
                        <p class="mt-2 text-[10px] leading-4 text-slate-500">Cost basis {{ number_format($position['cost_basis'], 4) }} {{ $position['currency'] }} · {{ str($position['protection'])->headline() }} · Opened {{ $position['opened_at']?->format('M j, Y H:i') ?? '—' }}</p>
                    </details>
                    @if($position['manual_close'])
                        <button
                            type="button"
                            class="open-close-modal flex min-h-9 items-center justify-center rounded-md border border-red-400/25 bg-red-500/10 text-[11px] font-semibold text-red-200"
                            data-action="{{ $position['close_url'] }}"
                            data-symbol="{{ $position['symbol'] }}"
                            data-return="{{ $position['pnl_percent'] === null ? '—' : sprintf('%+.2f%%', $position['pnl_percent']) }}"
                            data-remaining="{{ $position['remaining'] }}"
                            data-value="{{ $position['current_value'] === null ? '—' : number_format($position['current_value'], 4).' '.$position['currency'] }}"
                        >Close PAPER position</button>
                    @elseif($position['opportunity_id'])
                        <a href="{{ route('opportunities.show', $position['opportunity_id']) }}" class="flex min-h-9 items-center justify-center rounded-md border border-blue-400/20 bg-blue-500/10 text-[11px] font-semibold text-blue-200">View opportunity</a>
                    @endif
                </article>
            @endforeach
        </div>
    @else
        <div class="px-5 py-12 text-center" data-trading-empty-state>
            <span class="mx-auto flex size-10 items-center justify-center rounded-full border border-slate-700 bg-slate-800/70 text-slate-400"><x-app-icon name="trading" :size="19" /></span>
            <h3 class="mt-3 text-sm font-semibold text-slate-200">No open {{ $trading['source_label'] }} positions</h3>
            <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">Funded simulated positions will appear here when this source records them.</p>
        </div>
    @endif
</section>
