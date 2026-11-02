@php
    $activeTab = $filters['tab'];
    $activeSource = $filters['source'];
    $queryFor = fn (array $changes = []): array => array_filter(array_merge([
        'tab' => $activeTab,
        'source' => $activeSource,
        'search' => $filters['search'],
        'status' => $filters['status'],
        'result' => $filters['result'],
        'exit_type' => $filters['exit_type'],
        'chain' => $filters['chain'],
    ], $changes), fn (mixed $value, string $key): bool => ! in_array($value, ['', 'all'], true) || in_array($key, ['tab', 'source'], true), ARRAY_FILTER_USE_BOTH);
    $tabUrl = fn (string $tab): string => route('trades.index', $queryFor(['tab' => $tab, 'page' => null]));
    $sourceUrl = fn (string $source): string => route('trades.index', $queryFor(['source' => $source, 'page' => null]));
    $toolsUrl = route('dashboard', ['view' => 'tools']);
    $records = $activeTab === 'positions' ? $trading['positions'] : ($activeTab === 'history' ? $trading['history'] : null);
    $summaryCards = [
        ['Portfolio Value', $trading['summary']['portfolio']],
        ['Open Positions', $trading['summary']['open_positions']],
        ['Realized P/L', $trading['summary']['realized_pnl']],
        ['Win Rate', $trading['summary']['win_rate']],
    ];
@endphp

