<x-layouts.shell title="Scan Details" :administrator="(bool) auth()->user()->is_admin">
    <div class="mx-auto flex w-full max-w-[74rem] flex-col gap-4 sm:gap-5" data-scan-detail-screen>
        <div>
            <a href="{{ route('scanner.index', ['tab' => 'history']) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-sky-300 hover:text-sky-200"><span aria-hidden="true">←</span> Scan history</a>
            <header class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-semibold tracking-[-0.025em] text-white sm:text-[1.75rem]">{{ $scan['scanner_label'] }}</h1>
                        @include('scanner.partials.status-badge', ['scan' => $scan])
                    </div>
                    <p class="mt-1 text-sm text-slate-400">{{ $scan['network_label'] }} scan submitted <time datetime="{{ $scan['submitted_at_iso'] }}">{{ $scan['submitted_at_display'] }}</time>.</p>
                </div>
                <a href="{{ route('scanner.index') }}" class="inline-flex min-h-9 items-center justify-center rounded-md bg-blue-600 px-3.5 text-xs font-semibold text-white hover:bg-blue-500">Run New Scan</a>
            </header>
        </div>

        @if($scan['status_key'] === 'completed_with_warnings')
            <section class="rounded-lg border border-amber-400/25 bg-amber-400/10 px-4 py-3" aria-labelledby="warning-outcome-heading">
                <div class="flex gap-3">
                    <span class="mt-0.5 text-amber-200" aria-hidden="true">!</span>
                    <div>
                        <h2 id="warning-outcome-heading" class="text-sm font-semibold text-amber-100">Completed with warnings</h2>
                        <p class="mt-1 text-xs leading-5 text-amber-100/90">Candidate failures did not make the overall execution fail. Skipped candidates were rejected by eligibility filters and are not provider failures.</p>
                        @foreach($scan['provider_warnings'] as $warning)
                            <p class="mt-1 text-[11px] leading-5 text-amber-200"><span class="font-mono">{{ $warning['code'] }}</span> × {{ $warning['count'] }} — {{ $warning['explanation'] }}</p>
                        @endforeach
                    </div>
                </div>
            </section>
        @elseif($scan['status_key'] === 'failed')
            <section class="rounded-lg border border-red-400/25 bg-red-400/10 px-4 py-3" role="alert"><h2 class="text-sm font-semibold text-red-200">Execution failed safely</h2><p class="mt-1 text-xs leading-5 text-red-200/90">No missing candidate or opportunity counts have been inferred as zero.</p></section>
        @elseif(in_array($scan['status_key'], ['pending', 'running'], true))
            <section class="rounded-lg border border-blue-400/20 bg-blue-400/8 px-4 py-3" role="status"><h2 class="text-sm font-semibold text-blue-200">{{ $scan['status_label'] }}</h2><p class="mt-1 text-xs leading-5 text-blue-200/90">This execution is still in progress. Counts and diagnostics may change.</p></section>
        @endif

        <section class="rounded-lg border border-slate-700/80 bg-slate-900/75 p-4 shadow-lg shadow-black/10" aria-labelledby="execution-lifecycle-heading">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div><h2 id="execution-lifecycle-heading" class="text-sm font-semibold text-white">Execution Lifecycle</h2><p class="mt-1 text-[11px] text-slate-500">The execution state and candidate outcomes are intentionally separate.</p></div>
                @if($scan['completed_at_display'])<p class="text-[11px] text-slate-500">Completed <time datetime="{{ $scan['completed_at_iso'] }}">{{ $scan['completed_at_display'] }}</time></p>@endif
            </div>

            <ol class="mt-4 grid gap-2 sm:grid-cols-5" aria-label="Scan execution lifecycle">
                @php
                    $states = ['pending' => 'Pending', 'running' => 'Running', 'completed' => 'Completed', 'completed_with_warnings' => 'Completed with warnings', 'failed' => 'Failed'];
                @endphp
                @foreach($states as $key => $label)
                    <li @class([
                        'flex min-h-12 items-center gap-2 rounded-md border px-3 text-xs',
                        'border-sky-400/30 bg-sky-400/10 text-sky-200' => $scan['status_key'] === $key,
                        'border-slate-800 bg-slate-950/20 text-slate-500' => $scan['status_key'] !== $key,
                    ]) @if($scan['status_key'] === $key) aria-current="step" @endif>
                        <span @class(['size-2 rounded-full', 'bg-sky-400' => $scan['status_key'] === $key, 'bg-slate-700' => $scan['status_key'] !== $key]) aria-hidden="true"></span>{{ $label }}
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="rounded-lg border border-slate-700/80 bg-slate-900/75 p-4 shadow-lg shadow-black/10" aria-labelledby="candidate-funnel-heading">
            <h2 id="candidate-funnel-heading" class="text-sm font-semibold text-white">Candidate Funnel</h2>
            <div class="mt-4 grid gap-2 sm:grid-cols-[1fr_auto_1fr_auto_1fr_auto_1fr] sm:items-center">
                @foreach([
                    ['Discovered', $scan['counts']['discovered'], 'text-white'],
                    ['Examined', $scan['counts']['examined'], 'text-white'],
                    ['Qualified', $scan['counts']['qualified'], 'text-emerald-300'],
                    ['Opportunities', $scan['counts']['opportunities'], 'text-sky-300'],
                ] as $index => [$label, $value, $tone])
                    @if($index > 0)<span class="hidden text-slate-600 sm:block" aria-hidden="true">→</span>@endif
                    <div class="rounded-md border border-slate-800 bg-slate-950/25 px-3 py-3 text-center"><p class="text-xl font-semibold tabular-nums {{ $value === null ? 'text-slate-600' : $tone }}">{{ $value === null ? '—' : number_format($value) }}</p><p class="mt-0.5 text-[10px] text-slate-500">{{ $label }}</p></div>
                @endforeach
            </div>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                @foreach([['Failed', $scan['counts']['failed'], 'text-red-300'], ['Skipped', $scan['counts']['skipped'], 'text-amber-300']] as [$label, $value, $tone])
                    <div class="rounded-md border border-slate-800 bg-slate-950/20 px-3 py-2.5"><p class="text-[10px] text-slate-500">Branch from examined</p><p class="mt-0.5 text-sm font-semibold tabular-nums {{ $value === null ? 'text-slate-600' : $tone }}">{{ $value === null ? '—' : number_format($value) }} {{ $label }}</p></div>
                @endforeach
            </div>
            @if(collect($scan['counts'])->contains(fn ($value): bool => $value === null))
                <p class="mt-3 text-[11px] text-slate-500">An em dash means the source contract did not report that metric; it does not mean zero.</p>
            @endif
        </section>

        <section class="rounded-lg border border-slate-700/80 bg-slate-900/75 p-4 shadow-lg shadow-black/10" aria-labelledby="candidate-diagnostics-heading">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <div><h2 id="candidate-diagnostics-heading" class="text-sm font-semibold text-white">Candidate Diagnostics</h2><p class="mt-1 text-[11px] text-slate-500">Safe, bounded candidate evidence from the authoritative execution owner.</p></div>
                @if($candidateDiagnostics['status'] === 'available')<span class="text-[11px] text-slate-500">{{ number_format($candidateDiagnostics['pagination']['total']) }} candidates</span>@endif
            </div>

            @if($candidateDiagnostics['status'] === 'available')
                @if($candidateDiagnostics['page_summary']['failed_rate_limited'] > 0 || $candidateDiagnostics['page_summary']['skipped_filters'] > 0)
                    <div class="mt-4 rounded-md border border-slate-800 bg-slate-950/25 px-3 py-2.5 text-[11px] leading-5 text-slate-300">
                        On this page,
                        @if($candidateDiagnostics['page_summary']['failed_rate_limited'] > 0)
                            <strong>{{ $candidateDiagnostics['page_summary']['failed_rate_limited'] }}</strong> candidates could not be evaluated because provider rate limiting is recorded on those candidate rows
                        @endif
                        @if($candidateDiagnostics['page_summary']['failed_rate_limited'] > 0 && $candidateDiagnostics['page_summary']['skipped_filters'] > 0)
                            , while
                        @endif
                        @if($candidateDiagnostics['page_summary']['skipped_filters'] > 0)
                            <strong>{{ $candidateDiagnostics['page_summary']['skipped_filters'] }}</strong> were skipped by configured eligibility filters
                        @endif
                        .
                    </div>
                @endif

                <div class="mt-4 overflow-x-auto rounded-md border border-slate-800" role="region" aria-label="Candidate diagnostics table" tabindex="0">
                    <table class="min-w-[66rem] w-full text-left text-[11px]">
                        <thead class="bg-slate-950/35 text-slate-400"><tr><th class="px-3 py-2.5 font-medium">Asset</th><th class="px-3 py-2.5 font-medium">Provider</th><th class="px-3 py-2.5 font-medium">Outcome</th><th class="px-3 py-2.5 font-medium">Reason</th><th class="px-3 py-2.5 font-medium">Market / Security</th><th class="px-3 py-2.5 font-medium">Opportunity</th></tr></thead>
                        <tbody class="divide-y divide-slate-800">
                            @forelse($candidateDiagnostics['candidates'] as $candidate)
                                @php
                                    $market = $candidate['market'];
                                    $shortAddress = mb_strlen($candidate['address']) > 16 ? mb_substr($candidate['address'], 0, 8).'…'.mb_substr($candidate['address'], -6) : $candidate['address'];
                                @endphp
                                <tr class="align-top bg-slate-900/20">
                                    <td class="px-3 py-3"><strong class="block text-slate-100">{{ $candidate['symbol'] ?? $candidate['name'] ?? 'Unknown token' }}</strong><span class="font-mono text-[10px] text-slate-500" title="{{ $candidate['address'] }}">{{ $shortAddress }}</span></td>
                                    <td class="px-3 py-3 text-slate-300">{{ ucfirst($candidate['provider']) }}</td>
                                    <td class="px-3 py-3"><span @class(['rounded px-2 py-1 text-[10px] font-semibold', 'bg-red-400/12 text-red-300' => in_array($candidate['state'], ['failed', 'qualified_downstream_failed'], true), 'bg-amber-400/12 text-amber-200' => $candidate['state'] === 'skipped', 'bg-emerald-400/12 text-emerald-300' => in_array($candidate['state'], ['qualified', 'opportunity_created', 'opportunity_existing'], true), 'bg-slate-700 text-slate-300' => $candidate['state'] === 'discovered'])>{{ $candidate['outcome_label'] }}</span></td>
                                    <td class="max-w-72 px-3 py-3"><span class="font-mono text-[10px] text-slate-300">{{ $candidate['reason_code'] ?? '—' }}</span>@if($candidate['reason_explanation'])<p class="mt-1 leading-4 text-slate-500">{{ $candidate['reason_explanation'] }}</p>@endif</td>
                                    <td class="px-3 py-3 text-slate-400">
                                        @if($market)
                                            <span class="block">Market cap {{ $market['market_cap_usd'] === null ? '—' : '$'.number_format($market['market_cap_usd'], 2) }}</span>
                                            <span class="block">Liquidity {{ $market['liquidity_usd'] === null ? '—' : '$'.number_format($market['liquidity_usd'], 2) }}</span>
                                            <span class="block">Holders {{ $market['holders'] === null ? '—' : number_format($market['holders']) }}</span>
                                        @else
                                            <span class="text-slate-600">Market not reported</span>
                                        @endif
                                        <span class="mt-1 block text-[10px] text-slate-500">Security: {{ str_replace('_', ' ', $candidate['security_status']) }}</span>
                                    </td>
                                    <td class="px-3 py-3">@if($candidate['opportunity_id'])<span class="font-mono text-[10px] text-sky-300" title="Engine opportunity ID">{{ $candidate['opportunity_id'] }}</span>@else<span class="text-slate-600">—</span>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-10 text-center text-xs text-slate-500">No candidate rows were recorded for this scan.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($candidateDiagnostics['pagination']['last_page'] > 1)
                    <nav class="mt-4 flex items-center justify-between text-xs" aria-label="Candidate diagnostics pagination">
                        @if($candidateDiagnostics['pagination']['page'] > 1)<a href="{{ route('scanner.show', ['activity' => $scan['id'], 'candidate_page' => $candidateDiagnostics['pagination']['page'] - 1]) }}" class="rounded-md border border-slate-700 px-3 py-2 text-slate-200 hover:bg-slate-800">Previous</a>@else<span></span>@endif
                        <span class="text-slate-500">Page {{ $candidateDiagnostics['pagination']['page'] }} of {{ $candidateDiagnostics['pagination']['last_page'] }}</span>
                        @if($candidateDiagnostics['pagination']['page'] < $candidateDiagnostics['pagination']['last_page'])<a href="{{ route('scanner.show', ['activity' => $scan['id'], 'candidate_page' => $candidateDiagnostics['pagination']['page'] + 1]) }}" class="rounded-md border border-slate-700 px-3 py-2 text-slate-200 hover:bg-slate-800">Next</a>@else<span></span>@endif
                    </nav>
                @endif
            @else
                <div class="mt-4 rounded-md border border-dashed border-slate-700 px-4 py-8 text-center"><p class="text-sm font-medium text-slate-300">Candidate details unavailable</p><p class="mt-1 text-xs leading-5 text-slate-500">{{ $candidateDiagnostics['message'] }}</p></div>
            @endif
        </section>

        <details class="rounded-lg border border-slate-800 bg-slate-900/50 px-4 py-3 text-xs">
            <summary class="cursor-pointer font-semibold text-slate-300">Technical ownership evidence</summary>
            <dl class="mt-3 grid gap-2 text-[11px] sm:grid-cols-2">
                <div><dt class="text-slate-500">Execution owner</dt><dd class="mt-0.5 text-slate-300">{{ $scan['source_owner'] }} · {{ $scan['source_owner_detail'] }}</dd></div>
                <div><dt class="text-slate-500">Activity ID</dt><dd class="mt-0.5 font-mono text-slate-300">{{ $scan['id'] }}</dd></div>
                @if($scan['engine_scan_execution_id'])<div><dt class="text-slate-500">Engine scan ID</dt><dd class="mt-0.5 font-mono text-slate-300">{{ $scan['engine_scan_execution_id'] }}</dd></div>@endif
                @if($scan['engine_delivery_status'])<div><dt class="text-slate-500">Projection delivery</dt><dd class="mt-0.5 text-slate-300">{{ str_replace('_', ' ', $scan['engine_delivery_status']) }}</dd></div>@endif
            </dl>
        </details>
    </div>
</x-layouts.shell>
