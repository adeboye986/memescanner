<section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 sm:p-6" aria-labelledby="opportunity-lifecycle-heading">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-300">Durable workflow evidence</p>
        <h2 id="opportunity-lifecycle-heading" class="mt-2 text-lg font-semibold text-white">Opportunity lifecycle</h2>
        <p class="mt-1 text-sm text-slate-500">Only persisted relationships are shown. Unknown and unavailable stages are not inferred from timestamps.</p>
    </div>
    <ol class="mt-5 grid gap-3 lg:grid-cols-2">
        @foreach($engineLifecycle as $step)
            <li class="rounded-xl border border-slate-800 bg-slate-950/55 p-4">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-sm font-semibold text-slate-200">{{ $step['label'] }}</h3>
                    <span @class([
                        'shrink-0 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider',
                        'bg-emerald-400/10 text-emerald-300' => $step['status'] === 'complete',
                        'bg-blue-400/10 text-blue-300' => in_array($step['status'], ['pending', 'submitted'], true),
                        'bg-red-400/10 text-red-300' => $step['status'] === 'failed',
                        'bg-amber-400/10 text-amber-300' => $step['status'] === 'skipped',
                        'bg-slate-800 text-slate-400' => in_array($step['status'], ['unknown', 'not_available', 'not_applicable', 'projected'], true),
                    ])>{{ str($step['status'])->replace('_', ' ') }}</span>
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-500">{{ $step['detail'] }}</p>
                @if($step['occurred_at'])
                    <p class="mt-2 text-xs text-slate-600">{{ \Illuminate\Support\Carbon::parse($step['occurred_at'])->format('M j, Y H:i:s T') }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</section>
