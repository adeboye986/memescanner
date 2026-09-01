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
                Trading Mode: Paper
            </span>
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
                Auto Tracker: <span id="tracker-status-text">{{ strtoupper($systemStatus['status']) }}</span>
            </span>
        </div>
    </header>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ session('success') }}</div>
    @endif
    @if (session('warning'))
        <div class="rounded-xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm text-amber-200" role="status">{{ session('warning') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">{{ session('error') }}</div>
    @endif

    <section aria-labelledby="wallet-heading" class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Portfolio</p>
                <h2 id="wallet-heading" class="mt-1 text-xl font-semibold text-white">Wallet Summary</h2>
            </div>
            <span class="text-sm text-slate-500">Virtual SOL</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            @php
                $summaryCards = [
                    ['label' => 'Starting Balance', 'value' => number_format((float) $wallet->starting_balance_sol, 4).' SOL', 'tone' => 'neutral'],
                    ['label' => 'Available Balance', 'value' => number_format((float) $wallet->available_balance_sol, 4).' SOL', 'tone' => 'healthy'],
                    ['label' => 'Invested Balance', 'value' => number_format((float) $wallet->invested_balance_sol, 4).' SOL', 'tone' => 'watch'],
                    ['label' => 'Realized P/L', 'value' => sprintf('%+.4f SOL', (float) $wallet->realized_pnl_sol), 'tone' => (float) $wallet->realized_pnl_sol >= 0 ? 'healthy' : 'danger'],
                    ['label' => 'Open Funded Positions', 'value' => (string) $positions->count(), 'tone' => 'neutral'],
                ];
            @endphp

            @foreach ($summaryCards as $card)
                <article @class([
                    'rounded-2xl border bg-slate-900/70 p-5 shadow-xl shadow-black/10',
                    'border-slate-800' => $card['tone'] === 'neutral',
                    'border-emerald-400/20' => $card['tone'] === 'healthy',
                    'border-amber-400/20' => $card['tone'] === 'watch',
                    'border-red-400/20' => $card['tone'] === 'danger',
                ])>
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $card['label'] }}</p>
                    <p @class([
                        'mt-3 text-2xl font-semibold tabular-nums',
                        'text-white' => $card['tone'] === 'neutral',
                        'text-emerald-300' => $card['tone'] === 'healthy',
                        'text-amber-300' => $card['tone'] === 'watch',
                        'text-red-300' => $card['tone'] === 'danger',
                    ])>{{ $card['value'] }}</p>
                </article>
            @endforeach
        </div>
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

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($dashboardActions as $action => $definition)
                    <form method="POST" action="{{ route('dashboard.actions.store', $action) }}" class="dashboard-action-form {{ $action === 'paper-reconcile' ? 'sm:col-span-2' : '' }}" data-action="{{ $action }}">
                        @csrf
                        <button
                            type="submit"
                            @disabled(in_array($action, $runningActions, true))
                            class="group flex w-full items-center justify-between gap-4 rounded-xl border border-slate-700 bg-slate-950/60 px-4 py-4 text-left transition hover:border-slate-600 hover:bg-slate-800/80 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span>
                                <span class="block text-sm font-semibold text-slate-100">{{ $definition['label'] }}</span>
                                <span class="mt-1 block text-xs text-slate-500">{{ $actionDescriptions[$action] }}</span>
                            </span>
                            <span class="action-state shrink-0 text-xs font-semibold uppercase tracking-wider text-emerald-300">
                                {{ in_array($action, $runningActions, true) ? 'Running' : 'Run' }}
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

            <div id="activity-empty" class="{{ $latestActivity ? 'hidden' : '' }} rounded-xl border border-dashed border-slate-700 px-5 py-8 text-center text-sm text-slate-500">
                No dashboard operation has been triggered yet.
            </div>

            <div id="activity-content" class="{{ $latestActivity ? '' : 'hidden' }} flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 id="activity-label" class="font-semibold text-white">{{ $latestActivity['label'] ?? '' }}</h3>
                    <span id="activity-status" @class([
                        'rounded-full border px-2.5 py-1 text-xs font-semibold uppercase tracking-wider',
                        'border-slate-700 text-slate-300' => ! $latestActivity || in_array($latestActivity['status'], ['pending', 'running'], true),
                        'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => ($latestActivity['status'] ?? null) === 'completed',
                        'border-red-400/20 bg-red-400/10 text-red-300' => ($latestActivity['status'] ?? null) === 'failed',
                    ])>{{ $latestActivity['status'] ?? '' }}</span>
                </div>

                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs uppercase tracking-wider text-slate-500">Started</dt><dd id="activity-started" class="mt-1 font-medium text-slate-200">{{ $latestActivity['started_at'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wider text-slate-500">Finished</dt><dd id="activity-finished" class="mt-1 font-medium text-slate-200">{{ $latestActivity['finished_at'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wider text-slate-500">Duration</dt><dd id="activity-duration" class="mt-1 font-medium text-slate-200">{{ isset($latestActivity['duration_seconds']) ? $latestActivity['duration_seconds'].'s' : '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wider text-slate-500">Exit Code</dt><dd id="activity-exit-code" class="mt-1 font-medium text-slate-200">{{ $latestActivity['exit_code'] ?? '—' }}</dd></div>
                </dl>

                <p id="activity-summary" class="rounded-lg bg-slate-950/70 px-4 py-3 text-sm leading-6 text-slate-400">{{ $latestActivity['summary'] ?? '' }}</p>

                <details id="activity-output-details" class="group rounded-xl border border-slate-700 bg-slate-950/60">
                    <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-slate-300 marker:hidden">View Output</summary>
                    <pre id="activity-output" class="max-h-80 overflow-auto border-t border-slate-800 p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-slate-400">{{ $latestActivity['output'] ?? '' }}</pre>
                </details>
            </div>

            <div class="grid gap-3 border-t border-slate-800 pt-4 text-xs sm:grid-cols-3">
                <div><p class="uppercase tracking-wider text-slate-600">Last Tracker Check</p><p id="last-tracker-check" class="mt-1 text-slate-300">{{ $systemStatus['last_tracker_check']?->diffForHumans() ?? 'Never' }}</p></div>
                <div><p class="uppercase tracking-wider text-slate-600">Last Momentum Scan</p><p id="last-momentum-scan" class="mt-1 text-slate-300">{{ $systemStatus['last_momentum_scan']?->diffForHumans() ?? 'Never' }}</p></div>
                <div><p class="uppercase tracking-wider text-slate-600">Last Token Scan</p><p id="last-token-scan" class="mt-1 text-slate-300">{{ $systemStatus['last_token_scan']?->diffForHumans() ?? 'Never' }}</p></div>
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
                                @if ($trade['protection_armed'])
                                    <span class="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-semibold text-amber-300">Protection armed</span>
                                @else
                                    <span class="rounded-full bg-emerald-400/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">Open</span>
                                @endif
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
                            data-value="{{ number_format($trade['current_value'], 4) }} SOL"
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
                            ['Initial Investment', number_format((float) $position->initial_investment_sol, 4).' SOL', 'neutral'],
                            ['Remaining', number_format($trade['remaining_fraction'] * 100, 2).'%', 'neutral'],
                            ['Estimated Value', number_format($trade['current_value'], 4).' SOL', $isProfitable ? 'profit' : 'loss'],
                            ['Unrealized P/L', sprintf('%+.4f SOL', $trade['unrealized_pnl']), $trade['unrealized_pnl'] >= 0 ? 'profit' : 'loss'],
                            ['Realized P/L', sprintf('%+.4f SOL', (float) $position->trade_pnl_sol), (float) $position->trade_pnl_sol >= 0 ? 'profit' : 'loss'],
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
                        <h4 class="text-sm font-semibold text-white">Current strategy levels</h4>
                        <span class="text-xs text-slate-500">Based on ${{ number_format($trade['entry_market_cap'], 2) }} entry MC</span>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <div class="rounded-xl border border-red-400/20 bg-red-400/5 p-4"><p class="text-xs font-semibold uppercase text-red-300">Stop Loss</p><p class="mt-2 font-semibold text-white">${{ number_format($trade['levels']['stop_loss'], 2) }}</p><p class="mt-1 text-xs text-slate-400">-5% · 0.95x · Close 100%</p></div>
                        <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-4"><p class="text-xs font-semibold uppercase text-emerald-300">1X Profit</p><p class="mt-2 font-semibold text-white">${{ number_format($trade['levels']['profit_1x'], 2) }}</p><p class="mt-1 text-xs text-slate-400">+100% · 2.00x · Hold</p></div>
                        <div class="rounded-xl border border-amber-400/20 bg-amber-400/5 p-4"><p class="text-xs font-semibold uppercase text-amber-300">Protection</p><p class="mt-2 font-semibold text-white">${{ number_format($trade['levels']['protection'], 2) }}</p><p class="mt-1 text-xs text-slate-400">+150% · 2.50x · Arm only</p></div>
                        <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-4"><p class="text-xs font-semibold uppercase text-emerald-300">2X Profit</p><p class="mt-2 font-semibold text-white">${{ number_format($trade['levels']['profit_2x'], 2) }}</p><p class="mt-1 text-xs text-slate-400">+200% · 3.00x · Close 100%</p></div>
                        <div class="rounded-xl border border-amber-400/20 bg-amber-400/5 p-4"><p class="text-xs font-semibold uppercase text-amber-300">Protected Floor</p><p class="mt-2 font-semibold text-white">${{ number_format($trade['levels']['profit_1x'], 2) }}</p><p class="mt-1 text-xs text-slate-400">After arming: +100% · 2.00x · Close 100%</p></div>
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
