@php
    $engineHealthItems = [
        'connectivity' => ['TypeScript Engine Connectivity', $engineStatus['connectivity'], 'Authenticated HTTP health response'],
        'liveness' => ['Engine Liveness', $engineStatus['liveness'], 'Process response; not workflow leadership'],
        'readiness' => ['Engine Readiness', $engineStatus['readiness'], 'Dependency and schema readiness'],
        'postgres' => ['Engine PostgreSQL Dependency', $engineStatus['postgres'], 'Reported by authenticated readiness'],
        'workflow_leadership' => ['PostgreSQL Workflow Leader', $engineStatus['workflow_leadership'], $engineStatus['workflow_leadership_detail']],
    ];
@endphp

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" data-engine-health>
    @foreach($engineHealthItems as $key => [$label, $state, $detail])
        <article class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $label }}</p>
            <p id="engine-{{ str($key)->replace('_', '-') }}" @class([
                'mt-2 font-bold',
                'text-emerald-300' => in_array($state, ['ok', 'up', 'ready', 'reachable'], true),
                'text-amber-300' => in_array($state, ['unavailable', 'down', 'not_ready'], true),
                'text-slate-300' => in_array($state, ['unknown', 'not_available', 'disabled'], true),
            ])>{{ str($state)->replace('_', ' ')->upper() }}</p>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $detail }}</p>
        </article>
    @endforeach
</div>
<p class="mt-3 text-xs text-slate-500">
    Last successful authenticated engine response:
    <span id="engine-last-response">{{ $engineStatus['last_successful_response_at'] ? \Illuminate\Support\Carbon::parse($engineStatus['last_successful_response_at'])->diffForHumans() : 'Never' }}</span>.
    Engine health is cached briefly; leadership and engine backlog are not exposed by the current supported contract.
</p>
