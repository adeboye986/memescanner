<x-layouts.app title="Trade Opportunities">
    <header class="flex flex-col gap-4 border-b border-slate-800 pb-7 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-violet-400">Discovery Pipeline</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">Trade Opportunities</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">Qualified scanner results live here independently from trades. Signal opportunities never execute automatically; confirmation opportunities wait for an explicit decision.</p>
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-900/70 px-4 py-3 text-right">
            <p class="text-xs uppercase tracking-wider text-slate-500">Matching Results</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-white">{{ $opportunities->total() }}</p>
        </div>
    </header>

    <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <form method="GET" action="{{ route('opportunities.index') }}" class="grid gap-4 sm:grid-cols-3 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end">
            @foreach ([['status', 'Status', $statuses], ['chain', 'Chain', $chains], ['entry_mode', 'Entry Mode', $entryModes]] as [$name, $label, $options])
                <label class="flex flex-col gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                    {{ $label }}
                    <select name="{{ $name }}" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm font-medium normal-case tracking-normal text-slate-200 focus:border-emerald-400 focus:outline-none">
                        <option value="">All</option>
                        @foreach ($options as $option)
                            <option value="{{ $option->value }}" @selected(($filters[$name] ?? '') === $option->value)>{{ method_exists($option, 'label') ? $option->label() : str($option->value)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
            <div class="flex gap-2"><button class="rounded-xl bg-emerald-400 px-4 py-3 text-sm font-semibold text-slate-950">Filter</button><a href="{{ route('opportunities.index') }}" class="rounded-xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-300">Reset</a></div>
        </form>
    </section>

    <section class="grid gap-4">
        @forelse ($opportunities as $opportunity)
            <a href="{{ route('opportunities.show', $opportunity) }}" class="group grid gap-5 rounded-2xl border border-slate-800 bg-slate-900/70 p-5 transition hover:border-slate-600 hover:bg-slate-900 md:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))_auto] md:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2"><h2 class="text-lg font-semibold text-white">{{ $opportunity->symbol ?: 'Unknown' }}</h2><span class="text-sm text-slate-500">{{ $opportunity->name }}</span></div>
                    <p class="mt-2 truncate font-mono text-xs text-slate-500" title="{{ $opportunity->address }}">{{ str($opportunity->address)->limit(18, '…') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2"><span class="rounded-full bg-violet-400/10 px-2.5 py-1 text-[11px] font-semibold uppercase text-violet-300">{{ $opportunity->chain->label() }}</span><x-opportunity-status :status="$opportunity->status" /></div>
                </div>
                @foreach ([['Market Cap', $opportunity->market_cap !== null ? '$'.number_format((float) $opportunity->market_cap, 0) : 'N/A'], ['Liquidity', $opportunity->liquidity !== null ? '$'.number_format((float) $opportunity->liquidity, 0) : 'N/A'], ['Volume', $opportunity->volume !== null ? '$'.number_format((float) $opportunity->volume, 0) : 'N/A'], ['Source', str($opportunity->scanner)->replace('_', ' ')->title()]] as [$label, $value])
                    <div><p class="text-[11px] uppercase tracking-wider text-slate-600">{{ $label }}</p><p class="mt-1 text-sm font-semibold tabular-nums text-slate-200">{{ $value }}</p></div>
                @endforeach
                <div class="text-right"><p class="text-xs text-slate-500">{{ $opportunity->qualified_at?->diffForHumans() }}</p><span class="mt-2 inline-block text-sm font-semibold text-emerald-300 group-hover:text-emerald-200">Review →</span></div>
            </a>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-800 p-12 text-center"><p class="text-lg font-semibold text-slate-300">No matching opportunities</p><p class="mt-2 text-sm text-slate-500">Qualified scanner results will appear here.</p></div>
        @endforelse
    </section>

    {{ $opportunities->links() }}
</x-layouts.app>
