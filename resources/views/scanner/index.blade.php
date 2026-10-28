@php
    $tabUrl = fn (string $tab): string => route('scanner.index', ['tab' => $tab]);
    $defaultActionKey = 'token-scan:solana';
@endphp

<x-layouts.shell title="Token Scanner" :administrator="(bool) auth()->user()->is_admin">
    <div class="mx-auto flex w-full max-w-[74rem] flex-col gap-4 sm:gap-5" data-scanner-screen>
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.025em] text-white sm:text-[1.75rem]">Token Scanner</h1>
            <p class="text-sm text-slate-400">Discover and analyze new meme tokens across supported networks.</p>
        </header>

        @if(session('success'))
            <div class="rounded-lg border border-emerald-400/25 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ session('success') }}</div>
        @endif
        @if(session('warning'))
            <div class="rounded-lg border border-amber-400/25 bg-amber-400/10 px-4 py-3 text-sm text-amber-200" role="status">{{ session('warning') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-lg border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">
                <p class="font-semibold">The scan could not be started.</p>
                <ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <nav class="flex gap-6 border-b border-slate-800" aria-label="Token Scanner sections">
            @foreach(['new' => 'New Token Scanner', 'history' => 'Scan History', 'providers' => 'Provider Status'] as $tab => $label)
                <a href="{{ $tabUrl($tab) }}" @class([
                    'relative -mb-px border-b-2 px-1 pb-3 text-xs font-medium transition',
                    'border-sky-400 text-sky-300' => $activeTab === $tab,
                    'border-transparent text-slate-400 hover:text-slate-200' => $activeTab !== $tab,
                ]) @if($activeTab === $tab) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        @if($activeTab === 'new')
            <section class="rounded-lg border border-slate-700/80 bg-slate-900/75 p-4 shadow-lg shadow-black/10" aria-labelledby="run-scan-heading">
                <h2 id="run-scan-heading" class="text-sm font-semibold text-white">Run New Scan</h2>
                <form
                    method="POST"
                    action="{{ route('dashboard.actions.store', 'token-scan') }}"
                    class="mt-4"
                    data-token-scan-form
                    data-new-token-action="{{ route('dashboard.actions.store', 'token-scan') }}"
                    data-momentum-action="{{ route('dashboard.actions.store', 'momentum-scan') }}"
                    data-max-chase-percent="{{ rtrim(rtrim(number_format($scannerConfiguration['max_chase_percent'], 2), '0'), '.') }}"
                    data-momentum-budget="{{ $scannerConfiguration['momentum_birdeye_budget'] }}"
                    data-running-actions='@json($runningActions)'
                >
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="grid gap-1.5 text-[11px] font-medium text-slate-300">
                            Network
                            <select name="chain" data-scan-network class="min-h-10 rounded-md border border-slate-700 bg-slate-950/40 px-3 text-xs text-slate-100">
                                @foreach($chains as $chain)
                                    <option value="{{ $chain->value }}" @selected(old('chain', 'solana') === $chain->value)>{{ $chain->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1.5 text-[11px] font-medium text-slate-300">
                            Scan Type
                            <select data-scan-type class="min-h-10 rounded-md border border-slate-700 bg-slate-950/40 px-3 text-xs text-slate-100">
                                <option value="token-scan">New Token</option>
                                <option value="momentum-scan">Momentum</option>
                            </select>
                        </label>
                        <div class="grid gap-1.5 text-[11px] font-medium text-slate-300">
                            Platform policy
                            <div class="flex min-h-10 items-center rounded-md border border-slate-700 bg-slate-950/25 px-3 text-xs text-slate-300" data-scan-policy>
                                Max chase {{ rtrim(rtrim(number_format($scannerConfiguration['max_chase_percent'], 2), '0'), '.') }}%
                            </div>
                        </div>
                    </div>
                    <p class="mt-2 text-[10px] leading-4 text-slate-500" data-scan-help>
                        New-token candidates are evaluated with the current authorized platform policy. Provider budgets are managed server-side and cannot be edited here.
                    </p>
                    <button type="submit" data-scan-submit class="mt-4 flex min-h-10 w-full items-center justify-center rounded-md bg-blue-600 px-4 text-xs font-semibold text-white shadow-sm shadow-blue-950/30 transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-55" @disabled(in_array($defaultActionKey, $runningActions, true))>
                        <span data-scan-submit-label>{{ in_array($defaultActionKey, $runningActions, true) ? 'Scan already running' : 'Start Scan' }}</span>
                    </button>
                    <noscript><p class="mt-2 text-xs text-amber-200">JavaScript is required to switch scanner type. Without it, this form starts the New Token Scanner only.</p></noscript>
                </form>
            </section>

            @if($latestScan)
                @include('scanner.partials.scan-summary', ['scan' => $latestScan])
            @else
                <section class="rounded-lg border border-dashed border-slate-700 bg-slate-900/45 px-5 py-10 text-center">
                    <h2 class="text-sm font-semibold text-white">No scans yet</h2>
                    <p class="mt-1 text-xs text-slate-400">Start an authorized scan to create the first result. Nothing runs automatically.</p>
                </section>
            @endif
        @elseif($activeTab === 'history')
            <section class="rounded-lg border border-slate-700/80 bg-slate-900/75 p-4 shadow-lg shadow-black/10" aria-labelledby="scan-history-heading">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 id="scan-history-heading" class="text-sm font-semibold text-white">Scan History</h2>
                        <p class="mt-1 text-[11px] text-slate-500">Authorized Laravel and engine-owned scanner executions.</p>
                    </div>
                    <span class="text-[11px] tabular-nums text-slate-500">{{ number_format($history->total()) }} scans</span>
                </div>

                <form method="GET" action="{{ route('scanner.index') }}" class="mt-4 grid gap-2 sm:grid-cols-[repeat(3,minmax(0,1fr))_auto]">
                    <input type="hidden" name="tab" value="history">
                    <label class="grid gap-1 text-[10px] font-medium text-slate-400">Status
                        <select name="status" class="min-h-9 rounded-md border border-slate-700 bg-slate-950/40 px-2.5 text-xs text-slate-200">
                            @foreach(['all' => 'All statuses', 'pending' => 'Pending', 'running' => 'Running', 'completed' => 'Completed', 'completed_with_warnings' => 'Completed with warnings', 'failed' => 'Failed'] as $value => $label)
                                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-[10px] font-medium text-slate-400">Scanner
                        <select name="scanner" class="min-h-9 rounded-md border border-slate-700 bg-slate-950/40 px-2.5 text-xs text-slate-200">
                            @foreach(['all' => 'All scanners', 'new-token' => 'New Token', 'momentum' => 'Momentum'] as $value => $label)
                                <option value="{{ $value }}" @selected($filters['scanner'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-[10px] font-medium text-slate-400">Network
                        <select name="network" class="min-h-9 rounded-md border border-slate-700 bg-slate-950/40 px-2.5 text-xs text-slate-200">
                            @foreach(['all' => 'All networks', 'solana' => 'Solana', 'ethereum' => 'Ethereum'] as $value => $label)
                                <option value="{{ $value }}" @selected($filters['network'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="mt-auto min-h-9 rounded-md bg-blue-600 px-4 text-xs font-semibold text-white hover:bg-blue-500">Filter</button>
                </form>

                <div class="mt-4 overflow-x-auto rounded-md border border-slate-800" role="region" aria-label="Scan history table" tabindex="0">
                    <table class="min-w-[62rem] w-full text-left text-[11px]">
                        <thead class="bg-slate-950/35 text-slate-400">
                            <tr><th class="px-3 py-2.5 font-medium">Scanner</th><th class="px-3 py-2.5 font-medium">Submitted</th><th class="px-3 py-2.5 font-medium">Status</th><th class="px-3 py-2.5 text-right font-medium">Discovered</th><th class="px-3 py-2.5 text-right font-medium">Examined</th><th class="px-3 py-2.5 text-right font-medium">Qualified</th><th class="px-3 py-2.5 text-right font-medium">Failed</th><th class="px-3 py-2.5 text-right font-medium">Skipped</th><th class="px-3 py-2.5 text-right font-medium">Opps</th><th class="px-3 py-2.5 font-medium">Action</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @forelse($history as $scan)
                                <tr class="bg-slate-900/20">
                                    <td class="px-3 py-3"><strong class="block text-slate-100">{{ $scan['scanner_label'] }}</strong><span class="text-slate-500">{{ $scan['network_label'] }}</span></td>
                                    <td class="px-3 py-3 text-slate-300"><time datetime="{{ $scan['submitted_at_iso'] }}">{{ $scan['submitted_at_display'] }}</time>@if($scan['completed_at_display'])<span class="block text-[10px] text-slate-500">Completed {{ $scan['completed_at_display'] }}</span>@endif</td>
                                    <td class="px-3 py-3">@include('scanner.partials.status-badge', ['scan' => $scan])@if(($scan['provider_warning_count'] ?? 0) > 0)<span class="mt-1 block text-[10px] text-amber-300">{{ $scan['provider_warning_count'] }} provider warning(s)</span>@endif</td>
                                    @foreach(['discovered', 'examined', 'qualified', 'failed', 'skipped', 'opportunities'] as $field)
                                        <td class="px-3 py-3 text-right tabular-nums {{ $scan['counts'][$field] === null ? 'text-slate-600' : 'text-slate-200' }}">{{ $scan['counts'][$field] === null ? '—' : number_format($scan['counts'][$field]) }}</td>
                                    @endforeach
                                    <td class="px-3 py-3"><a href="{{ route('scanner.show', $scan['id']) }}" class="font-semibold text-sky-300 hover:text-sky-200">View</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="px-4 py-10 text-center text-xs text-slate-500">No scans match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($history->hasPages())<div class="mt-4">{{ $history->links() }}</div>@endif
            </section>
        @else
            <section class="rounded-lg border border-slate-700/80 bg-slate-900/75 p-4 shadow-lg shadow-black/10" aria-labelledby="provider-status-heading">
                <div>
                    <h2 id="provider-status-heading" class="text-sm font-semibold text-white">Provider Status</h2>
                    <p class="mt-1 text-[11px] text-slate-500">Configuration and current authenticated health are shown separately. Configured does not mean reachable.</p>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach($providerStatus as $provider)
                        @php($healthy = in_array($provider['status'], ['configured', 'active'], true))
                        <article class="rounded-md border border-slate-800 bg-slate-950/25 p-3">
                            <div class="flex items-center justify-between gap-3"><h3 class="text-xs font-semibold text-slate-100">{{ $provider['label'] }}</h3><span @class(['rounded px-2 py-1 text-[10px] font-semibold', 'bg-emerald-400/12 text-emerald-300' => $healthy, 'bg-amber-400/12 text-amber-200' => ! $healthy])>{{ str_replace('_', ' ', ucfirst($provider['status'])) }}</span></div>
                            <p class="mt-2 text-[11px] leading-5 text-slate-400">{{ $provider['detail'] }}</p>
                        </article>
                    @endforeach
                </div>
                <p class="mt-4 rounded-md border border-slate-800 bg-slate-950/25 px-3 py-2.5 text-[11px] leading-5 text-slate-400">Provider retry policy, credentials, and technical process diagnostics remain in the authorized operations interface; this page does not expose secrets or raw provider responses.</p>
            </section>
        @endif
    </div>
</x-layouts.shell>
