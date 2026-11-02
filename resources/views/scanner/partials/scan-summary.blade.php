@php
    $hasScan = is_array($scan);
    $scanState = $hasScan ? $scan['status_key'] : 'empty';
    $outcomeCounts = [
        ['label' => 'Qualified', 'value' => $hasScan ? $scan['counts']['qualified'] : null, 'color' => '#22c55e', 'text' => 'text-emerald-300'],
        ['label' => 'Failed', 'value' => $hasScan ? $scan['counts']['failed'] : null, 'color' => '#fb624d', 'text' => 'text-red-300'],
        ['label' => 'Skipped', 'value' => $hasScan ? $scan['counts']['skipped'] : null, 'color' => '#f5a524', 'text' => 'text-amber-300'],
    ];
    $outcomeAvailable = $hasScan && collect($outcomeCounts)->every(fn (array $item): bool => $item['value'] !== null);
    $outcomeTotal = $outcomeAvailable ? collect($outcomeCounts)->sum('value') : 0;
    $segmentOffset = 0.0;
    $metrics = [
        'examined' => ['Examined', 'text-white'],
        'qualified' => ['Qualified', 'text-emerald-300'],
        'failed' => ['Failed', 'text-red-300'],
        'skipped' => ['Skipped', 'text-white'],
        'provider_warning_count' => ['Provider incidents', 'text-white'],
    ];
@endphp

<section
    class="overflow-hidden rounded-lg border border-slate-700/80 bg-slate-900/80 shadow-lg shadow-black/10"
    aria-labelledby="latest-scan-heading"
    data-latest-scan-card
    data-latest-scan-state="{{ $scanState }}"
