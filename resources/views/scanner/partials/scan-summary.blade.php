@php
    $outcomeCounts = [
        ['label' => 'Qualified', 'value' => $scan['counts']['qualified'], 'color' => '#22c55e', 'text' => 'text-emerald-300'],
        ['label' => 'Failed', 'value' => $scan['counts']['failed'], 'color' => '#fb624d', 'text' => 'text-red-300'],
        ['label' => 'Skipped', 'value' => $scan['counts']['skipped'], 'color' => '#f5a524', 'text' => 'text-amber-300'],
    ];
    $outcomeAvailable = collect($outcomeCounts)->every(fn (array $item): bool => $item['value'] !== null);
    $outcomeTotal = $outcomeAvailable ? collect($outcomeCounts)->sum('value') : 0;
    $segmentOffset = 0.0;
@endphp

<section class="overflow-hidden rounded-lg border border-slate-700/80 bg-slate-900/75 shadow-lg shadow-black/10" aria-labelledby="latest-scan-heading">
    <div class="flex flex-col gap-2 px-4 py-3.5 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="latest-scan-heading" class="text-sm font-semibold text-white">Latest Scan Result</h2>
                @include('scanner.partials.status-badge', ['scan' => $scan])
            </div>
            <p class="mt-1 text-[11px] leading-5 text-slate-400">
                {{ $scan['scanner_label'] }} · {{ $scan['network_label'] }} · Submitted
                <time datetime="{{ $scan['submitted_at_iso'] }}">{{ $scan['submitted_at_display'] }}</time>
            </p>
        </div>
        <a href="{{ route('scanner.show', $scan['id']) }}" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-sky-300 transition hover:text-sky-200">
            View full details <span aria-hidden="true">→</span>
        </a>
    </div>

    <dl class="grid grid-cols-2 border-y border-slate-800 bg-slate-950/25 sm:grid-cols-5">
        @foreach([
            'examined' => ['Examined', 'text-white'],
            'qualified' => ['Qualified', 'text-emerald-300'],
            'failed' => ['Failed', 'text-red-300'],
            'skipped' => ['Skipped', 'text-white'],
            'provider_warning_count' => ['Provider incidents', 'text-white'],
        ] as $field => [$label, $tone])
            @php($value = $field === 'provider_warning_count' ? $scan['provider_warning_count'] : $scan['counts'][$field])
            <div class="flex min-h-16 flex-col justify-center border-b border-slate-800 px-3 py-2.5 text-center last:border-b-0 sm:border-r sm:border-b-0 sm:last:border-r-0">
                <dt class="order-2 mt-0.5 text-[10px] text-slate-400">{{ $label }}</dt>
                <dd class="order-1 text-xl font-semibold tabular-nums {{ $value === null ? 'text-slate-500' : $tone }}" @if($value === null) aria-label="Not reported" @endif>
                    {{ $value === null ? '—' : number_format($value) }}
                </dd>
            </div>
        @endforeach
    </dl>

    @if($scan['status_key'] === 'completed_with_warnings')
        <div class="m-3 flex gap-2.5 rounded-md border border-amber-400/25 bg-amber-400/10 px-3 py-2.5 text-xs leading-5 text-amber-100" role="status">
            <span aria-hidden="true">!</span>
            <div>
                <p>
                    The scan completed, but some evidence requires attention.
                    @if($scan['counts']['failed'] !== null)<strong>{{ number_format($scan['counts']['failed']) }}</strong> candidate evaluations failed.@endif
                    @if($scan['counts']['skipped'] !== null)<strong>{{ number_format($scan['counts']['skipped']) }}</strong> candidates were skipped by eligibility rules.@endif
                </p>
                @foreach($scan['provider_warnings'] as $warning)
                    <p class="mt-1 text-[11px] text-amber-200"><span class="font-mono">{{ $warning['code'] }}</span> × {{ $warning['count'] }} — {{ $warning['explanation'] }}</p>
                @endforeach
            </div>
        </div>
    @elseif($scan['status_key'] === 'failed')
        <p class="m-3 rounded-md border border-red-400/25 bg-red-400/10 px-3 py-2.5 text-xs leading-5 text-red-200">The execution failed safely. Candidate counts are shown only where the source reported them.</p>
    @elseif(in_array($scan['status_key'], ['pending', 'running'], true))
        <p class="m-3 rounded-md border border-blue-400/20 bg-blue-400/8 px-3 py-2.5 text-xs leading-5 text-blue-200">This scan is {{ strtolower($scan['status_label']) }}. Reported counts may still change.</p>
    @endif

    <div class="grid gap-4 border-t border-slate-800 px-4 py-4 sm:grid-cols-[11rem_minmax(0,1fr)] sm:items-center">
        <div>
            <h3 class="text-sm font-semibold text-white">Candidate Outcomes</h3>
            <p class="mt-1 text-[11px] leading-4 text-slate-500">Candidate dispositions are separate from the execution state.</p>
        </div>

        @if($outcomeAvailable && $outcomeTotal > 0)
            <div class="grid grid-cols-[6.5rem_minmax(0,1fr)] items-center gap-5">
                <div class="relative size-24" role="img" aria-label="{{ $outcomeTotal }} candidates: {{ collect($outcomeCounts)->map(fn (array $item): string => $item['value'].' '.$item['label'])->implode(', ') }}">
                    <svg viewBox="0 0 42 42" class="size-24 -rotate-90" aria-hidden="true">
                        <circle cx="21" cy="21" r="15.9" fill="none" stroke="currentColor" stroke-width="5" class="text-slate-800" />
                        @foreach($outcomeCounts as $item)
                            @php($percentage = $outcomeTotal > 0 ? ($item['value'] / $outcomeTotal) * 100 : 0)
                            @if($percentage > 0)
                                <circle cx="21" cy="21" r="15.9" fill="none" stroke="{{ $item['color'] }}" stroke-width="5" pathLength="100" stroke-dasharray="{{ $percentage }} {{ 100 - $percentage }}" stroke-dashoffset="{{ -$segmentOffset }}" />
                            @endif
                            @php($segmentOffset += $percentage)
                        @endforeach
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                        <strong class="text-base tabular-nums text-white">{{ number_format($outcomeTotal) }}</strong>
                        <span class="text-[9px] text-slate-500">Candidates</span>
                    </div>
                </div>
                <dl class="grid gap-2">
                    @foreach($outcomeCounts as $item)
                        <div class="grid grid-cols-[auto_1fr_auto] items-center gap-2 text-[11px]">
                            <span class="size-2 rounded-full" style="background-color: {{ $item['color'] }}" aria-hidden="true"></span>
                            <dt class="text-slate-300">{{ $item['label'] }}</dt>
                            <dd class="font-semibold tabular-nums {{ $item['text'] }}">{{ number_format($item['value']) }} ({{ number_format(($item['value'] / $outcomeTotal) * 100) }}%)</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @elseif($outcomeAvailable)
            <p class="rounded-md border border-dashed border-slate-700 px-4 py-5 text-center text-xs text-slate-400">No candidate outcomes were recorded for this scan.</p>
        @else
            <p class="rounded-md border border-dashed border-slate-700 px-4 py-5 text-center text-xs text-slate-400">Candidate outcome counts were not reported for this historical scan.</p>
        @endif
    </div>
</section>
