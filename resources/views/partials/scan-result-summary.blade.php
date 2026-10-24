@php($scanResult = $activity['result_summary'] ?? null)
@if(is_array($scanResult))
    <div class="mt-3 rounded-lg border border-slate-800 bg-slate-950/60 p-3">
        <dl class="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
            @foreach([
                'discovered' => 'Discovered',
                'examined' => 'Examined',
                'qualified' => 'Qualified',
                'skipped' => 'Skipped',
                'failed' => 'Failed',
                'exports_acknowledged' => 'Exported',
            ] as $field => $label)
                <div><dt class="text-[10px] uppercase tracking-wider text-slate-600">{{ $label }}</dt><dd class="mt-1 font-semibold tabular-nums text-slate-300">{{ $scanResult['counts'][$field] }}</dd></div>
            @endforeach
        </dl>
        <p class="mt-3 text-[11px] leading-5 text-slate-500">
            Engine exports:
            @foreach($scanResult['exports'] as $state => $count)
                <span class="whitespace-nowrap">{{ str($state)->replace('_', ' ') }} {{ $count }}</span>@if(! $loop->last) / @endif
            @endforeach
        </p>
        @if($scanResult['provider_failures'] !== [])
            <p class="mt-1 text-[11px] leading-5 text-amber-300/80">Provider warnings: @foreach($scanResult['provider_failures'] as $failure){{ $failure['code'] }} x{{ $failure['count'] }}@if(! $loop->last), @endif @endforeach</p>
        @endif
        <p class="mt-1 text-[11px] text-slate-600">Last structured progress: {{ $activity['last_progress_at'] ?? 'Unavailable' }}</p>
    </div>
@endif