>
    <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="latest-scan-heading" class="text-sm font-semibold text-white">Latest Scan Result</h2>
                @if($hasScan)
                    @include('scanner.partials.status-badge', ['scan' => $scan])
                @else
                    <span class="inline-flex items-center rounded-full border border-slate-600/80 bg-slate-800/80 px-2 py-0.5 text-[10px] font-semibold text-slate-300">No scan yet</span>
                @endif
            </div>
            @if($hasScan)
                <p class="mt-1 truncate text-[10px] leading-4 text-slate-500">
                    Execution ID:
                    <span class="font-mono text-slate-400">{{ $scan['engine_scan_execution_id'] ?: 'Laravel activity #'.$scan['id'] }}</span>
                </p>
                <p class="text-[10px] leading-4 text-slate-500">
                    {{ $scan['scanner_label'] }} · {{ $scan['network_label'] }} ·
                    <time datetime="{{ $scan['submitted_at_iso'] }}">{{ $scan['submitted_at_display'] }}</time>
                </p>
            @else
                <p class="mt-1 text-[11px] leading-4 text-slate-500">No authorized scanner result is available yet.</p>
            @endif
        </div>
        <a
            href="{{ $hasScan ? route('scanner.show', $scan['id']) : route('scanner.index', ['tab' => 'history']) }}"
            class="inline-flex shrink-0 items-center gap-1 text-[11px] font-semibold text-sky-300 transition hover:text-sky-200"
        >
            {{ $hasScan ? 'View full details' : 'View scan history' }} <span aria-hidden="true">→</span>
        </a>
    </div>

    <dl class="grid grid-cols-2 border-y border-slate-800 bg-slate-950/25 sm:grid-cols-5">
        @foreach($metrics as $field => [$label, $tone])
            @php($value = $hasScan ? ($field === 'provider_warning_count' ? $scan['provider_warning_count'] : $scan['counts'][$field]) : null)
            <div class="flex min-h-14 flex-col justify-center border-b border-slate-800 px-2 py-2 text-center last:border-b-0 sm:border-r sm:border-b-0 sm:last:border-r-0">
                <dt class="order-2 mt-0.5 text-[10px] text-slate-400">{{ $label }}</dt>
                <dd class="order-1 text-lg font-semibold tabular-nums {{ $value === null ? 'text-slate-500' : $tone }}" @if($value === null) aria-label="Not reported" @endif>
                    {{ $value === null ? '—' : number_format($value) }}
                </dd>
            </div>
        @endforeach
    </dl>

    @if($hasScan && $scan['status_key'] === 'completed_with_warnings')
        <div class="m-3 flex gap-2.5 rounded-md border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-[11px] leading-4 text-amber-100" role="status">
            <span class="mt-0.5 font-bold" aria-hidden="true">!</span>
            <div class="min-w-0">
                <p>
                    <strong>Completed with warnings.</strong>
                    @if($scan['counts']['failed'] !== null)<strong>{{ number_format($scan['counts']['failed']) }}</strong> candidate {{ $scan['counts']['failed'] === 1 ? 'evaluation' : 'evaluations' }} failed.@endif
                    @if($scan['provider_warning_count'] !== null)<strong>{{ number_format($scan['provider_warning_count']) }}</strong> provider {{ $scan['provider_warning_count'] === 1 ? 'incident was' : 'incidents were' }} recorded.@endif
                    @if($scan['counts']['skipped'] !== null)<strong>{{ number_format($scan['counts']['skipped']) }}</strong> {{ $scan['counts']['skipped'] === 1 ? 'candidate was' : 'candidates were' }} skipped separately by eligibility rules.@endif
                </p>
                @if($scan['provider_warnings'] !== [])
                    <p class="mt-1 text-[10px] text-amber-200">
                        Provider warnings:
                        @foreach($scan['provider_warnings'] as $warning)
                            <span class="font-mono">{{ $warning['code'] }}</span> × {{ $warning['count'] }}@if(! $loop->last), @endif
                        @endforeach
                    </p>
                @endif
            </div>
        </div>
    @elseif($hasScan && $scan['status_key'] === 'failed')
        <p class="m-3 rounded-md border border-red-400/25 bg-red-400/10 px-3 py-2 text-[11px] leading-4 text-red-200">The execution failed safely. Candidate counts are shown only where the source reported them.</p>
    @elseif($hasScan && in_array($scan['status_key'], ['pending', 'running'], true))
        <p class="m-3 rounded-md border border-blue-400/20 bg-blue-400/8 px-3 py-2 text-[11px] leading-4 text-blue-200">This scan is {{ strtolower($scan['status_label']) }}. Reported counts may still change.</p>
    @elseif(! $hasScan)
        <p class="m-3 rounded-md border border-slate-700/80 bg-slate-950/25 px-3 py-2 text-[11px] leading-4 text-slate-400">Start an authorized scan above to populate this card. Nothing runs automatically.</p>
    @endif

    <div class="border-t border-slate-800 px-4 py-3.5">
        <div class="flex flex-col gap-0.5 sm:flex-row sm:items-baseline sm:justify-between">
            <h3 class="text-sm font-semibold text-white">Candidate Outcomes</h3>
            <p class="text-[10px] leading-4 text-slate-500">Candidate dispositions are separate from execution status.</p>
        </div>

        @if($outcomeAvailable && $outcomeTotal > 0)
            <div class="mt-3 grid grid-cols-[5rem_minmax(0,1fr)] items-center gap-4 sm:max-w-md">
                <div class="relative size-20" role="img" aria-label="{{ $outcomeTotal }} candidates: {{ collect($outcomeCounts)->map(fn (array $item): string => $item['value'].' '.$item['label'])->implode(', ') }}">
                    <svg viewBox="0 0 42 42" class="size-20 -rotate-90" aria-hidden="true">
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
                        <strong class="text-sm tabular-nums text-white">{{ number_format($outcomeTotal) }}</strong>
                        <span class="text-[9px] text-slate-500">Candidates</span>
                    </div>
                </div>
                <dl class="grid gap-1.5">
                    @foreach($outcomeCounts as $item)
                        <div class="grid grid-cols-[auto_1fr_auto] items-center gap-2 text-[10px]">
                            <span class="size-2 rounded-full" style="background-color: {{ $item['color'] }}" aria-hidden="true"></span>
                            <dt class="text-slate-300">{{ $item['label'] }}</dt>
                            <dd class="font-semibold tabular-nums {{ $item['text'] }}">{{ number_format($item['value']) }} ({{ number_format(($item['value'] / $outcomeTotal) * 100) }}%)</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @elseif($outcomeAvailable)
            <p class="mt-3 rounded-md border border-dashed border-slate-700 px-4 py-3 text-center text-[11px] text-slate-400">No candidate outcomes were recorded for this scan.</p>
        @elseif($hasScan)
            <p class="mt-3 rounded-md border border-dashed border-slate-700 px-4 py-3 text-center text-[11px] text-slate-400">Candidate outcome counts were not reported for this historical scan.</p>
        @else
            <div class="mt-3 grid grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-4 rounded-md border border-dashed border-slate-700/80 px-3 py-3">
                <div class="relative size-14 rounded-full border-[5px] border-slate-800" aria-hidden="true">
                    <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold text-slate-600">—</span>
                </div>
                <p class="text-[11px] leading-4 text-slate-400">Candidate outcomes will appear here after the first scan reports them.</p>
            </div>
        @endif
    </div>
</section>
