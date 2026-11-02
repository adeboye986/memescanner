<x-layouts.shell :title="($opportunity->symbol ?: 'Opportunity').' Opportunity'" :administrator="auth()->user()->is_admin">
    <div class="mx-auto flex w-full max-w-[72rem] flex-col gap-3.5 sm:gap-4" data-opportunity-detail>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('opportunities.index') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg px-2 text-sm font-semibold text-slate-400 transition hover:bg-slate-800/70 hover:text-white focus:outline-none focus:ring-2 focus:ring-violet-400">← All Opportunities</a>
        <x-opportunity-status :status="$opportunity->status" />
    </div>

    @if (session('success')) <div class="rounded-lg border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ session('success') }}</div> @endif
    @if (session('warning')) <div class="rounded-lg border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm text-amber-200" role="status">{{ session('warning') }}</div> @endif
    @if (session('error')) <div class="rounded-lg border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">{{ session('error') }}</div> @endif

    <header class="rounded-lg border border-slate-700/80 bg-slate-900/80 p-4 shadow-[0_18px_45px_rgba(2,6,23,0.16)] sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-start gap-3.5">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-violet-400/25 bg-violet-400/10 text-sm font-bold text-violet-200" aria-hidden="true">{{ str($opportunity->symbol ?: $opportunity->name ?: '?')->substr(0, 1)->upper() }}</span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-violet-400/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-violet-300">{{ $opportunity->chain->label() }}</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ $presentation['source']['scanner'] }}</span>
                    </div>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-white sm:text-[1.7rem]">{{ $opportunity->symbol ?: 'Unknown Token' }}</h1>
                    <p class="mt-0.5 text-sm text-slate-400">{{ $opportunity->name ?: 'Token name unavailable' }}</p>
                    <div class="mt-3 flex max-w-2xl items-center gap-2 rounded-lg border border-slate-800 bg-slate-950/65 p-1.5 pl-3">
                        <code class="min-w-0 flex-1 truncate text-xs text-slate-400" title="{{ $opportunity->address }}">{{ $opportunity->address }}</code>
                        <button type="button" data-copy-value="{{ $opportunity->address }}" class="copy-value min-h-9 shrink-0 rounded-md border border-slate-700 px-3 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white focus:outline-none focus:ring-2 focus:ring-violet-400">Copy</button>
                    </div>
                </div>
            </div>
            <dl class="grid shrink-0 grid-cols-2 gap-x-5 gap-y-3 rounded-lg border border-slate-800 bg-slate-950/55 p-3.5 text-sm lg:min-w-72">
                <div><dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Entry policy</dt><dd class="mt-1 font-semibold text-slate-200">{{ str($opportunity->entry_mode->value)->replace('_', ' ')->title() }}</dd></div>
                <div><dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Environment</dt><dd class="mt-1 font-semibold text-slate-200">{{ strtoupper($opportunity->execution_mode->value) }}</dd></div>
                <div class="col-span-2 border-t border-slate-800 pt-3"><dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Evidence source</dt><dd class="mt-1 text-xs text-slate-400">{{ $presentation['source']['owner'] }}</dd></div>
            </dl>
        </div>
    </header>

    <details class="group rounded-lg border border-slate-800 bg-slate-900/65">
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-4 py-2.5 text-sm font-semibold text-slate-300 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-violet-400">
            <span>Workflow evidence &amp; technical details</span>
            <span class="text-slate-500 transition group-open:rotate-180" aria-hidden="true">⌄</span>
        </summary>
        <div class="border-t border-slate-800 p-3 sm:p-4">@include('partials.opportunity-lifecycle')</div>
    </details>

    <section class="grid gap-px overflow-hidden rounded-lg border border-slate-800 bg-slate-800 sm:grid-cols-2 lg:grid-cols-5" aria-label="Opportunity market summary">
        @foreach ([['Price', $presentation['market']['price']], ['Market Cap', $presentation['market']['market_cap']], ['Liquidity', $presentation['market']['liquidity']], ['Volume', $presentation['market']['volume']], ['Qualified', $presentation['market']['qualified_at']]] as [$label, $value])
            <div class="bg-slate-900 px-3.5 py-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-slate-600">{{ $label }}</p><p class="mt-1.5 text-sm font-semibold tabular-nums text-slate-200">{{ $value }}</p></div>
        @endforeach
    </section>

    @if ($opportunity->volume === null)
        <p class="-mt-1 text-xs text-slate-500">Volume was not captured by the scanner for this historical opportunity; no value has been inferred.</p>
    @endif

    @if ($ethereumLive)
        @include('opportunities.ethereum-execution', ['attempt' => $opportunity->ethereumSwapAttempt])
    @elseif ($solanaLive)
        @include('opportunities.solana-execution', ['attempt' => $opportunity->solanaSwapAttempt])
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::PendingConfirmation)
        <section class="rounded-lg border border-amber-400/25 bg-amber-400/5 p-4 sm:p-5"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-300">Decision Required</p><h2 class="mt-1.5 text-lg font-semibold text-white">Approve or ignore this opportunity</h2><p class="mt-1.5 text-sm text-slate-400">Approval revalidates the state and uses the currently configured execution environment. LIVE remains locked server-side.</p><div class="mt-4 flex flex-wrap gap-2.5"><form method="POST" action="{{ route('opportunities.approve', $opportunity) }}">@csrf<button class="min-h-10 rounded-md bg-violet-500 px-4 text-sm font-bold text-white transition hover:bg-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-300">Approve Opportunity</button></form><form method="POST" action="{{ route('opportunities.ignore', $opportunity) }}">@csrf<button class="min-h-10 rounded-md border border-slate-600 px-4 text-sm font-semibold text-slate-200 transition hover:border-slate-500 hover:text-white focus:outline-none focus:ring-2 focus:ring-violet-400">Ignore</button></form></div></section>
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::Qualified && $opportunity->entry_mode === \App\Enums\EntryMode::Signal)
        <section class="rounded-lg border border-sky-400/20 bg-sky-400/5 p-4 text-sm text-sky-200"><strong>Signal only:</strong> this opportunity was recorded for visibility and will never execute automatically.</section>
    @endif

    @if ($opportunity->paperPosition)
        <section class="flex flex-col gap-4 rounded-lg border border-emerald-400/25 bg-emerald-400/5 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"><div><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-emerald-300">Paper Trade Opened</p><h2 class="mt-1.5 text-xl font-semibold text-white">Position #{{ $opportunity->paperPosition->id }}</h2><div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-400"><span>Execution mode: <strong class="text-slate-200">{{ $opportunity->execution_mode->value === 'paper' ? 'Paper' : 'Live' }}</strong></span><span>Entry policy: <strong class="text-slate-200">{{ str($opportunity->entry_mode->value)->title() }}</strong></span></div></div><a href="{{ $opportunity->paperPosition->status === 'open' ? route('dashboard', ['view' => 'tools']).'#positions-heading' : route('trades.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-md bg-emerald-400 px-4 text-center text-sm font-bold text-slate-950">{{ $opportunity->paperPosition->status === 'open' ? 'View Position' : 'View Trade' }} →</a></section>
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::Failed)
        <section class="rounded-lg border border-red-400/25 bg-red-400/5 p-4 sm:p-5"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-red-300">Execution Failed</p><p class="mt-1.5 text-sm text-slate-300">{{ $presentation['failure_reason'] ?? 'Execution could not be completed. No sensitive provider details are displayed.' }}</p></section>
    @endif

    <div class="grid gap-3.5 lg:grid-cols-2">
        <section class="rounded-lg border border-slate-800 bg-slate-900/70 p-4 sm:p-5"><p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-sky-400">At Qualification</p><h2 class="mt-1.5 text-base font-semibold text-white">Qualification Details</h2><dl class="mt-3 divide-y divide-slate-800">@forelse ($presentation['qualification'] as $field)<div class="flex justify-between gap-5 py-2.5 text-sm"><dt class="text-slate-500">{{ $field['label'] }}</dt><dd class="max-w-[60%] break-words text-right text-slate-300">{{ $field['value'] }}</dd></div>@empty<p class="py-3 text-sm text-slate-500">No additional qualification metadata was captured.</p>@endforelse</dl></section>
        <section class="rounded-lg border border-slate-800 bg-slate-900/70 p-4 sm:p-5"><div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-amber-300">Chain-Aware Coverage</p><h2 class="mt-1.5 text-base font-semibold text-white">Security Information</h2></div><span @class(['rounded-full px-2.5 py-1 text-[10px] font-bold uppercase', 'bg-emerald-400/10 text-emerald-300' => $presentation['security']['status'] === 'passed', 'bg-red-400/10 text-red-300' => $presentation['security']['status'] === 'failed', 'bg-slate-800 text-slate-400' => $presentation['security']['status'] === 'unavailable'])>{{ $presentation['security']['status'] }}</span></div><p class="mt-2.5 text-sm leading-6 text-slate-500">{{ $presentation['security']['summary'] }}</p><dl class="mt-3 divide-y divide-slate-800">@forelse ($presentation['security']['fields'] as $field)<div class="flex justify-between gap-5 py-2.5 text-sm"><dt class="text-slate-500">{{ $field['label'] }}</dt><dd class="max-w-[60%] break-words text-right text-slate-300">{{ $field['value'] }}</dd></div>@empty<p class="py-3 text-sm text-slate-500">Applicable security details were unavailable at qualification time.</p>@endforelse</dl></section>
    </div>

    <section class="rounded-lg border border-slate-800 bg-slate-900/70 p-4 sm:p-5"><h2 class="text-base font-semibold text-white">Decision Audit</h2><div class="mt-3 divide-y divide-slate-800">@forelse ($opportunity->events->sortByDesc('id') as $event)<div class="flex flex-wrap justify-between gap-3 py-2.5 text-sm"><span class="font-semibold text-slate-300">{{ str($event->action)->replace('_', ' ')->title() }}</span><span class="text-slate-500">{{ $event->from_status }} → {{ $event->to_status }} · {{ $event->user?->name ?? 'System' }} · {{ $event->created_at->diffForHumans() }}</span></div>@empty<p class="py-3 text-sm text-slate-500">No manual actions recorded.</p>@endforelse</div></section>
    </div>
</x-layouts.shell>
