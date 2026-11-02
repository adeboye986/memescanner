@php
    $sectionLabels = [
        'general' => 'General',
        'trading' => 'Trading Preferences',
        'risk' => 'Risk Management',
        'notifications' => 'Notifications',
        'security' => 'Security',
        'diagnostics' => 'Diagnostics',
    ];
    $sectionUrl = fn (string $section): string => route('account.edit', ['section' => $section]);
    $executionMode = $preference->execution_mode->value;
    $entryMode = $preference->entry_mode->value;
@endphp

<x-layouts.shell title="Settings" :administrator="(bool) $user->is_admin">
    <div class="mx-auto flex w-full max-w-[70rem] flex-col gap-4" data-settings-screen data-settings-section="{{ $activeSection }}">
        <header class="flex flex-col gap-1">
            <h1 class="text-[1.45rem] font-semibold leading-7 tracking-[-0.025em] text-white sm:text-[1.65rem]">Settings</h1>
            <p class="text-[13px] leading-5 text-slate-400">Manage your account, trading preferences, and notifications.</p>
        </header>

        <nav class="flex gap-6 overflow-x-auto overflow-y-hidden border-b border-slate-800 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Settings sections">
            @foreach($sections as $section)
                <a
                    href="{{ $sectionUrl($section) }}"
                    @class([
                        'relative -mb-px shrink-0 whitespace-nowrap border-b-2 px-0.5 pb-2.5 text-xs font-medium transition',
                        'border-blue-500 text-blue-300' => $activeSection === $section,
                        'border-transparent text-slate-400 hover:text-slate-200' => $activeSection !== $section,
                    ])
                    @if($activeSection === $section) aria-current="page" @endif
                >{{ $sectionLabels[$section] }}</a>
            @endforeach
        </nav>

        @if(session('success'))
            <div class="rounded-lg border border-emerald-400/25 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-lg border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-lg border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">
                <p class="font-semibold">Your changes could not be saved.</p>
                <ul class="mt-1.5 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($activeSection === 'general')
            <section aria-labelledby="general-heading" class="rounded-xl border border-slate-700/80 bg-slate-900/80 p-4 shadow-xl shadow-black/10 sm:p-5">
                <div class="border-b border-slate-800 pb-4">
                    <h2 id="general-heading" class="text-base font-semibold text-white">Account details</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">Update the identity used for your account and security messages.</p>
                </div>

                <form method="POST" action="{{ route('account.update') }}" class="mt-4 max-w-3xl space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            Name
                            <input name="name" value="{{ old('name', $user->name) }}" required autocomplete="name" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                        </label>
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            Email address
                            <input type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                        </label>
                    </div>

                    <div class="flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-950/35 px-3.5 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-200">Email verification</p>
                            <p class="mt-0.5 text-xs text-slate-400">{{ $user->hasVerifiedEmail() ? 'Your email address is verified.' : 'Verification is required for protected customer actions and PAPER AUTO.' }}</p>
                        </div>
                        <span @class([
                            'inline-flex w-fit rounded-full px-2.5 py-1 text-[10px] font-semibold tracking-wide',
                            'bg-emerald-400/10 text-emerald-300' => $user->hasVerifiedEmail(),
                            'bg-amber-400/10 text-amber-300' => ! $user->hasVerifiedEmail(),
                        ])>{{ $user->hasVerifiedEmail() ? 'VERIFIED' : 'ACTION REQUIRED' }}</span>
                    </div>

                    <p class="text-[11px] leading-5 text-slate-500">Changing your email address requires verification again.</p>
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-xs font-semibold text-white transition hover:bg-blue-500">Save account</button>
                </form>

                <div class="mt-5 flex flex-col gap-3 border-t border-slate-800 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-white">Connected wallets</h3>
                        <p class="mt-0.5 text-xs text-slate-400">Wallet connections, balances, and transaction history remain in the dedicated Wallets workspace.</p>
                    </div>
                    <a href="{{ route('wallets.index') }}" class="inline-flex min-h-9 shrink-0 items-center justify-center rounded-lg border border-slate-700 px-3.5 text-xs font-semibold text-slate-200 transition hover:border-slate-600 hover:bg-slate-800">Open Wallets</a>
                </div>
            </section>
        @elseif($activeSection === 'trading')
            <section id="trading-preferences" aria-labelledby="trading-heading" class="rounded-xl border border-slate-700/80 bg-slate-900/80 p-4 shadow-xl shadow-black/10 sm:p-5">
                <div class="flex flex-col gap-3 border-b border-slate-800 pb-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 id="trading-heading" class="text-base font-semibold text-white">Trading Mode</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-400">Choose how qualified opportunities enter the existing trading workflow.</p>
                    </div>
                    <span @class([
                        'inline-flex w-fit rounded-full px-2.5 py-1 text-[10px] font-semibold tracking-wide',
                        'bg-emerald-400/10 text-emerald-300' => $executionMode === 'paper',
                        'bg-red-400/10 text-red-300' => $executionMode === 'live',
                    ])>{{ strtoupper($executionMode) }} · {{ strtoupper($entryMode) }}</span>
                </div>

                <div @class([
                    'mt-4 rounded-lg border px-3.5 py-3 text-xs leading-5',
                    'border-blue-400/20 bg-blue-500/10 text-blue-200' => $executionMode === 'paper',
                    'border-amber-400/25 bg-amber-400/10 text-amber-200' => $executionMode === 'live',
                ])>
                    @if($executionMode === 'paper')
                        PAPER mode is active. Orders remain simulated and use the application’s existing PAPER safeguards.
                    @else
                        LIVE is limited to CONFIRM for eligible Ethereum opportunities. Browser-wallet signing and every existing confirmation safeguard remain required.
                    @endif
                </div>

                <form method="POST" action="{{ route('dashboard.trading-preferences.update') }}" class="mt-5 max-w-3xl space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            Execution preference
                            <select name="execution_mode" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                                <option value="paper" @selected(old('execution_mode', $executionMode) === 'paper')>PAPER</option>
                                <option value="live" @selected(old('execution_mode', $executionMode) === 'live')>LIVE</option>
                            </select>
                        </label>
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            Entry mode
                            <select name="entry_mode" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                                <option value="signal" @selected(old('entry_mode', $entryMode) === 'signal')>Signal</option>
                                <option value="confirm" @selected(old('entry_mode', $entryMode) === 'confirm')>Confirm</option>
                                <option value="auto" @selected(old('entry_mode', $entryMode) === 'auto')>Auto</option>
                            </select>
                        </label>
                    </div>

                    <div class="rounded-lg border border-slate-800 bg-slate-950/35 px-3.5 py-3 text-[11px] leading-5 text-slate-400">
                        LIVE AUTO and LIVE SIGNAL are unavailable. PAPER AUTO requires a verified email. Saving a preference never submits or signs a trade.
                    </div>
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-xs font-semibold text-white transition hover:bg-blue-500">Save preferences</button>
                </form>

                <div class="mt-5 flex flex-col gap-3 border-t border-slate-800 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-white">Scanner policy</h3>
                        <p class="mt-0.5 text-xs leading-5 text-slate-400">Provider budgets, evaluation thresholds, and execution limits are server-managed and are not editable account preferences.</p>
                    </div>
                    <a href="{{ route('scanner.index') }}" class="inline-flex min-h-9 shrink-0 items-center justify-center rounded-lg border border-slate-700 px-3.5 text-xs font-semibold text-slate-200 transition hover:border-slate-600 hover:bg-slate-800">Open Token Scanner</a>
                </div>
            </section>
        @elseif($activeSection === 'risk')
            <section id="paper-strategy" aria-labelledby="risk-heading" class="rounded-xl border border-slate-700/80 bg-slate-900/80 p-4 shadow-xl shadow-black/10 sm:p-5">
                <div class="border-b border-slate-800 pb-4">
                    <h2 id="risk-heading" class="text-base font-semibold text-white">PAPER Risk Management</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">Set the strategy snapshot applied when a new PAPER position opens.</p>
                </div>

                <div class="mt-4 grid max-w-3xl grid-cols-3 gap-2" aria-label="Current PAPER strategy">
                    <div class="rounded-lg border border-red-400/15 bg-red-400/8 px-3 py-2.5"><p class="text-[10px] text-slate-500">Stop loss</p><p class="mt-0.5 text-sm font-semibold text-red-300">-{{ number_format($paperStrategy['stop_loss_percent'], 2) }}%</p></div>
                    <div class="rounded-lg border border-amber-400/15 bg-amber-400/8 px-3 py-2.5"><p class="text-[10px] text-slate-500">Level 1</p><p class="mt-0.5 text-sm font-semibold text-amber-300">+{{ number_format($paperStrategy['protection_level_1_percent'], 2) }}%</p></div>
                    <div class="rounded-lg border border-emerald-400/15 bg-emerald-400/8 px-3 py-2.5"><p class="text-[10px] text-slate-500">Level 2</p><p class="mt-0.5 text-sm font-semibold text-emerald-300">+{{ number_format($paperStrategy['protection_level_2_percent'], 2) }}%</p></div>
                </div>

                <form method="POST" action="{{ route('dashboard.paper-strategy.update') }}" class="mt-5 max-w-3xl space-y-4">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            Stop loss %
                            <input name="stop_loss_percent" type="number" min="0.01" max="99.99" step="0.01" required value="{{ old('stop_loss_percent', $paperStrategy['stop_loss_percent']) }}" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                        </label>
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            Protection level 1 %
                            <input name="protection_level_1_percent" type="number" min="0.01" max="10000" step="0.01" required value="{{ old('protection_level_1_percent', $paperStrategy['protection_level_1_percent']) }}" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                        </label>
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            Protection level 2 %
                            <input name="protection_level_2_percent" type="number" min="0.02" max="10000" step="0.01" required value="{{ old('protection_level_2_percent', $paperStrategy['protection_level_2_percent']) }}" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                        </label>
                    </div>

                    <div class="rounded-lg border border-blue-400/20 bg-blue-500/10 px-3.5 py-3 text-[11px] leading-5 text-blue-200">
                        Changes apply only to new PAPER positions. Existing positions retain their stored strategy snapshot.
                    </div>
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-xs font-semibold text-white transition hover:bg-blue-500">Save risk settings</button>
                </form>
            </section>
        @elseif($activeSection === 'notifications')
            <section aria-labelledby="notifications-heading" class="rounded-xl border border-slate-700/80 bg-slate-900/80 p-4 shadow-xl shadow-black/10 sm:p-5">
                <div class="border-b border-slate-800 pb-4">
                    <h2 id="notifications-heading" class="text-base font-semibold text-white">Notifications</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">Manage the supported notification connection for this account.</p>
                </div>

                <div class="mt-4 flex flex-col gap-4 rounded-lg border border-slate-800 bg-slate-950/35 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg bg-blue-500/10 text-blue-300"><x-app-icon name="telegram" :size="18" /></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-white">Telegram</h3>
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-[9px] font-semibold tracking-wide',
                                    'bg-emerald-400/10 text-emerald-300' => $identity,
                                    'bg-amber-400/10 text-amber-300' => ! $identity && $platformBotAvailable,
                                    'bg-slate-700/70 text-slate-300' => ! $identity && ! $platformBotAvailable,
                                ])>{{ $identity ? 'CONNECTED' : ($platformBotAvailable ? 'NOT CONNECTED' : 'UNAVAILABLE') }}</span>
                            </div>
                            @if($identity)
                                <p class="mt-1 text-xs text-slate-400">Linked as {{ $identity->telegram_username ? '@'.$identity->telegram_username : ($identity->display_name ?: 'Telegram user') }}.</p>
                            @elseif($platformBotAvailable)
                                <p class="mt-1 text-xs text-slate-400">Connect your account to the platform’s official Telegram bot.</p>
                            @else
                                <p class="mt-1 text-xs text-slate-400">The platform bot is not currently available.</p>
                            @endif
                        </div>
                    </div>
                    <a href="{{ route('telegram.settings') }}" class="inline-flex min-h-9 shrink-0 items-center justify-center rounded-lg bg-blue-600 px-3.5 text-xs font-semibold text-white transition hover:bg-blue-500">Manage Telegram</a>
                </div>

                <div class="mt-4 rounded-lg border border-slate-800 px-4 py-3">
                    <h3 class="text-xs font-semibold text-slate-200">Email preferences</h3>
                    <p class="mt-1 text-[11px] leading-5 text-slate-400">Configurable email notification channels are not currently available. Account verification and security emails continue to use the verified address on your profile.</p>
                </div>
            </section>
        @elseif($activeSection === 'security')
            <section aria-labelledby="security-heading" class="rounded-xl border border-slate-700/80 bg-slate-900/80 p-4 shadow-xl shadow-black/10 sm:p-5">
                <div class="border-b border-slate-800 pb-4">
                    <h2 id="security-heading" class="text-base font-semibold text-white">Security</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">Change your password and review your email verification status.</p>
                </div>

                <div class="mt-4 flex max-w-3xl flex-col gap-3 rounded-lg border border-slate-800 bg-slate-950/35 px-3.5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold text-slate-200">Email verification</p>
                        <p class="mt-0.5 text-xs text-slate-400">{{ $user->hasVerifiedEmail() ? 'Verified for protected account actions.' : 'Verify your email to unlock protected customer actions.' }}</p>
                    </div>
                    @if($user->hasVerifiedEmail())
                        <span class="inline-flex w-fit rounded-full bg-emerald-400/10 px-2.5 py-1 text-[10px] font-semibold tracking-wide text-emerald-300">VERIFIED</span>
                    @else
                        <form method="POST" action="{{ route('verification.send') }}">
                            @csrf
                            <button type="submit" class="inline-flex min-h-9 items-center justify-center rounded-lg border border-amber-400/30 px-3.5 text-xs font-semibold text-amber-300">Resend verification</button>
                        </form>
                    @endif
                </div>

                <form method="POST" action="{{ route('account.password.update') }}" class="mt-5 max-w-3xl space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300 sm:col-span-2">
                            Current password
                            <input type="password" name="current_password" required autocomplete="current-password" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                        </label>
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            New password
                            <input type="password" name="password" required autocomplete="new-password" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                        </label>
                        <label class="grid gap-1.5 text-xs font-medium text-slate-300">
                            Confirm new password
                            <input type="password" name="password_confirmation" required autocomplete="new-password" class="min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 text-sm text-white transition focus:border-blue-500 focus:outline-none">
                        </label>
                    </div>
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-xs font-semibold text-white transition hover:bg-blue-500">Update password</button>
                </form>

                <p class="mt-5 border-t border-slate-800 pt-4 text-[11px] leading-5 text-slate-500">Two-factor authentication, session management, and device controls are not currently supported, so no inactive controls are shown here.</p>
            </section>
        @elseif($activeSection === 'diagnostics' && $user->is_admin)
            <section aria-labelledby="diagnostics-heading" class="rounded-xl border border-slate-700/80 bg-slate-900/80 p-4 shadow-xl shadow-black/10 sm:p-5">
                <div class="border-b border-slate-800 pb-4">
                    <h2 id="diagnostics-heading" class="text-base font-semibold text-white">Administrator diagnostics</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">Privileged configuration and operational evidence remain isolated from customer preferences.</p>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <a href="{{ route('settings.index') }}" class="rounded-lg border border-slate-800 bg-slate-950/35 p-4 transition hover:border-slate-700 hover:bg-slate-800/60">
                        <h3 class="text-sm font-semibold text-white">Platform settings</h3>
                        <p class="mt-1 text-xs leading-5 text-slate-400">Manage privileged integrations, masked secrets, platform risk controls, and configuration audit history.</p>
                    </a>
                    <a href="{{ route('dashboard', ['view' => 'tools']) }}#system-status" class="rounded-lg border border-slate-800 bg-slate-950/35 p-4 transition hover:border-slate-700 hover:bg-slate-800/60">
                        <h3 class="text-sm font-semibold text-white">System status</h3>
                        <p class="mt-1 text-xs leading-5 text-slate-400">Review engine health, process status, queues, and safe operational telemetry.</p>
                    </a>
                </div>
            </section>
        @endif
    </div>
</x-layouts.shell>
