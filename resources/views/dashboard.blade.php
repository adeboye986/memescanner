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
                Execution Preference: {{ strtoupper($executionMode) }}
            </span>
            <span class="inline-flex items-center rounded-full border border-violet-400/20 bg-violet-400/10 px-3 py-2 text-violet-300">Entry: {{ strtoupper($entryMode) }}</span>
            <span id="tracker-status-badge" @class([
                'inline-flex items-center gap-2 rounded-full border px-3 py-2',
                'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => $systemStatus['status'] === 'active',
                'border-amber-400/20 bg-amber-400/10 text-amber-300' => in_array($systemStatus['status'], ['stale', 'degraded'], true),
                'border-slate-700 bg-slate-800 text-slate-300' => $systemStatus['status'] === 'unknown',
            ])>
                <span id="tracker-status-dot" @class([
                    'size-2 rounded-full',
                    'bg-emerald-400' => $systemStatus['status'] === 'active',
                    'bg-amber-400' => in_array($systemStatus['status'], ['stale', 'degraded'], true),
                    'bg-slate-500' => $systemStatus['status'] === 'unknown',
                ])></span>
                Legacy Laravel PAPER Tracker: <span id="tracker-status-text">{{ strtoupper($systemStatus['status']) }}</span>
            </span>
        </div>
    </header>

    @if($onboardingStatus && ! $onboardingStatus['ready'])
        <section class="flex flex-col gap-4 rounded-2xl border border-amber-400/20 bg-amber-400/5 p-5 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-semibold text-amber-200">Complete your setup</p><p class="mt-1 text-sm text-slate-400">Verify your email and connect Telegram to receive and manage your own opportunities.</p></div><a href="{{ route('onboarding') }}" class="shrink-0 rounded-xl bg-amber-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-950">Continue Setup</a></section>
    @endif

    @if ($executionMode === 'live')
        <div class="rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm font-semibold text-red-200">LIVE supports CONFIRM only for Ethereum opportunities. Review each transaction in your browser wallet; selecting LIVE does not execute a trade.</div>
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
            <p class="font-semibold">Your changes could not be saved.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section aria-label="Platform workflow summary" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <article class="rounded-2xl border border-sky-400/15 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Execution Preference</p><p class="mt-2 text-lg font-bold text-sky-300">{{ strtoupper($executionMode) }}</p><p class="mt-1 text-xs text-slate-500">Preference only; server capability is verified separately</p></article>
        <article class="rounded-2xl border border-violet-400/15 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Entry Mode</p><p class="mt-2 text-lg font-bold text-violet-300">{{ str($entryMode)->replace('_', ' ')->upper() }}</p><p class="mt-1 text-xs text-slate-500">Qualification policy</p></article>
        <article class="rounded-2xl border border-amber-400/15 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Opportunities · 24h</p><p class="mt-2 text-lg font-bold text-white">{{ $opportunitySummary['recent'] }}</p><p class="mt-1 text-xs text-amber-300">{{ $opportunitySummary['pending'] }} pending confirmation</p></article>
        <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Legacy Laravel PAPER Tracker</p><p class="mt-2 text-lg font-bold {{ $systemStatus['status'] === 'active' ? 'text-emerald-300' : (in_array($systemStatus['status'], ['stale', 'degraded'], true) ? 'text-amber-300' : 'text-slate-300') }}">{{ strtoupper($systemStatus['status']) }}</p><p class="mt-1 text-xs text-slate-500">Laravel-owned legacy positions only</p></article>
        <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4"><p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Configured Integrations</p><p class="mt-2 text-lg font-bold text-emerald-300">{{ collect($integrationSummary)->whereIn('status', ['configured', 'active'])->count() }} / {{ count($integrationSummary) }}</p><p class="mt-1 text-xs text-slate-500">Configuration and health are distinct</p></article>
    </section>

    @can('manage-settings')
        <section aria-labelledby="operations-heading" class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
            <div class="flex items-center justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Operations</p><h2 id="operations-heading" class="mt-1 text-xl font-semibold text-white">Background Process Health</h2></div><span class="text-xs text-slate-500">Safe operational metadata only</span></div>
            <div class="mt-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach (['scheduler' => 'Laravel Scheduler', 'queue' => 'Laravel Queue Worker', 'legacy_paper_tracker' => 'Legacy Laravel PAPER Tracker'] as $key => $label)
                    @php
                        $state = $operationalStatus[$key]['status'];
                    @endphp
                    <article class="rounded-xl border border-slate-800 bg-slate-950/60 p-4"><p class="text-xs uppercase tracking-wider text-slate-500">{{ $label }}</p><p id="operation-{{ str($key)->replace('_', '-') }}" class="mt-2 font-bold {{ $state === 'healthy' ? 'text-emerald-300' : ($state === 'stale' ? 'text-amber-300' : 'text-slate-300') }}">{{ str($state)->replace('_', ' ')->upper() }}</p><p class="mt-1 text-xs text-slate-500">{{ $operationalStatus[$key]['last_run_at']?->diffForHumans() ?? 'No heartbeat recorded' }}</p></article>
                @endforeach
                <article class="rounded-xl border border-slate-800 bg-slate-950/60 p-4"><p class="text-xs uppercase tracking-wider text-slate-500">Laravel Pending Jobs</p><p id="operation-pending-jobs" class="mt-2 text-lg font-bold text-white">{{ $operationalStatus['pending_jobs'] }}</p></article>
                <article class="rounded-xl border border-slate-800 bg-slate-950/60 p-4"><p class="text-xs uppercase tracking-wider text-slate-500">Laravel Failed Jobs</p><p id="operation-failed-jobs" class="mt-2 text-lg font-bold {{ $operationalStatus['failed_jobs'] > 0 ? 'text-red-300' : 'text-emerald-300' }}">{{ $operationalStatus['failed_jobs'] }}</p></article>
            </div>
            <div class="mt-5">@include('partials.engine-health-cards')</div>
        </section>
    @endcan

    <form id="trading-preferences" method="POST" action="{{ route('dashboard.trading-preferences.update') }}" class="flex flex-wrap items-end gap-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        @csrf @method('PUT')
        <label class="grid gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Execution Preference<select name="execution_mode" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white">@foreach(['paper' => 'PAPER', 'live' => 'LIVE'] as $value => $label)<option value="{{ $value }}" @selected(old('execution_mode', $executionMode) === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="grid gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Entry Mode<select name="entry_mode" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white">@foreach(['signal' => 'Signal', 'confirm' => 'Confirm', 'auto' => 'Auto'] as $value => $label)<option value="{{ $value }}" @selected(old('entry_mode', $entryMode) === $value)>{{ $label }}</option>@endforeach</select></label>
        <button class="rounded-xl bg-violet-400 px-5 py-2.5 text-sm font-semibold text-slate-950">Save Preferences</button>
        <p class="text-xs text-slate-500">LIVE supports CONFIRM only for Ethereum opportunities and requires browser wallet signing. AUTO and SIGNAL are PAPER only.</p>
    </form>

    <section aria-labelledby="wallet-heading" class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Legacy Laravel Portfolio</p>
                <h2 id="wallet-heading" class="mt-1 text-xl font-semibold text-white">Legacy Laravel PAPER Wallets</h2>
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
                        <h3 class="font-semibold uppercase tracking-wider text-white">{{ $wallet->chain->label() }} Legacy PAPER Wallet</h3>
                        <span class="rounded-full bg-violet-400/10 px-2.5 py-1 text-xs font-semibold text-violet-300">{{ $currency }}</span>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div><dt class="text-xs uppercase text-slate-500">Starting</dt><dd class="mt-2 font-semibold text-white">{{ number_format((float) $wallet->starting_balance_sol, 4) }} {{ $currency }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">Available</dt><dd class="mt-2 font-semibold text-emerald-300">{{ number_format((float) $wallet->available_balance_sol, 4) }} {{ $currency }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">Invested</dt><dd class="mt-2 font-semibold text-amber-300">{{ number_format((float) $wallet->invested_balance_sol, 4) }} {{ $currency }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">Realized P/L</dt><dd class="mt-2 font-semibold {{ (float) $wallet->realized_pnl_sol >= 0 ? 'text-emerald-300' : 'text-red-300' }}">{{ sprintf('%+.4f %s', (float) $wallet->realized_pnl_sol, $currency) }}</dd></div>
                    </dl>
                    <p class="mt-4 border-t border-slate-800 pt-3 text-xs text-slate-500">{{ $positions->filter(fn ($trade) => $trade['model']->chain->value === $chain && $trade['model']->user_id === auth()->id())->count() }} open funded positions</p>
                </article>
            @endforeach
        </div>

        @if ($legacyWallets->isNotEmpty())
            <div class="rounded-2xl border border-amber-400/20 bg-amber-400/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-300">Legacy Admin Ledgers</p>
                <p class="mt-2 text-sm text-slate-400">These null-owned balances are preserved separately from your personal paper wallets.</p>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach ($legacyWallets as $chain => $wallet)
                        @php
                            $currency = $wallet->currencyCode();
                        @endphp
                        <article class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <div class="flex items-center justify-between"><h3 class="font-semibold text-white">{{ $wallet->chain->label() }} Legacy</h3><span class="text-xs text-amber-300">UNOWNED</span></div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs uppercase text-slate-500">Available</dt><dd class="mt-1 text-white">{{ number_format((float) $wallet->available_balance_sol, 4) }} {{ $currency }}</dd></div><div><dt class="text-xs uppercase text-slate-500">Invested</dt><dd class="mt-1 text-white">{{ number_format((float) $wallet->invested_balance_sol, 4) }} {{ $currency }}</dd></div></dl>
                            <p class="mt-3 text-xs text-slate-500">{{ $positions->filter(fn ($trade) => $trade['model']->chain->value === $chain && $trade['model']->user_id === null)->count() }} legacy open funded positions</p>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    <section id="paper-strategy" aria-labelledby="strategy-heading" class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10 sm:p-6">
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
    </section>

    <div class="grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
        <section id="scanner-controls" aria-labelledby="controls-heading" class="flex flex-col gap-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10 sm:p-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Manual Laravel Operations</p>
                <h2 id="controls-heading" class="mt-1 text-xl font-semibold text-white">Laravel Scanner Commands</h2>
                <p class="mt-2 text-sm text-slate-400">Buttons submit existing Laravel commands to the Laravel queue. Engine evaluation, when configured, happens downstream and is not proof of scan completion.</p>
            </div>

            @php
                $actionDescriptions = [
                    'token-scan' => 'Queue the Laravel new-token scanner',
                    'momentum-scan' => 'Queue the Laravel momentum scanner',
                    'paper-track' => 'Run the legacy Laravel PAPER tracker',
                    'paper-report' => 'Generate a legacy Laravel PAPER report',
                    'paper-reconcile' => 'Check the legacy Laravel wallet ledger without fixing it',
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

        @include('partials.system-activity')
    </div>

    <section aria-labelledby="positions-heading" class="flex flex-col gap-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Legacy Laravel Positions</p>
                <h2 id="positions-heading" class="mt-1 text-xl font-semibold text-white">Legacy Laravel Open PAPER Positions</h2>
            </div>
            <p class="text-sm text-slate-500">Market values are PAPER observations, not executable sell quotes. Check observation status before relying on them.</p>
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
                            @if ($observation = data_get($position->meta, 'market_observation'))
                                <p class="mt-2 text-xs text-amber-300">Observation: {{ str($observation['status'])->replace('_', ' ') }}
                                    @if ($observation['status'] === 'unverified') — automatic PAPER exit deferred: {{ implode(', ', $observation['reasons']) }} @endif
                                    @if (!empty($observation['diagnostics'])) — {{ implode(', ', $observation['diagnostics']) }} @endif
                                    @if ($observation['pool_switched'] ?? false) — pool changed @endif
                                </p>
                                <p class="mt-1 text-xs text-slate-500">Source: {{ $observation['provider'] ?? 'Unavailable' }} · {{ $observation['valuation_source'] }} · Historical last-valid observation at: {{ data_get($position->meta, 'last_valid_market_observation_at', 'Not recorded') }}</p>
                            @endif
                            @if ($historicalObservation = data_get($position->meta, 'last_valid_market_observation'))
                                @php $historicalMarketCap = data_get($historicalObservation, 'market_cap'); @endphp
                                <p class="mt-1 text-xs text-slate-500">Historical validated observation (not current): {{ data_get($position->meta, 'last_valid_market_observation_at', 'Not recorded') }} · {{ $historicalObservation['provider'] ?? 'Unavailable' }} · Market cap: {{ $historicalMarketCap === null ? 'Unavailable' : 'USD '.number_format((float) $historicalMarketCap, 2) }}.</p>
                            @endif
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

    @include('partials.engine-paper-positions')

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
            <div class="rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm leading-6 text-red-200">This closes 100% of the remaining paper position. Legacy Laravel tracking for every other legacy position stays active.</div>
            <div class="flex justify-end gap-3">
                <button id="cancel-close" type="button" class="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:bg-slate-800">Cancel</button>
                <button type="submit" class="rounded-lg bg-red-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-400">Close Position</button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
