<section
    id="system-activity"
    data-status-url="{{ route('dashboard.activity') }}"
    aria-labelledby="activity-heading"
    class="flex flex-col gap-5 rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10 sm:p-6"
>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Laravel Commands</p>
            <h2 id="activity-heading" class="mt-1 text-xl font-semibold text-white">System Activity</h2>
        </div>
        <div class="text-right text-xs">
            <span id="activity-live-indicator" class="inline-flex rounded-full border border-slate-700 px-2.5 py-1 text-slate-400">Unknown</span>
            <p class="mt-1 text-slate-600">Last successful refresh: <span id="activity-last-refresh">Not yet refreshed</span></p>
        </div>
    </div>

    <div class="flex flex-col gap-3">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Pending and Running</p>
        <div id="current-activity-empty" class="{{ $currentActivities === [] ? '' : 'hidden' }} rounded-xl border border-dashed border-slate-700 px-5 py-6 text-center text-sm text-slate-500">
            No authorized dashboard operation is pending or running.
        </div>
        <div id="current-activity-list" class="{{ $currentActivities === [] ? 'hidden' : '' }} flex flex-col gap-2">
            @foreach($currentActivities as $activity)
                <article class="rounded-xl border border-blue-400/20 bg-blue-400/5 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold text-white">{{ $activity['label'] }}</h3>
                        <span class="rounded-full border border-blue-400/20 bg-blue-400/10 px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-blue-300">{{ $activity['status'] }}</span>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-slate-400">{{ $activity['summary'] }}</p>
                    <p class="mt-2 text-xs text-slate-500">Initiated by {{ $activity['initiated_by'] }}@if($activity['chain']) · {{ strtoupper($activity['chain']) }}@endif · {{ $activity['started_at'] ?? 'Waiting for Laravel queue worker' }} · {{ $activity['running_seconds'] === null ? 'Pending' : $activity['running_seconds'].'s' }}</p>
                </article>
            @endforeach
        </div>
    </div>

    <div class="flex flex-col gap-3 border-t border-slate-800 pt-4">
        <div class="flex items-center justify-between gap-4">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Recent Activity</p>
            <span id="recent-activity-count" class="text-xs text-slate-600">Latest {{ count($recentActivities) }}</span>
        </div>
        <div id="recent-activity-list" class="flex max-h-80 flex-col gap-2 overflow-y-auto">
            @forelse($recentActivities as $activity)
                <details class="group rounded-lg border border-slate-800 bg-slate-950/50">
                    <summary class="grid cursor-pointer list-none grid-cols-[1fr_auto] items-center gap-3 px-3 py-3 marker:hidden">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-slate-200">{{ $activity['label'] }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ ucfirst($activity['triggered_by']) }} · {{ $activity['initiated_by'] }}@if($activity['chain']) · {{ strtoupper($activity['chain']) }}@endif · {{ $activity['relative_time'] }}</span>
                        </span>
                        <span class="text-right">
                            <span @class([
                                'block text-xs font-semibold uppercase tracking-wider',
                                'text-amber-300' => $activity['status'] === 'pending',
                                'text-blue-300' => $activity['status'] === 'running',
                                'text-emerald-300' => $activity['status'] === 'completed',
                                'text-red-300' => $activity['status'] === 'failed',
                            ])>{{ $activity['status'] }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ $activity['duration_seconds'] !== null ? $activity['duration_seconds'].'s' : '—' }}</span>
                        </span>
                    </summary>
                    <p class="border-t border-slate-800 p-3 text-xs leading-5 text-slate-400">{{ $activity['summary'] }}</p>
                    <p class="border-t border-slate-800 px-3 py-2 text-xs text-slate-600">Started: {{ $activity['started_at'] ?? 'Not started' }} · Finished: {{ $activity['finished_at'] ?? 'Not finished' }}</p>
                </details>
            @empty
                <p class="rounded-lg border border-dashed border-slate-800 px-4 py-5 text-center text-sm text-slate-600">No activity recorded yet.</p>
            @endforelse
        </div>
    </div>

    <div class="grid gap-3 border-t border-slate-800 pt-4 text-xs sm:grid-cols-3">
        <div><p class="uppercase tracking-wider text-slate-600">Last Legacy Tracker Cycle</p><p id="last-tracker-check" class="mt-1 text-slate-300">{{ $systemStatus['last_tracker_check']?->diffForHumans() ?? 'Never' }}</p></div>
        <div><p class="uppercase tracking-wider text-slate-600">Last Laravel Momentum Scan</p><p id="last-momentum-scan" class="mt-1 text-slate-300">{{ $systemStatus['last_momentum_scan']?->diffForHumans() ?? 'Never' }}</p></div>
        <div><p class="uppercase tracking-wider text-slate-600">Last Laravel Token Scan</p><p id="last-token-scan" class="mt-1 text-slate-300">{{ $systemStatus['last_token_scan']?->diffForHumans() ?? 'Never' }}</p></div>
    </div>
    <p class="text-xs text-slate-500">Legacy Laravel tracker process lease: <span id="tracker-process-lease">{{ $systemStatus['process_lock_held'] ? 'held' : 'not held' }}</span> · Last process heartbeat: <span id="tracker-process-heartbeat">{{ $systemStatus['last_process_heartbeat'] ?? 'Never' }}</span>. This lease is unrelated to TypeScript workflow leadership.</p>
    <p class="text-xs text-slate-500">Unverified legacy positions across all batches: <span id="tracker-outstanding-unverified">{{ $systemStatus['outstanding_unverified_positions'] }}</span> · Last successful provider request: <span id="tracker-provider-success">{{ $systemStatus['last_successful_provider_request'] ?? 'Never' }}</span> · Last valid legacy position observation: <span id="tracker-valid-observation">{{ $systemStatus['last_successful_market_observation']?->diffForHumans() ?? 'Never' }}</span>.</p>
    <div class="grid gap-3 text-xs sm:grid-cols-4">
        <div><p class="uppercase tracking-wider text-slate-600">Legacy Cycle Duration</p><p id="tracker-cycle-duration" class="mt-1 text-slate-300">{{ $systemStatus['cycle_duration_ms'] !== null ? number_format($systemStatus['cycle_duration_ms'], 0).' ms' : 'N/A' }}</p></div>
        <div><p class="uppercase tracking-wider text-slate-600">Legacy Positions in Batch</p><p id="tracker-open-positions" class="mt-1 text-slate-300">{{ $systemStatus['open_positions'] ?? 'N/A' }}</p></div>
        <div><p class="uppercase tracking-wider text-slate-600">Valid Legacy Observations</p><p id="tracker-priced-positions" class="mt-1 text-slate-300">{{ $systemStatus['priced_positions'] !== null ? $systemStatus['priced_positions'].' / '.$systemStatus['open_positions'] : 'N/A' }}</p></div>
        <div><p class="uppercase tracking-wider text-slate-600">Legacy Provider Failures</p><p id="tracker-provider-failures" class="mt-1 text-slate-300">{{ $systemStatus['provider_failures'] ?? 'N/A' }}</p></div>
    </div>
</section>
