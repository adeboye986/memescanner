@php
    $activeTab = $filters['tab'];
    $hasFilters = collect(['search', 'chain', 'status', 'entry_mode', 'score_band'])
        ->contains(fn (string $filter): bool => ($filters[$filter] ?? '') !== '');
    $tabUrl = fn (string $tab): string => route('opportunities.index', array_filter([
        'tab' => $tab,
        'search' => $filters['search'] ?? null,
        'chain' => $filters['chain'] ?? null,
        'entry_mode' => $filters['entry_mode'] ?? null,
        'score_band' => $filters['score_band'] ?? null,
    ], fn (mixed $value): bool => $value !== null && $value !== ''));
    $emptyHeading = match ($activeTab) {
        'ignored' => 'No ignored opportunities',
        'history' => 'No opportunity history',
        default => 'No active opportunities',
    };
    $emptyDescription = $hasFilters
        ? 'No authorized opportunities match the selected filters.'
        : match ($activeTab) {
            'ignored' => 'Opportunities you ignore will remain available here.',
            'history' => 'Completed and terminal opportunities will appear here.',
            default => 'Qualified scanner results will appear here when they are available.',
        };
@endphp

<x-layouts.shell title="Opportunities" :administrator="(bool) auth()->user()->is_admin">
    <div class="mx-auto flex w-full max-w-[72rem] flex-col gap-3.5 sm:gap-4" data-opportunities-screen>
        <header class="flex flex-col gap-0.5">
            <h1 class="text-[1.45rem] font-semibold leading-7 tracking-[-0.025em] text-white">Opportunities</h1>
            <p class="text-[13px] leading-5 text-slate-400">Qualified tokens ready for your review and supported trading workflows.</p>
        </header>

        <nav
            class="flex gap-7 overflow-x-auto overflow-y-hidden border-b border-slate-800 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            aria-label="Opportunity sections"
            data-tab-strip
        >
            @foreach($tabs as $tab => $label)
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

        @if($errors->any())
            <div class="rounded-md border border-red-400/25 bg-red-400/10 px-3 py-2 text-xs text-red-200" role="alert">
                The selected opportunity filters could not be applied. Review the filter values and try again.
            </div>
        @endif

        <section class="rounded-lg border border-slate-700/80 bg-slate-900/80 p-3 shadow-lg shadow-black/10" aria-label="Opportunity filters">
            <form method="GET" action="{{ route('opportunities.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(16rem,1.7fr)_minmax(9rem,0.75fr)_minmax(9rem,0.75fr)_auto_auto]">
                <input type="hidden" name="tab" value="{{ $activeTab }}">

                <label class="relative block">
                    <span class="sr-only">Search opportunities</span>
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-500">
                        <x-app-icon name="search" :size="15" />
                    </span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $filters['search'] ?? '' }}"
                        placeholder="Search token, address or symbol..."
                        autocomplete="off"
                        class="min-h-10 w-full rounded-md border border-slate-700/90 bg-slate-950/50 py-2 pr-3 pl-9 text-xs text-slate-100 placeholder:text-slate-600"
                    >
                </label>

                <label>
                    <span class="sr-only">Network</span>
                    <select name="chain" class="min-h-10 w-full rounded-md border border-slate-700/90 bg-slate-950/50 px-3 text-xs text-slate-200">
                        <option value="">All Networks</option>
                        @foreach($chains as $chain)
                            <option value="{{ $chain->value }}" @selected(($filters['chain'] ?? '') === $chain->value)>{{ $chain->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span class="sr-only">Opportunity status</span>
                    <select name="status" class="min-h-10 w-full rounded-md border border-slate-700/90 bg-slate-950/50 px-3 text-xs text-slate-200">
                        <option value="">All Statuses</option>
                        @foreach($statusOptions as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <details class="group relative" @if(($filters['entry_mode'] ?? '') !== '' || ($filters['score_band'] ?? '') !== '') open @endif>
                    <summary class="flex min-h-10 cursor-pointer list-none items-center justify-center rounded-md border border-slate-700/90 bg-slate-950/50 px-3 text-slate-400 transition hover:border-slate-600 hover:text-slate-200" aria-label="More filters">
                        <x-app-icon name="filter" :size="16" />
                        <span class="ml-2 text-xs lg:sr-only">More filters</span>
                    </summary>
                    <div class="mt-2 grid gap-3 rounded-md border border-slate-700 bg-slate-900 p-3 shadow-xl shadow-black/20 sm:absolute sm:right-0 sm:z-20 sm:w-64">
                        <label class="grid gap-1.5 text-[10px] font-medium text-slate-400">
                            Entry mode
                            <select name="entry_mode" class="min-h-9 rounded-md border border-slate-700 bg-slate-950/60 px-3 text-xs text-slate-200">
                                <option value="">All entry modes</option>
                                @foreach($entryModes as $entryMode)
                                    <option value="{{ $entryMode->value }}" @selected(($filters['entry_mode'] ?? '') === $entryMode->value)>{{ str($entryMode->value)->upper() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1.5 text-[10px] font-medium text-slate-400">
                            Recorded security score
                            <select name="score_band" class="min-h-9 rounded-md border border-slate-700 bg-slate-950/60 px-3 text-xs text-slate-200">
                                <option value="">All reported scores</option>
                                @foreach($scoreBands as $value => $label)
                                    <option value="{{ $value }}" @selected(($filters['score_band'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </details>

                <button type="submit" class="flex min-h-10 items-center justify-center rounded-md border border-blue-400/30 bg-blue-600 px-3 text-xs font-semibold text-white shadow-sm shadow-blue-950/30 transition hover:bg-blue-500">
                    Apply
                </button>
            </form>

            @if($hasFilters)
                <div class="mt-2 flex items-center justify-between gap-3 text-[10px] text-slate-500">
                    <span>{{ number_format($opportunities->total()) }} matching {{ str('opportunity')->plural($opportunities->total()) }}</span>
                    <a href="{{ route('opportunities.index', ['tab' => $activeTab]) }}" class="font-semibold text-sky-300 hover:text-sky-200">Clear filters</a>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-700/80 bg-slate-900/80 shadow-lg shadow-black/10" aria-labelledby="opportunity-results-heading">
            <div class="flex items-center justify-between border-b border-slate-800 px-4 py-3">
                <h2 id="opportunity-results-heading" class="text-sm font-semibold text-white">{{ $tabs[$activeTab] }}</h2>
                <span class="text-[10px] tabular-nums text-slate-500">{{ number_format($opportunities->total()) }} total</span>
            </div>

            @if($opportunities->isNotEmpty())
                <div class="hidden overflow-x-auto md:block" data-opportunity-table>
                    <table class="w-full min-w-[46rem] text-left">
                        <thead class="border-b border-slate-800 bg-slate-950/30 text-[10px] font-medium text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">Token</th>
                                <th class="px-3 py-2.5 font-medium">Network</th>
                                <th class="px-3 py-2.5 text-right font-medium">Entry Price</th>
                                <th class="px-3 py-2.5 text-right font-medium">Market Cap</th>
                                <th class="px-3 py-2.5 text-center font-medium">Score</th>
                                <th class="px-3 py-2.5 font-medium">Status</th>
                                <th class="px-4 py-2.5 text-right font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/90">
                            @foreach($opportunities as $opportunity)
                                <tr class="transition hover:bg-slate-800/35">
                                    <td class="px-4 py-3">
                                        <div class="flex min-w-0 items-center gap-2.5" title="{{ $opportunity['source_owner'] }}">
                                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full border border-slate-700 bg-slate-800 text-[10px] font-bold text-slate-200">{{ mb_strtoupper(mb_substr($opportunity['symbol'], 0, 1)) }}</span>
                                            <div class="min-w-0">
                                                <p class="truncate text-xs font-semibold text-white">{{ $opportunity['name'] }}</p>
                                                <p class="truncate text-[10px] text-slate-500">{{ $opportunity['symbol'] }} · {{ $opportunity['address_display'] }}</p>
                                                <span class="sr-only">Record source: {{ $opportunity['source_owner'] }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-[11px] text-slate-300">{{ $opportunity['network_label'] }}</td>
                                    <td class="px-3 py-3 text-right text-[11px] font-medium tabular-nums text-slate-200" @if($opportunity['price_display'] === '—') aria-label="Unavailable" @endif>{{ $opportunity['price_display'] }}</td>
                                    <td class="px-3 py-3 text-right text-[11px] font-medium tabular-nums text-slate-200" @if($opportunity['market_cap_display'] === '—') aria-label="Unavailable" @endif>{{ $opportunity['market_cap_display'] }}</td>
                                    <td class="px-3 py-3 text-center">
                                        @if($opportunity['score'] === null)
                                            <span class="text-[11px] text-slate-500" aria-label="Unavailable">—</span>
                                        @else
                                            <span @class([
                                                'inline-flex min-w-8 justify-center rounded-md border px-1.5 py-1 text-[10px] font-semibold tabular-nums',
                                                'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => $opportunity['score_tone'] === 'strong',
                                                'border-amber-400/20 bg-amber-400/10 text-amber-300' => $opportunity['score_tone'] === 'moderate',
                                                'border-red-400/20 bg-red-400/10 text-red-300' => $opportunity['score_tone'] === 'elevated',
                                            ])>{{ $opportunity['score'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3"><x-opportunity-status :status="$opportunity['status']" /></td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('opportunities.show', $opportunity['id']) }}" class="inline-flex min-h-8 items-center rounded-md border border-blue-400/20 bg-blue-500/10 px-3 text-[10px] font-semibold text-blue-200 transition hover:bg-blue-500/20">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-slate-800 md:hidden" data-opportunity-mobile-list>
                    @foreach($opportunities as $opportunity)
                        <article class="grid gap-3 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full border border-slate-700 bg-slate-800 text-[11px] font-bold text-slate-200">{{ mb_strtoupper(mb_substr($opportunity['symbol'], 0, 1)) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-semibold text-white">{{ $opportunity['name'] }}</h3>
                                        <p class="truncate text-[10px] text-slate-500">{{ $opportunity['symbol'] }} · {{ $opportunity['network_label'] }}</p>
                                    </div>
                                </div>
                                <x-opportunity-status :status="$opportunity['status']" />
                            </div>
                            <dl class="grid grid-cols-3 gap-2 rounded-md bg-slate-950/30 p-2.5 text-center">
                                @foreach([
                                    ['Entry price', $opportunity['price_display']],
                                    ['Market cap', $opportunity['market_cap_display']],
                                    ['Score', $opportunity['score'] ?? '—'],
                                ] as [$label, $value])
                                    <div>
                                        <dt class="text-[9px] text-slate-500">{{ $label }}</dt>
                                        <dd class="mt-0.5 truncate text-[11px] font-semibold tabular-nums text-slate-200" @if($value === '—') aria-label="Unavailable" @endif>{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <a href="{{ route('opportunities.show', $opportunity['id']) }}" class="flex min-h-9 items-center justify-center rounded-md border border-blue-400/20 bg-blue-500/10 text-[11px] font-semibold text-blue-200">View opportunity</a>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="px-5 py-12 text-center" data-opportunity-empty-state>
                    <span class="mx-auto flex size-10 items-center justify-center rounded-full border border-slate-700 bg-slate-800/70 text-slate-400"><x-app-icon name="opportunity" :size="19" /></span>
                    <h3 class="mt-3 text-sm font-semibold text-slate-200">{{ $emptyHeading }}</h3>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">{{ $emptyDescription }}</p>
                </div>
            @endif
        </section>

        @if($opportunities->hasPages())
            @php
                $currentPage = $opportunities->currentPage();
                $lastPage = $opportunities->lastPage();
                $pageStart = max(1, $currentPage - 2);
                $pageEnd = min($lastPage, $currentPage + 2);
            @endphp
            <nav class="flex flex-col gap-2 text-[11px] sm:flex-row sm:items-center sm:justify-between" aria-label="Opportunity pagination">
                <p class="text-slate-500">Showing {{ number_format($opportunities->firstItem()) }}–{{ number_format($opportunities->lastItem()) }} of {{ number_format($opportunities->total()) }}</p>
                <div class="flex flex-wrap items-center gap-1">
                    @if($opportunities->onFirstPage())
                        <span class="inline-flex min-h-8 items-center rounded-md border border-slate-800 px-3 text-slate-600" aria-disabled="true">Previous</span>
                    @else
                        <a href="{{ $opportunities->previousPageUrl() }}" class="inline-flex min-h-8 items-center rounded-md border border-slate-700 px-3 text-slate-300 hover:bg-slate-800">Previous</a>
                    @endif

                    @if($pageStart > 1)
                        <a href="{{ $opportunities->url(1) }}" class="inline-flex size-8 items-center justify-center rounded-md text-slate-400 hover:bg-slate-800">1</a>
                        @if($pageStart > 2)<span class="px-1 text-slate-600">…</span>@endif
                    @endif

                    @for($page = $pageStart; $page <= $pageEnd; $page++)
                        <a
                            href="{{ $opportunities->url($page) }}"
                            @class([
                                'inline-flex size-8 items-center justify-center rounded-md border text-[11px] font-semibold',
                                'border-blue-400/30 bg-blue-500/15 text-blue-200' => $page === $currentPage,
                                'border-transparent text-slate-400 hover:border-slate-700 hover:bg-slate-800' => $page !== $currentPage,
                            ])
                            @if($page === $currentPage) aria-current="page" @endif
                        >{{ $page }}</a>
                    @endfor

                    @if($pageEnd < $lastPage)
                        @if($pageEnd < $lastPage - 1)<span class="px-1 text-slate-600">…</span>@endif
                        <a href="{{ $opportunities->url($lastPage) }}" class="inline-flex size-8 items-center justify-center rounded-md text-slate-400 hover:bg-slate-800">{{ $lastPage }}</a>
                    @endif

                    @if($opportunities->hasMorePages())
                        <a href="{{ $opportunities->nextPageUrl() }}" class="inline-flex min-h-8 items-center rounded-md border border-slate-700 px-3 text-slate-300 hover:bg-slate-800">Next</a>
                    @else
                        <span class="inline-flex min-h-8 items-center rounded-md border border-slate-800 px-3 text-slate-600" aria-disabled="true">Next</span>
                    @endif
                </div>
            </nav>
        @endif
    </div>
</x-layouts.shell>
