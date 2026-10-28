@php
    $toolsUrl = route('dashboard', ['view' => 'tools']);
    $walletsUrl = auth()->user()->is_admin ? $toolsUrl.'#wallets' : route('account.edit').'#wallets';
    $tradingSettingsUrl = auth()->user()->is_admin ? route('settings.index') : $toolsUrl.'#trading-preferences';
    $summaryCards = [
        [
            'label' => 'Total Scans',
            'value' => number_format($totalScans),
            'detail' => 'Authorized scanner runs',
            'tone' => 'sky',
        ],
        [
            'label' => 'New Opportunities',
            'value' => number_format($opportunitySummary['recent']),
            'detail' => $opportunitySummary['pending'].' pending confirmation',
            'tone' => 'emerald',
        ],
        [
            'label' => 'Legacy Positions',
            'value' => number_format($positions->count()),
            'detail' => 'Open funded PAPER',
            'tone' => 'amber',
        ],
        [
            'label' => 'Engine Positions',
            'value' => number_format($engineOpenPositions->count()),
            'detail' => 'Open PostgreSQL PAPER',
            'tone' => 'violet',
        ],
    ];
@endphp

<x-layouts.shell title="Overview" :administrator="(bool) auth()->user()->is_admin">
    <div class="mx-auto flex w-full max-w-[86rem] flex-col gap-4 sm:gap-5" data-overview-screen>
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.025em] text-white sm:text-[1.75rem]">
                {{ $greeting }}, {{ $greetingName }}!
            </h1>
            <p class="text-sm text-slate-400">Here’s what’s happening with your meme scanner.</p>
        </header>

        @if($onboardingStatus && ! $onboardingStatus['ready'])
            <section class="flex flex-col gap-3 rounded-lg border border-amber-400/25 bg-amber-400/8 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" aria-label="Setup required">
                <div>
                    <p class="text-sm font-semibold text-amber-200">Complete your setup</p>
                    <p class="mt-0.5 text-xs leading-5 text-slate-400">Verify your email and connect Telegram before relying on scanner notifications.</p>
                </div>
                <a href="{{ route('onboarding') }}" class="shrink-0 rounded-md bg-amber-300 px-3.5 py-2 text-center text-xs font-semibold text-slate-950">Continue setup</a>
            </section>
        @endif

        @if($executionMode === 'live')
            <div class="rounded-lg border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm font-medium text-red-200" role="status">
                LIVE supports CONFIRM only for eligible Ethereum opportunities. Selecting LIVE never executes a trade by itself.
            </div>
        @endif

        @if(session('success'))
            <div class="rounded-lg border border-emerald-400/25 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ session('success') }}</div>
        @endif
        @if(session('warning'))
            <div class="rounded-lg border border-amber-400/25 bg-amber-400/10 px-4 py-3 text-sm text-amber-200" role="status">{{ session('warning') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-lg border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-lg border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">
                <p class="font-semibold">Your changes could not be saved.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section aria-label="Overview summary" class="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
            @foreach($summaryCards as $card)
                <article @class([
                    'min-h-24 rounded-lg border bg-slate-900/70 p-4 shadow-sm shadow-black/10',
                    'border-sky-400/20 bg-gradient-to-br from-sky-400/8 to-slate-900/70' => $card['tone'] === 'sky',
                    'border-emerald-400/15 bg-gradient-to-br from-emerald-400/7 to-slate-900/70' => $card['tone'] === 'emerald',
                    'border-amber-400/15 bg-gradient-to-br from-amber-400/7 to-slate-900/70' => $card['tone'] === 'amber',
                    'border-violet-400/15 bg-gradient-to-br from-violet-400/7 to-slate-900/70' => $card['tone'] === 'violet',
                ])>
                    <p @class([
                        'text-[11px] font-medium',
                        'text-sky-300' => $card['tone'] === 'sky',
                        'text-emerald-300' => $card['tone'] === 'emerald',
                        'text-amber-300' => $card['tone'] === 'amber',
                        'text-violet-300' => $card['tone'] === 'violet',
                    ])>{{ $card['label'] }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-white">{{ $card['value'] }}</p>
                    <p class="mt-1 text-[11px] leading-4 text-slate-500">{{ $card['detail'] }}</p>
                </article>
            @endforeach
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-700/80 bg-slate-900/75 shadow-lg shadow-black/10" aria-labelledby="latest-scan-heading">
            <div class="flex flex-col gap-2 px-4 py-3.5 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="latest-scan-heading" class="text-base font-semibold text-white">Latest Scan</h2>
                        @if($latestScan)
                            <span @class([
                                'inline-flex rounded px-2 py-1 text-[10px] font-semibold leading-none',
                                'bg-amber-400/15 text-amber-200' => in_array($latestScan['overview_status']['key'], ['pending', 'completed_with_warnings'], true),
                                'bg-blue-400/15 text-blue-200' => $latestScan['overview_status']['key'] === 'running',
                                'bg-emerald-400/15 text-emerald-200' => $latestScan['overview_status']['key'] === 'completed',
                                'bg-red-400/15 text-red-200' => $latestScan['overview_status']['key'] === 'failed',
                                'bg-slate-700 text-slate-300' => $latestScan['overview_status']['key'] === 'unknown',
                            ])>{{ $latestScan['overview_status']['label'] }}</span>
                        @endif
                    </div>
                    @if($latestScan)
                        <p class="mt-1 text-xs text-slate-400">
                            {{ $latestScan['scanner_label'] }} · {{ $latestScan['chain_label'] }} · {{ $latestScan['relative_time'] }}
                        </p>
                    @else
                        <p class="mt-1 text-xs text-slate-400">No authorized scanner activity has been recorded.</p>
                    @endif
                </div>
                <a href="{{ $latestScan ? route('scanner.show', $latestScan['id']) : route('scanner.index', ['tab' => 'history']) }}" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-sky-300 transition hover:text-sky-200">
                    View details <span aria-hidden="true">→</span>
                </a>
            </div>

            @if($latestScan)
                <dl class="grid grid-cols-2 border-y border-slate-800 bg-slate-950/25 sm:grid-cols-5">
                    @foreach([
                        'examined' => ['Examined', 'text-white'],
                        'qualified' => ['Qualified', 'text-emerald-300'],
                        'failed' => ['Failed', 'text-red-300'],
                        'skipped' => ['Skipped', 'text-white'],
                        'provider_incidents' => ['Provider incidents', 'text-white'],
                    ] as $field => [$label, $tone])
                        @php
                            $value = $latestScan['overview_counts'][$field];
                        @endphp
                        <div class="flex flex-col border-b border-slate-800 px-3 py-3 text-center last:border-b-0 sm:border-r sm:border-b-0 sm:last:border-r-0">
                            <dt class="order-2 mt-0.5 text-[10px] text-slate-400">{{ $label }}</dt>
                            <dd class="order-1 text-xl font-semibold tabular-nums {{ $value === null ? 'text-slate-500' : $tone }}" @if($value === null) aria-label="Not reported" @endif>
                                {{ $value === null ? '—' : number_format($value) }}
                            </dd>
                        </div>
                    @endforeach
                </dl>

                @if($latestScan['overview_status']['key'] === 'failed')
                    <div class="m-3 flex gap-2.5 rounded-md border border-red-400/25 bg-red-400/10 px-3 py-2.5 text-xs leading-5 text-red-200">
                        <span aria-hidden="true">!</span>
                        <p>
                            The scan failed safely
                            @if($latestScan['error_code'])
                                with code <span class="font-mono">{{ $latestScan['error_code'] }}</span>
                            @endif.
                            No result has been inferred.
                        </p>
                    </div>
                @elseif($latestScan['overview_status']['key'] === 'completed_with_warnings')
                    <div class="m-3 flex gap-2.5 rounded-md border border-amber-400/25 bg-amber-400/10 px-3 py-2.5 text-xs leading-5 text-amber-100">
                        <span aria-hidden="true">!</span>
                        <div>
                            <p>Some scan evidence was recorded with warnings. Candidate and provider outcomes are shown separately.</p>
                            @if($latestScan['provider_failures'] !== [])
                                <p class="mt-1 text-[10px] text-amber-200">
                                    Provider: @foreach($latestScan['provider_failures'] as $failure)<span class="font-mono">{{ $failure['code'] }}</span> × {{ $failure['count'] }}@if(! $loop->last), @endif @endforeach
                                </p>
                            @endif
                        </div>
                    </div>
                @elseif(in_array($latestScan['overview_status']['key'], ['pending', 'running'], true))
                    <p class="m-3 rounded-md border border-blue-400/20 bg-blue-400/8 px-3 py-2.5 text-xs leading-5 text-blue-200">This scan is {{ strtolower($latestScan['overview_status']['label']) }}. Reported counts may still change.</p>
                @elseif(! $latestScan['structured_result_available'])
                    <p class="m-3 rounded-md border border-slate-700 bg-slate-950/30 px-3 py-2.5 text-xs leading-5 text-slate-400">Structured candidate counts were not reported for this historical scan.</p>
                @endif
            @else
                <div class="border-t border-slate-800 px-4 py-8 text-center">
                    <p class="text-sm font-medium text-slate-300">No scans yet</p>
                    <p class="mt-1 text-xs text-slate-500">Run an authorized scanner command to create the first result.</p>
                    <a href="{{ route('scanner.index') }}" class="mt-4 inline-flex rounded-md bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-blue-500">Run new scan</a>
                </div>
            @endif
        </section>

        <div class="grid items-start gap-3 lg:grid-cols-[minmax(0,1.22fr)_minmax(16rem,1fr)]">
            <section class="rounded-lg border border-slate-700/80 bg-slate-900/75 p-4 shadow-lg shadow-black/10" aria-labelledby="recent-activity-heading">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="recent-activity-heading" class="text-base font-semibold text-white">Recent Activity</h2>
                    <a href="{{ route('scanner.index', ['tab' => 'history']) }}" class="text-xs font-semibold text-sky-300 transition hover:text-sky-200">View all →</a>
                </div>

                <div class="mt-3 flex flex-col gap-3">
                    @forelse($overviewActivities as $activity)
                        @php
                            $isScanner = in_array($activity['action'], ['token-scan', 'momentum-scan'], true);
                            $result = $activity['result_summary'];
                            $activityCounts = [];
                            if ($isScanner && is_array($result)) {
                                foreach (['examined' => 'examined', 'failed' => 'failed', 'skipped' => 'skipped'] as $field => $label) {
                                    if (data_get($result, 'availability.counts.'.$field, false)) {
                                        $activityCounts[] = data_get($result, 'counts.'.$field).' '.$label;
                                    }
                                }
                            }
                        @endphp
                        <article class="grid grid-cols-[2rem_minmax(0,1fr)] gap-3">
                            <span @class([
                                'flex size-8 items-center justify-center rounded-full border',
                                'border-amber-400/30 bg-amber-400/15 text-amber-200' => in_array($activity['overview_status']['key'], ['pending', 'completed_with_warnings'], true),
                                'border-blue-400/30 bg-blue-400/15 text-blue-200' => $activity['overview_status']['key'] === 'running',
                                'border-emerald-400/30 bg-emerald-400/15 text-emerald-200' => $activity['overview_status']['key'] === 'completed',
                                'border-red-400/30 bg-red-400/15 text-red-200' => $activity['overview_status']['key'] === 'failed',
                                'border-slate-700 bg-slate-800 text-slate-300' => $activity['overview_status']['key'] === 'unknown',
                            ])>
                                <x-app-icon :name="$isScanner ? 'search' : 'trading'" :size="15" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold text-slate-100">
                                    {{ $isScanner ? 'Scan '.$activity['overview_status']['label'] : $activity['label'] }}
                                </p>
                                <p class="mt-0.5 truncate text-[11px] text-slate-400">
                                    {{ $activityCounts !== [] ? implode(' · ', $activityCounts) : $activity['overview_status']['label'] }}
                                </p>
                                <p class="mt-0.5 text-[10px] text-slate-500">{{ $activity['initiated_by'] }} · {{ $activity['relative_time'] }}</p>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-md border border-dashed border-slate-700 px-4 py-7 text-center">
                            <p class="text-sm font-medium text-slate-300">No recent activity</p>
                            <p class="mt-1 text-xs text-slate-500">Authorized scanner and PAPER operations will appear here.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-slate-700/80 bg-slate-900/75 p-4 shadow-lg shadow-black/10" aria-labelledby="quick-actions-heading">
                <h2 id="quick-actions-heading" class="text-base font-semibold text-white">Quick Actions</h2>
                <nav class="mt-3 flex flex-col gap-2" aria-label="Overview quick actions">
                    <a href="{{ route('scanner.index') }}" class="flex min-h-10 items-center gap-2 rounded-md bg-blue-600 px-3 text-xs font-semibold text-white shadow-sm shadow-blue-950/30 transition hover:bg-blue-500">
                        <x-app-icon name="search" :size="16" /> Run New Scan
                    </a>
                    <a href="{{ route('opportunities.index') }}" class="flex min-h-10 items-center gap-2 rounded-md border border-slate-700 bg-slate-950/20 px-3 text-xs font-semibold text-slate-200 transition hover:border-slate-600 hover:bg-slate-800">
                        <x-app-icon name="opportunity" :size="16" /> View Opportunities
                    </a>
                    <a href="{{ $walletsUrl }}" class="flex min-h-10 items-center gap-2 rounded-md border border-slate-700 bg-slate-950/20 px-3 text-xs font-semibold text-slate-200 transition hover:border-slate-600 hover:bg-slate-800">
                        <x-app-icon name="wallet" :size="16" /> Manage Wallets
                    </a>
                    <a href="{{ $tradingSettingsUrl }}" class="flex min-h-10 items-center gap-2 rounded-md border border-slate-700 bg-slate-950/20 px-3 text-xs font-semibold text-slate-200 transition hover:border-slate-600 hover:bg-slate-800">
                        <x-app-icon name="settings" :size="16" /> Trading Settings
                    </a>
                </nav>
            </section>
        </div>
    </div>
</x-layouts.shell>
