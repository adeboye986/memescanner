<x-layouts.app title="Paper Trading Dashboard">
    <header class="flex flex-col gap-5 border-b border-slate-800 pb-7 lg:flex-row lg:items-end lg:justify-between">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-semibold uppercase tracking-[0.24em] text-emerald-400">Meme Scanner</p>
            <h1 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">Paper Trading Dashboard</h1>
            <p class="max-w-2xl text-sm leading-6 text-slate-400">Monitor funded positions and manage manual exits without interrupting automatic tracking.</p>
        </div>

        <div class="flex flex-wrap gap-3 text-xs font-semibold uppercase tracking-wider">
            <span class="inline-flex items-center gap-2 rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-2 text-sky-300">
                <span class="size-2 rounded-full bg-sky-400"></span>
                Trading Mode: {{ strtoupper($executionMode) }}
            </span>
            <span class="inline-flex items-center rounded-full border border-violet-400/20 bg-violet-400/10 px-3 py-2 text-violet-300">Entry: {{ strtoupper($entryMode) }}</span>
            <span id="tracker-status-badge" @class([
                'inline-flex items-center gap-2 rounded-full border px-3 py-2',
                'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => $systemStatus['status'] === 'active',
                'border-amber-400/20 bg-amber-400/10 text-amber-300' => $systemStatus['status'] === 'stale',
                'border-slate-700 bg-slate-800 text-slate-300' => $systemStatus['status'] === 'unknown',
            ])>
                <span id="tracker-status-dot" @class([
                    'size-2 rounded-full',
                    'bg-emerald-400' => $systemStatus['status'] === 'active',
                    'bg-amber-400' => $systemStatus['status'] === 'stale',
                    'bg-slate-500' => $systemStatus['status'] === 'unknown',
                ])></span>
                Fast Tracker: <span id="tracker-status-text">{{ strtoupper($systemStatus['status']) }}</span>
            </span>
        </div>
    </header>

    @if ($executionMode === 'live')
        <div class="rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm font-semibold text-red-200">LIVE EXECUTION NOT YET ENABLED — all live orders are blocked server-side.</div>
    @endif

    @if (session('success'))
        <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ session('success') }}</div>
    @endif
    @if (session('warning'))
        <div class="rounded-xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm text-amber-200" role="status">{{ session('warning') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">
            <p class="font-semibold">The strategy could not be saved.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section aria-label="Platform workflow summary" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <article class="rounded-2xl border border-sky-400/15 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Trading Mode</p><p class="mt-2 text-lg font-bold text-sky-300">{{ strtoupper($executionMode) }}</p><p class="mt-1 text-xs text-slate-500">{{ $executionMode === 'paper' ? 'Simulated funds' : 'Execution locked' }}</p></article>
        <article class="rounded-2xl border border-violet-400/15 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Entry Mode</p><p class="mt-2 text-lg font-bold text-violet-300">{{ str($entryMode)->replace('_', ' ')->upper() }}</p><p class="mt-1 text-xs text-slate-500">Qualification policy</p></article>
        <article class="rounded-2xl border border-amber-400/15 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Opportunities · 24h</p><p class="mt-2 text-lg font-bold text-white">{{ $opportunitySummary['recent'] }}</p><p class="mt-1 text-xs text-amber-300">{{ $opportunitySummary['pending'] }} pending confirmation</p></article>
        <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Tracker</p><p class="mt-2 text-lg font-bold {{ $systemStatus['status'] === 'active' ? 'text-emerald-300' : ($systemStatus['status'] === 'stale' ? 'text-amber-300' : 'text-slate-300') }}">{{ strtoupper($systemStatus['status']) }}</p><p class="mt-1 text-xs text-slate-500">Fast position monitoring</p></article>
        <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Integrations</p><p class="mt-2 text-lg font-bold text-emerald-300">{{ collect($integrationSummary)->whereIn('status', ['configured', 'active'])->count() }} / {{ count($integrationSummary) }}</p><p class="mt-1 text-xs text-slate-500">Configured or active</p></article>
    </section>

    <section aria-labelledby="wallet-heading" class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Portfolio</p>
                <h2 id="wallet-heading" class="mt-1 text-xl font-semibold text-white">Wallet Summary</h2>
            </div>
            <span class="text-sm text-slate-500">Virtual paper balances</span>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            @foreach ($wallets as $chain => $wallet)
                @php
                    $currency = $wallet->currencyCode();
                @endphp
                <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold uppercase tracking-wider text-white">{{ $wallet->chain->label() }} Paper Wallet</h3>
                        <span class="rounded-full bg-violet-400/10 px-2.5 py-1 text-xs font-semibold text-violet-300">{{ $currency }}</span>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div><dt class="text-xs uppercase text-slate-500">Starting</dt><dd class="mt-2 font-semibold text-white">{{ number_format((float) $wallet->starting_balance_sol, 4) }} {{ $currency }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">Available</dt><dd class="mt-2 font-semibold text-emerald-300">{{ number_format((float) $wallet->available_balance_sol, 4) }} {{ $currency }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">Invested</dt><dd class="mt-2 font-semibold text-amber-300">{{ number_format((float) $wallet->invested_balance_sol, 4) }} {{ $currency }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">Realized P/L</dt><dd class="mt-2 font-semibold {{ (float) $wallet->realized_pnl_sol >= 0 ? 'text-emerald-300' : 'text-red-300' }}">{{ sprintf('%+.4f %s', (float) $wallet->realized_pnl_sol, $currency) }}</dd></div>
                    </dl>
                    <p class="mt-4 border-t border-slate-800 pt-3 text-xs text-slate-500">{{ $positions->filter(fn ($trade) => $trade['model']->chain->value === $chain)->count() }} open funded positions</p>
                </article>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="strategy-heading" class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10 sm:p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Risk Management</p>
                <h2 id="strategy-heading" class="mt-1 text-xl font-semibold text-white">Paper Trading Strategy</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-400">These defaults are snapshotted when a new position opens. Existing positions keep their own strategy.</p>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded-xl bg-red-400/10 px-3 py-2 text-red-300"><span class="block text-slate-500">Stop Loss</span>-{{ number_format($paperStrategy['stop_loss_percent'], 2) }}%</div>
                <div class="rounded-xl bg-amber-400/10 px-3 py-2 text-amber-300"><span class="block text-slate-500">Level 1</span>+{{ number_format($paperStrategy['protection_level_1_percent'], 2) }}%</div>
                <div class="rounded-xl bg-emerald-400/10 px-3 py-2 text-emerald-300"><span class="block text-slate-500">Level 2</span>+{{ number_format($paperStrategy['protection_level_2_percent'], 2) }}%</div>
            </div>
        </div>

        @can('manage-settings')
        <form method="POST" action="{{ route('dashboard.paper-strategy.update') }}" class="mt-6 grid gap-4 md:grid-cols-3 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end">
            @csrf
            <label class="flex flex-col gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Stop Loss %
                <input name="stop_loss_percent" type="number" min="0.01" max="99.99" step="0.01" required value="{{ old('stop_loss_percent', $paperStrategy['stop_loss_percent']) }}" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm font-semibold normal-case text-slate-100 focus:border-emerald-400 focus:outline-none">
            </label>
            <label class="flex flex-col gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Protection Level 1 %
                <input name="protection_level_1_percent" type="number" min="0.01" step="0.01" required value="{{ old('protection_level_1_percent', $paperStrategy['protection_level_1_percent']) }}" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm font-semibold normal-case text-slate-100 focus:border-emerald-400 focus:outline-none">
            </label>
            <label class="flex flex-col gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Protection Level 2 %
                <input name="protection_level_2_percent" type="number" min="0.02" step="0.01" required value="{{ old('protection_level_2_percent', $paperStrategy['protection_level_2_percent']) }}" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm font-semibold normal-case text-slate-100 focus:border-emerald-400 focus:outline-none">
            </label>
            <button type="submit" class="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300">Save Strategy</button>
        </form>
        @else
            <p class="mt-5 text-sm text-slate-500">Sign in as an administrator to change strategy settings.</p>
        @endcan
    </section>

    <div class="grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
        <section aria-labelledby="controls-heading" class="flex flex-col gap-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10 sm:p-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Manual Operations</p>
                <h2 id="controls-heading" class="mt-1 text-xl font-semibold text-white">Scanner Controls</h2>
                <p class="mt-2 text-sm text-slate-400">Commands run through the queue. Scheduled operations continue independently.</p>
            </div>

            @php
                $actionDescriptions = [
                    'token-scan' => 'Scan newly launched tokens',
                    'momentum-scan' => 'Evaluate momentum candidates',
                    'paper-track' => 'Refresh all open paper positions',
                    'paper-report' => 'Generate current paper performance',
                    'paper-reconcile' => 'Check wallet ledger without fixing it',
                ];
            @endphp

            <label class="flex max-w-xs flex-col gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                Chain
                <select id="scanner-chain" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm font-semibold normal-case tracking-normal text-slate-100 focus:border-emerald-400 focus:outline-none">
                    <option value="solana">Solana</option>
                    <option value="ethereum">Ethereum</option>
                </select>
            </label>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($dashboardActions as $action => $definition)
                    @php
                        $isChainAction = in_array($action, ['token-scan', 'momentum-scan'], true);
                        $actionKey = $isChainAction ? $action.':solana' : $action;
                    @endphp
                    <form method="POST" action="{{ route('dashboard.actions.store', $action) }}" class="dashboard-action-form {{ $action === 'paper-reconcile' ? 'sm:col-span-2' : '' }}" data-action="{{ $action }}" data-action-key="{{ $actionKey }}" @if($isChainAction) data-chain-action @endif>
                        @csrf
                        @if ($isChainAction)
                            <input type="hidden" name="chain" value="solana" data-chain-input>
                        @endif
                        <button
                            type="submit"
                            @disabled(in_array($actionKey, $runningActions, true))
                            class="group flex w-full items-center justify-between gap-4 rounded-xl border border-slate-700 bg-slate-950/60 px-4 py-4 text-left transition hover:border-slate-600 hover:bg-slate-800/80 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span>
                                <span class="block text-sm font-semibold text-slate-100">{{ $definition['label'] }}</span>
                                <span class="mt-1 block text-xs text-slate-500">{{ $actionDescriptions[$action] }}</span>
                            </span>
                            <span class="action-state shrink-0 text-xs font-semibold uppercase tracking-wider text-emerald-300">
                                {{ in_array($actionKey, $runningActions, true) ? 'Running' : 'Run' }}
                            </span>
                        </button>
                    </form>
                @endforeach
            </div>
        </section>

        <section
            id="system-activity"
            data-status-url="{{ route('dashboard.activity') }}"
            aria-labelledby="activity-heading"
            class="flex flex-col gap-5 rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10 sm:p-6"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Command Monitor</p>
                    <h2 id="activity-heading" class="mt-1 text-xl font-semibold text-white">System Activity</h2>
                </div>
                <span id="activity-live-indicator" class="rounded-full border border-slate-700 px-2.5 py-1 text-xs text-slate-400">Live</span>
            </div>

            <div class="flex flex-col gap-3">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Current Activity</p>
                <div id="current-activity-empty" class="{{ $currentActivity ? 'hidden' : '' }} rounded-xl border border-dashed border-slate-700 px-5 py-6 text-center text-sm text-slate-500">
                    No manual operation currently running.
                </div>
                <div id="current-activity-content" class="{{ $currentActivity ? '' : 'hidden' }} rounded-xl border border-blue-400/20 bg-blue-400/5 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 id="current-activity-label" class="font-semibold text-white">⚡ {{ $currentActivity['label'] ?? '' }}</h3>
                        <span id="current-activity-status" class="rounded-full border border-blue-400/20 bg-blue-400/10 px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-blue-300">{{ $currentActivity['status'] ?? '' }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-sm">
                        <p><span class="text-slate-500">Started:</span> <span id="current-activity-started" class="font-medium text-slate-200">{{ $currentActivity['started_at'] ?? 'Waiting for worker' }}</span></p>
                        <p><span class="text-slate-500">Running for:</span> <span id="current-activity-running" class="font-medium text-slate-200">{{ isset($currentActivity['running_seconds']) ? $currentActivity['running_seconds'].'s' : 'Pending' }}</span></p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3 border-t border-slate-800 pt-4">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Recent Activity</p>
                    <span class="text-xs text-slate-600">Latest {{ count($recentActivities) }}</span>
                </div>
                <div id="recent-activity-list" class="flex max-h-80 flex-col gap-2 overflow-y-auto">
                    @forelse ($recentActivities as $activity)
                        <details class="group rounded-lg border border-slate-800 bg-slate-950/50">
                            <summary class="grid cursor-pointer list-none grid-cols-[1fr_auto] items-center gap-3 px-3 py-3 marker:hidden">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-slate-200">{{ $activity['label'] }}</span>
                                    <span class="mt-1 block text-xs text-slate-500">{{ ucfirst($activity['triggered_by']) }} · {{ $activity['relative_time'] }}</span>
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
                            <pre class="max-h-64 overflow-auto border-t border-slate-800 p-3 font-mono text-xs leading-5 whitespace-pre-wrap text-slate-400">{{ $activity['output'] ?: 'No output captured yet.' }}</pre>
                        </details>
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-800 px-4 py-5 text-center text-sm text-slate-600">No activity recorded yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="grid gap-3 border-t border-slate-800 pt-4 text-xs sm:grid-cols-3">
                <div><p class="uppercase tracking-wider text-slate-600">Last Fast Cycle</p><p id="last-tracker-check" class="mt-1 text-slate-300">{{ $systemStatus['last_tracker_check']?->diffForHumans() ?? 'Never' }}</p></div>
                <div><p class="uppercase tracking-wider text-slate-600">Last Momentum Scan</p><p id="last-momentum-scan" class="mt-1 text-slate-300">{{ $systemStatus['last_momentum_scan']?->diffForHumans() ?? 'Never' }}</p></div>
                <div><p class="uppercase tracking-wider text-slate-600">Last Token Scan</p><p id="last-token-scan" class="mt-1 text-slate-300">{{ $systemStatus['last_token_scan']?->diffForHumans() ?? 'Never' }}</p></div>
            </div>
            <div class="grid gap-3 text-xs sm:grid-cols-4">
                <div><p class="uppercase tracking-wider text-slate-600">Cycle Duration</p><p id="tracker-cycle-duration" class="mt-1 text-slate-300">{{ $systemStatus['cycle_duration_ms'] !== null ? number_format($systemStatus['cycle_duration_ms'], 0).' ms' : 'N/A' }}</p></div>
                <div><p class="uppercase tracking-wider text-slate-600">Open Positions</p><p id="tracker-open-positions" class="mt-1 text-slate-300">{{ $systemStatus['open_positions'] ?? 'N/A' }}</p></div>
                <div><p class="uppercase tracking-wider text-slate-600">Priced</p><p id="tracker-priced-positions" class="mt-1 text-slate-300">{{ $systemStatus['priced_positions'] !== null ? $systemStatus['priced_positions'].' / '.$systemStatus['open_positions'] : 'N/A' }}</p></div>
                <div><p class="uppercase tracking-wider text-slate-600">Provider Failures</p><p id="tracker-provider-failures" class="mt-1 text-slate-300">{{ $systemStatus['provider_failures'] ?? 'N/A' }}</p></div>
            </div>
        </section>
    </div>

    <section aria-labelledby="positions-heading" class="flex flex-col gap-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Positions</p>
                <h2 id="positions-heading" class="mt-1 text-xl font-semibold text-white">Open Trades</h2>
            </div>
            <p class="text-sm text-slate-500">Market values reflect the latest tracker check.</p>
        </div>

        @forelse ($positions as $trade)
            @php
                $position = $trade['model'];
                $isProfitable = $trade['current_return'] >= 0;
            @endphp
            <article class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/70 shadow-xl shadow-black/10">
                <div class="flex flex-col gap-5 border-b border-slate-800 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-slate-800 text-lg font-bold text-white">
                            {{ mb_substr($position->symbol ?: '?', 0, 2) }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-xl font-semibold text-white">{{ $position->symbol ?: 'Unknown token' }}</h3>
                                <span class="rounded-full bg-violet-400/10 px-2.5 py-1 text-xs font-semibold uppercase text-violet-300">{{ $position->chain->label() }}</span>
                                <span class="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-semibold text-amber-300">{{ $trade['protection_state'] }}</span>
                            </div>
                            <p class="mt-1 truncate font-mono text-xs text-slate-500" title="{{ $position->address }}">{{ $position->address }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-5">
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">Current Return</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums {{ $isProfitable ? 'text-emerald-300' : 'text-red-300' }}">{{ sprintf('%+.2f%%', $trade['current_return']) }}</p>
                        </div>
                        <button
                            type="button"
                            class="open-close-modal rounded-lg bg-red-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-red-950/30 transition hover:bg-red-400 focus:outline-none focus:ring-2 focus:ring-red-300"
                            data-action="{{ route('paper-trades.close', $position) }}"
                            data-symbol="{{ $position->symbol ?: 'Unknown token' }}"
                            data-return="{{ sprintf('%+.2f%%', $trade['current_return']) }}"
                            data-remaining="{{ number_format($trade['remaining_fraction'] * 100, 2) }}%"
                            data-value="{{ number_format($trade['current_value'], 4) }} {{ $trade['currency'] }}"
                        >Close Trade</button>
                    </div>
                </div>

                <div class="grid gap-px bg-slate-800 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                    @php
                        $metrics = [
                            ['Entry Market Cap', '$'.number_format($trade['entry_market_cap'], 2), 'neutral'],
                            ['Latest Market Cap', '$'.number_format($trade['current_market_cap'], 2), 'neutral'],
                            ['Position Multiple', number_format($trade['current_multiple'], 2).'x', $isProfitable ? 'profit' : 'loss'],
                            ['Peak Multiple', number_format($trade['peak_multiple'], 2).'x', $trade['protection_armed'] ? 'watch' : 'neutral'],
                            ['Initial Investment', number_format((float) $position->initial_investment_sol, 4).' '.$trade['currency'], 'neutral'],
                            ['Remaining', number_format($trade['remaining_fraction'] * 100, 2).'%', 'neutral'],
                            ['Estimated Value', number_format($trade['current_value'], 4).' '.$trade['currency'], $isProfitable ? 'profit' : 'loss'],
                            ['Unrealized P/L', sprintf('%+.4f %s', $trade['unrealized_pnl'], $trade['currency']), $trade['unrealized_pnl'] >= 0 ? 'profit' : 'loss'],
                            ['Realized P/L', sprintf('%+.4f %s', (float) $position->trade_pnl_sol, $trade['currency']), (float) $position->trade_pnl_sol >= 0 ? 'profit' : 'loss'],
                            ['Time Open', $position->entry_at?->diffForHumans(now(), true, false, 2) ?? 'N/A', 'neutral'],
                            ['Last Checked', $position->last_checked_at?->diffForHumans() ?? 'Never', 'neutral'],
                        ];
                    @endphp

                    @foreach ($metrics as [$label, $value, $tone])
                        <div class="bg-slate-900/90 px-5 py-4">
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $label }}</p>
                            <p @class([
                                'mt-2 font-semibold tabular-nums',
                                'text-slate-200' => $tone === 'neutral',
                                'text-emerald-300' => $tone === 'profit',
                                'text-red-300' => $tone === 'loss',
                                'text-amber-300' => $tone === 'watch',
                            ])>{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="px-5 py-5 sm:px-6">
                    <div class="mb-4 flex items-center justify-between gap-4">
                        <div><h4 class="text-sm font-semibold text-white">Position strategy snapshot</h4><p class="mt-1 text-xs text-slate-500">SL -{{ number_format($trade['strategy']['stop_loss_percent'], 2) }}% · P1 +{{ number_format($trade['strategy']['protection_level_1_percent'], 2) }}% · P2 +{{ number_format($trade['strategy']['protection_level_2_percent'], 2) }}%</p></div>
                        <span class="text-xs text-slate-500">Based on ${{ number_format($trade['entry_market_cap'], 2) }} entry MC</span>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl border border-red-400/20 bg-red-400/5 p-4"><p class="text-xs font-semibold uppercase text-red-300">Stop Loss</p><p class="mt-2 font-semibold text-white">${{ number_format($trade['levels']['stop_loss'], 2) }}</p><p class="mt-1 text-xs text-slate-400">-{{ number_format($trade['strategy']['stop_loss_percent'], 2) }}% · {{ number_format($trade['strategy']['stop_loss_multiple'], 2) }}x · Close 100%</p></div>
                        <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-4"><p class="text-xs font-semibold uppercase text-emerald-300">Level 1 +{{ number_format($trade['strategy']['protection_level_1_percent'], 2) }}%</p><p class="mt-2 font-semibold text-white">${{ number_format($trade['levels']['profit_1x'], 2) }}</p><p class="mt-1 text-xs text-slate-400">{{ number_format($trade['strategy']['protection_level_1_multiple'], 2) }}x · Arm floor · Keep holding</p></div>
                        <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-4"><p class="text-xs font-semibold uppercase text-emerald-300">Level 2 +{{ number_format($trade['strategy']['protection_level_2_percent'], 2) }}%</p><p class="mt-2 font-semibold text-white">${{ number_format($trade['levels']['profit_2x'], 2) }}</p><p class="mt-1 text-xs text-slate-400">{{ number_format($trade['strategy']['protection_level_2_multiple'], 2) }}x · Upgrade floor · Keep holding</p></div>
                        <div class="rounded-xl border border-amber-400/20 bg-amber-400/5 p-4"><p class="text-xs font-semibold uppercase text-amber-300">Active Protected Floor</p><p class="mt-2 font-semibold text-white">{{ $trade['protected_floor_multiple'] ? number_format($trade['protected_floor_multiple'], 2).'x' : 'Not armed' }}</p><p class="mt-1 text-xs text-slate-400">Later observation at or below floor closes 100% at observed fill</p></div>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/40 px-6 py-16 text-center">
                <h3 class="text-lg font-semibold text-white">No open funded positions</h3>
                <p class="mt-2 text-sm text-slate-400">New paper trades will appear here automatically.</p>
            </div>
        @endforelse
    </section>

    <dialog id="close-position-modal" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-0 text-slate-100 shadow-2xl backdrop:bg-slate-950/80">
        <form id="close-position-form" method="POST" class="flex flex-col gap-6 p-6">
            @csrf
            <div class="flex flex-col gap-2">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-red-300">Confirm manual exit</p>
                <h2 class="text-2xl font-semibold text-white">Close <span id="modal-symbol"></span>?</h2>
                <p class="text-sm leading-6 text-slate-400">Current DexScreener data will be fetched when you confirm.</p>
            </div>
            <dl class="grid grid-cols-2 gap-4 rounded-xl bg-slate-950/70 p-4 text-sm">
                <div><dt class="text-slate-500">Current return</dt><dd id="modal-return" class="mt-1 font-semibold text-white"></dd></div>
                <div><dt class="text-slate-500">Remaining position</dt><dd id="modal-remaining" class="mt-1 font-semibold text-white"></dd></div>
                <div class="col-span-2"><dt class="text-slate-500">Estimated current value</dt><dd id="modal-value" class="mt-1 font-semibold text-white"></dd></div>
            </dl>
            <div class="rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm leading-6 text-red-200">This closes 100% of the remaining paper position. Automatic tracking for every other position stays active.</div>
            <div class="flex justify-end gap-3">
                <button id="cancel-close" type="button" class="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:bg-slate-800">Cancel</button>
                <button type="submit" class="rounded-lg bg-red-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-400">Close Position</button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
