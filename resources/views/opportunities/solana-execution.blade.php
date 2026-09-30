<section data-solana-opportunity class="space-y-4 rounded-2xl border border-sky-400/25 bg-sky-400/5 p-6"
    data-user-id="{{ auth()->id() }}" data-attempt-id="{{ $attempt?->id }}"
    data-wallet-address="{{ $attempt?->wallet_address }}"
    data-prepare-url="{{ route('opportunities.solana.prepare', $opportunity) }}"
    data-confirm-url="{{ route('opportunities.solana.confirm', $opportunity) }}"
    data-arm-url="{{ route('opportunities.solana.signing', [$opportunity, 'arm']) }}"
    data-release-url="{{ route('opportunities.solana.signing', [$opportunity, 'release']) }}"
    data-rejected-url="{{ route('opportunities.solana.signing', [$opportunity, 'rejected']) }}"
    data-submitted-url="{{ route('opportunities.solana.submitted', $opportunity) }}">
    <h2 class="text-xl font-semibold text-white">Solana LIVE confirmation</h2>
    <p class="text-sm text-slate-300">The Trading Engine may prepare this trade, but only your wallet can sign and broadcast it. No wallet prompt opens automatically.</p>

    @if ($opportunity->status === \App\Enums\TradeOpportunityStatus::Executed)
        <p class="text-emerald-300">Executed / confirmed from authoritative Solana evidence.</p>
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::Failed)
        <p class="text-red-300">Execution failed. No automatic retry will occur.</p>
    @elseif ($attempt?->signing_armed_at && !$attempt->transaction_signature)
        <p class="text-amber-300">Wallet outcome unresolved. Check wallet history and report only the transaction from this attempt; do not send again.</p>
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::Expired)
        <p class="text-amber-300">Prepared transaction expired. If it was already broadcast, recover the exact signature below.</p>
    @elseif ($attempt?->status === 'submitted')
        <p class="text-amber-300">Submitted / awaiting authoritative Solana confirmation.</p>
    @elseif ($attempt?->status === 'preparing')
        <p>Preparation is in progress. Refresh before taking another action.</p>
    @elseif ($attempt?->status === 'prepared' && $attempt->expires_at?->isFuture() && !$attempt->signing_requested_at)
        <p>Ready for explicit wallet confirmation. The wallet will sign and broadcast the fixed transaction.</p>
        <button data-solana-confirm type="button" class="rounded-xl bg-emerald-400 px-5 py-3 text-sm font-bold text-slate-950">Confirm &amp; Broadcast in Wallet</button>
    @elseif ($attempt?->status === 'reserved')
        <p>The safe reservation exists, but provider preparation must be retried explicitly.</p>
        <button data-solana-prepare type="button" class="rounded-xl bg-sky-400 px-5 py-3 text-sm font-bold text-slate-950">Prepare transaction</button>
    @else
        <p>Awaiting verified engine evaluation or a recoverable lifecycle state.</p>
    @endif

    @if ($attempt)
        <div class="space-y-1 text-sm text-slate-400">
            <p>Attempt #{{ $attempt->id }} · <span class="uppercase">{{ $attempt->status }}</span></p>
            <p class="break-all">Reserved wallet: {{ $attempt->wallet_address }}</p>
            @if ($attempt->transaction_signature)
                <p class="break-all">Signature: <a href="https://solscan.io/tx/{{ $attempt->transaction_signature }}" target="_blank" rel="noopener noreferrer" class="text-sky-300 underline">{{ $attempt->transaction_signature }}</a></p>
            @endif
        </div>

        @if (config('services.trading_engine.live_recovery_enabled') && $attempt->signing_armed_at && !$attempt->transaction_signature && in_array($attempt->status, ['prepared', 'expired'], true))
            <div class="space-y-3 rounded-xl border border-amber-400/30 bg-amber-400/5 p-4">
                <p class="text-sm font-semibold text-amber-200">Recover a transaction already present in wallet history</p>
                <p class="text-xs text-slate-400">Reporting never signs or broadcasts. The server retrieves the chain transaction and verifies its exact prepared message before accepting it.</p>
                <input data-solana-known-signature type="text" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="90" placeholder="Solana signature" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm">
                <button data-solana-report-known type="button" class="rounded-lg border border-amber-400/40 px-4 py-2 text-sm font-semibold text-amber-200">Report known transaction</button>
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            <select data-solana-wallet aria-label="Solana wallet" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"></select>
            <button data-solana-connect type="button" class="rounded-lg border border-slate-700 px-3 py-2 text-sm">Connect wallet</button>
            <button data-solana-recover type="button" class="rounded-lg border border-amber-400/40 px-3 py-2 text-sm text-amber-200">Retry saved transaction report</button>
        </div>
    @endif

    <p data-solana-feedback role="status" class="break-words text-sm text-slate-300"></p>
    <a href="{{ route('opportunities.show', $opportunity) }}" class="inline-block text-sm text-sky-300 underline">Refresh status</a>
</section>