<x-layouts.shell title="Trading" :administrator="(bool) auth()->user()->is_admin">
    <div class="mx-auto flex w-full max-w-[72rem] flex-col gap-3.5 sm:gap-4" data-trading-screen>
        <header class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-[1.45rem] font-semibold leading-7 tracking-[-0.025em] text-white">Trading</h1>
                    <span class="inline-flex items-center rounded-full border border-blue-400/25 bg-blue-500/10 px-2.5 py-1 text-[10px] font-semibold tracking-wide text-blue-200">PAPER · SIMULATED</span>
                </div>
                <p class="mt-0.5 text-[13px] leading-5 text-slate-400">Monitor simulated positions, stored trade history, and source-specific performance. Entry preference: {{ str($entryMode)->upper() }}.</p>
            </div>
            <a href="{{ $toolsUrl }}#trading-preferences" class="inline-flex min-h-9 shrink-0 items-center justify-center rounded-md border border-slate-700 bg-slate-900/80 px-3 text-[11px] font-semibold text-slate-300 transition hover:border-slate-600 hover:bg-slate-800">
                Trading settings
            </a>
        </header>

        @if($executionMode === 'live')
            <div class="rounded-md border border-amber-400/25 bg-amber-400/10 px-3 py-2 text-xs leading-5 text-amber-200" role="status">
                Your requested execution mode is LIVE, but this screen remains PAPER-only. LIVE actions stay behind the existing opportunity confirmation and wallet safeguards.
            </div>
        @endif

        <nav class="flex gap-7 overflow-x-auto overflow-y-hidden border-b border-slate-800 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Trading sections">
            @foreach(['positions' => 'Positions', 'history' => 'Trade History', 'performance' => 'Performance'] as $tab => $label)
                <a
                    href="{{ $tabUrl($tab) }}"
                    @class([
                        'relative -mb-px shrink-0 whitespace-nowrap border-b-2 px-1 pb-2.5 text-xs font-medium transition',
                        'border-sky-400 text-sky-300' => $activeTab === $tab,
                        'border-transparent text-slate-400 hover:text-slate-200' => $activeTab !== $tab,
                    ])
                    @if($activeTab === $tab) aria-current="page" @endif
                >{{ $label }}</a>
            @endforeach
        </nav>

        <section class="flex flex-col gap-2 rounded-lg border border-slate-700/80 bg-slate-900/80 p-3 shadow-lg shadow-black/10 sm:flex-row sm:items-center sm:justify-between" aria-label="PAPER record source">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Record source</p>
                <p class="mt-0.5 text-xs text-slate-300">{{ $trading['source_description'] }}</p>
            </div>
            <div class="inline-flex w-full rounded-md border border-slate-700 bg-slate-950/45 p-1 sm:w-auto" role="group" aria-label="Select record source">
                @foreach(['engine' => 'Engine PAPER', 'legacy' => 'Legacy PAPER'] as $source => $label)
                    <a
                        href="{{ $sourceUrl($source) }}"
                        @class([
                            'flex min-h-8 flex-1 items-center justify-center rounded px-3 text-[10px] font-semibold transition sm:flex-none',
                            'bg-blue-600 text-white shadow-sm shadow-blue-950/30' => $activeSource === $source,
                            'text-slate-400 hover:bg-slate-800 hover:text-slate-200' => $activeSource !== $source,
                        ])
                        @if($activeSource === $source) aria-current="true" @endif
                    >{{ $label }}</a>
                @endforeach
            </div>
        </section>

        <section class="grid grid-cols-2 gap-2.5 lg:grid-cols-4" aria-label="{{ $trading['source_label'] }} summary">
            @foreach($summaryCards as [$label, $metric])
                <article class="min-w-0 rounded-lg border border-slate-700/80 bg-slate-900/80 px-3.5 py-3 shadow-lg shadow-black/10 sm:px-4">
                    <p class="truncate text-[10px] font-medium text-slate-500">{{ $label }}</p>
                    <p @class([
                        'mt-1.5 truncate text-lg font-semibold leading-6 tabular-nums sm:text-xl',
                        'text-emerald-300' => $metric['tone'] === 'positive',
                        'text-red-300' => $metric['tone'] === 'negative',
                        'text-white' => $metric['tone'] === 'neutral',
                    ]) @if($metric['value'] === '—') aria-label="Unavailable" @endif>{{ $metric['value'] }}</p>
                    <p class="mt-0.5 truncate text-[9px] text-slate-500">{{ $metric['detail'] }}</p>
                </article>
            @endforeach
        </section>

        @if($errors->any())
            <div class="rounded-md border border-red-400/25 bg-red-400/10 px-3 py-2 text-xs text-red-200" role="alert">
                The selected Trading filters could not be applied.
            </div>
        @endif

        @if(in_array($activeTab, ['positions', 'history'], true))
            <section class="rounded-lg border border-slate-700/80 bg-slate-900/80 p-3 shadow-lg shadow-black/10" aria-label="{{ $activeTab === 'positions' ? 'Position' : 'Trade history' }} filters">
                <form method="GET" action="{{ route('trades.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-7">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <input type="hidden" name="source" value="{{ $activeSource }}">
                    <label class="relative block lg:col-span-2">
                        <span class="sr-only">Search trading records</span>
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-500"><x-app-icon name="search" :size="15" /></span>
                        <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search token or address..." autocomplete="off" class="min-h-10 w-full rounded-md border border-slate-700/90 bg-slate-950/50 py-2 pr-3 pl-9 text-xs text-slate-100 placeholder:text-slate-600">
                    </label>
                    <label>
                        <span class="sr-only">Network</span>
                        <select name="chain" class="min-h-10 w-full rounded-md border border-slate-700/90 bg-slate-950/50 px-3 text-xs text-slate-200">
                            @foreach(['all' => 'All Networks', 'solana' => 'Solana', 'ethereum' => 'Ethereum'] as $value => $label)
                                <option value="{{ $value }}" @selected($filters['chain'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if($activeTab === 'history')
                        <label>
                            <span class="sr-only">Trade result</span>
                            <select name="result" class="min-h-10 w-full rounded-md border border-slate-700/90 bg-slate-950/50 px-3 text-xs text-slate-200">
                                @foreach(['all' => 'All Results', 'wins' => 'Wins', 'losses' => 'Losses', 'break-even' => 'Break-even'] as $value => $label)
                                    <option value="{{ $value }}" @selected($filters['result'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if($activeSource === 'legacy')
                            <label>
                                <span class="sr-only">Trade status</span>
                                <select name="status" class="min-h-10 w-full rounded-md border border-slate-700/90 bg-slate-950/50 px-3 text-xs text-slate-200">
                                    @foreach(['all' => 'All Statuses', 'open' => 'Open', 'closed' => 'Closed'] as $value => $label)
                                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span class="sr-only">Exit type</span>
                                <select name="exit_type" class="min-h-10 w-full rounded-md border border-slate-700/90 bg-slate-950/50 px-3 text-xs text-slate-200">
                                    @foreach(['all' => 'All Exit Types', 'manual' => 'Manual', 'stop-loss' => 'Stop Loss', 'full-target' => 'Full Target', 'protected-floor' => 'Protected Floor', 'other' => 'Other'] as $value => $label)
                                        <option value="{{ $value }}" @selected($filters['exit_type'] === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                    @endif
                    <button type="submit" class="flex min-h-10 items-center justify-center rounded-md border border-blue-400/30 bg-blue-600 px-3 text-xs font-semibold text-white shadow-sm shadow-blue-950/30 transition hover:bg-blue-500">Apply</button>
                </form>
                @if(collect(['search', 'chain', 'status', 'result', 'exit_type'])->contains(fn (string $key): bool => ! in_array($filters[$key], ['', 'all'], true)))
                    <div class="mt-2 text-right">
                        <a href="{{ route('trades.index', ['tab' => $activeTab, 'source' => $activeSource]) }}" class="text-[10px] font-semibold text-sky-300 hover:text-sky-200">Clear filters</a>
                    </div>
                @endif
            </section>
        @endif

        @if($activeTab === 'positions')
            @include('trades.partials.positions')
        @elseif($activeTab === 'history')
            @include('trades.partials.history')
        @else
            @include('trades.partials.performance')
        @endif

        @if($records?->hasPages())
            <div class="text-xs">{{ $records->links() }}</div>
        @endif

        <section class="flex flex-col gap-2 rounded-lg border border-slate-800 bg-slate-900/45 px-4 py-3 text-[11px] text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>Strategy, risk policy, and source evidence remain server-managed. This page does not activate LIVE trading.</p>
            <div class="flex flex-wrap gap-3">
                <a href="{{ $toolsUrl }}#paper-strategy" class="font-semibold text-sky-300 hover:text-sky-200">PAPER strategy</a>
                <a href="{{ $toolsUrl }}{{ $activeSource === 'engine' ? '#engine-paper-portfolio' : '#positions-heading' }}" class="font-semibold text-sky-300 hover:text-sky-200">Compatibility details</a>
            </div>
        </section>
    </div>

    @if($activeTab === 'positions' && $activeSource === 'legacy')
        <dialog id="close-position-modal" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-slate-700 bg-slate-900 p-0 text-slate-100 shadow-2xl backdrop:bg-slate-950/80">
            <form id="close-position-form" method="POST" class="flex flex-col gap-5 p-5">
                @csrf
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-300">Confirm manual PAPER exit</p>
                    <h2 class="mt-1.5 text-xl font-semibold text-white">Close <span id="modal-symbol"></span>?</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">Fresh provider data will be fetched and validated when you confirm.</p>
                </div>
                <dl class="grid grid-cols-2 gap-3 rounded-lg bg-slate-950/60 p-3 text-xs">
                    <div><dt class="text-slate-500">Displayed return</dt><dd id="modal-return" class="mt-1 font-semibold text-white"></dd></div>
                    <div><dt class="text-slate-500">Remaining</dt><dd id="modal-remaining" class="mt-1 font-semibold text-white"></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Displayed value</dt><dd id="modal-value" class="mt-1 font-semibold text-white"></dd></div>
                </dl>
                <div class="rounded-lg border border-red-400/20 bg-red-400/10 px-3 py-2.5 text-xs leading-5 text-red-200">This closes 100% of the remaining legacy PAPER position. Existing server-side authorization and freshness checks still apply.</div>
                <div class="flex justify-end gap-2">
                    <button id="cancel-close" type="button" class="min-h-9 rounded-md border border-slate-700 px-3 text-xs font-semibold text-slate-300 hover:bg-slate-800">Cancel</button>
                    <button type="submit" class="min-h-9 rounded-md bg-red-600 px-3 text-xs font-semibold text-white hover:bg-red-500">Close PAPER position</button>
                </div>
            </form>
        </dialog>
    @endif
</x-layouts.shell>
