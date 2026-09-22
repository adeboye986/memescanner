<section data-ethereum-opportunity class="space-y-4 rounded-2xl border border-violet-400/25 bg-violet-400/5 p-6"
    data-user-id="{{ auth()->id() }}" data-attempt-id="{{ $attempt?->id }}"
    data-wallet-address="{{ $attempt?->wallet_address }}" data-sell-amount-wei="{{ $attempt?->sell_amount_wei }}"
    data-signing-requested="{{ $attempt?->signing_requested_at ? '1' : '0' }}"
    data-prepare-url="{{ route('opportunities.ethereum.prepare', $opportunity) }}"
    data-confirm-url="{{ route('opportunities.ethereum.confirm', $opportunity) }}"
    data-arm-url="{{ route('opportunities.ethereum.signing', [$opportunity, 'arm']) }}"
    data-release-url="{{ route('opportunities.ethereum.signing', [$opportunity, 'release']) }}"
    data-rejected-url="{{ route('opportunities.ethereum.signing', [$opportunity, 'rejected']) }}"
    data-submitted-url="{{ route('wallets.ethereum.submitted') }}" data-cancelled-url="{{ route('wallets.ethereum.cancelled') }}">
    <h2 class="text-xl font-semibold text-white">Ethereum LIVE confirmation</h2>
    @if ($opportunity->status === \App\Enums\TradeOpportunityStatus::PendingConfirmation)
        <p>Awaiting approval. Choose your spend and slippage, then prepare and confirm separately in your wallet.</p>
        <form data-opportunity-approval method="POST" action="{{ route('opportunities.approve', $opportunity) }}" class="flex flex-wrap items-end gap-4">
            @csrf
            <input type="hidden" name="sell_amount_wei" value="{{ old('sell_amount_wei', $attempt?->sell_amount_wei) }}">
            <label class="grid gap-2 text-sm">Spend (ETH)<input data-opportunity-spend required inputmode="decimal" value="{{ $attempt ? app(\App\Services\TokenAmountFormatter::class)->format($attempt->sell_amount_wei, 18) : '' }}" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2" @readonly($attempt !== null)></label>
            <label class="grid gap-2 text-sm">Slippage (basis points; 100 = 1%)<input name="slippage_bps" type="number" required min="1" max="500" value="{{ old('slippage_bps', $attempt?->slippage_bps ?? 100) }}" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2" @readonly($attempt !== null)></label>
            <button class="rounded-xl bg-violet-400 px-5 py-3 text-sm font-bold text-slate-950">Approve Opportunity</button>
        </form>
        <form method="POST" action="{{ route('opportunities.ignore', $opportunity) }}">@csrf<button class="text-sm text-slate-300 underline">Ignore</button></form>
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::Executed)
        <p class="text-emerald-300">Executed / confirmed.</p>
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::Failed)
        <p class="text-red-300">Execution failed. No automatic retry will occur.</p>
    @elseif ($attempt?->signing_requested_at && !$attempt->transaction_hash && in_array($attempt->status, ['prepared', 'expired'], true))
        <p class="text-amber-300">{{ $attempt->signing_armed_at ? 'Signing request outcome unresolved. Check wallet activity before further action. Recover any known transaction report; do not send again.' : 'Signing handoff unresolved. No transaction submission is recorded. A lost response or closed page before wallet invocation requires manual resolution; no automatic retry is permitted.' }}</p>
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::Expired)
        <p class="text-amber-300">Expired. This payload cannot be sent again. Recover any transaction already broadcast below.</p>
    @elseif ($opportunity->status === \App\Enums\TradeOpportunityStatus::Ignored)
        <p>Ignored / cancelled.</p>
    @elseif ($attempt?->status === 'submitted')
        <p class="text-amber-300">Transaction submitted / awaiting blockchain confirmation.</p>
    @elseif ($attempt?->status === 'preparing')
        <p>Preparing. Refresh to check whether the transaction is ready.</p>
    @elseif ($attempt?->status === 'prepared' && $attempt->signing_requested_at)
        <p class="text-amber-300">Wallet handoff already requested. Recover any known transaction report. If no hash was returned before the page closed, check wallet history; do not send again.</p>
    @elseif ($attempt?->status === 'prepared' && $attempt->expires_at?->isFuture())
        <p>Ready for wallet confirmation. Spend and transaction details are fixed by your approved reservation.</p>
        <button data-opportunity-confirm type="button" class="rounded-xl bg-emerald-400 px-5 py-3 text-sm font-bold text-slate-950">Confirm &amp; Buy</button>
    @elseif ($attempt?->status === 'reserved')
        <p>Approved. Prepare the reserved trade before opening your wallet.</p>
        <button data-opportunity-prepare type="button" class="rounded-xl bg-violet-400 px-5 py-3 text-sm font-bold text-slate-950">Prepare transaction</button>
    @else
        <p>Preparation is unavailable or expired. Refresh the opportunity status.</p>
    @endif
    @if ($attempt)
        <p class="break-all text-sm text-slate-400">Reserved wallet: {{ $attempt->wallet_address }}</p>
        <div class="flex flex-wrap gap-3">
            <select data-opportunity-wallet aria-label="Ethereum wallet" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"></select>
            <button data-opportunity-connect type="button" class="rounded-lg border border-slate-700 px-3 py-2 text-sm">Connect wallet</button>
            <button data-opportunity-report type="button" hidden class="rounded-lg border border-amber-400/40 px-3 py-2 text-sm text-amber-200">Retry transaction report</button>
        </div>
    @endif
    <p data-opportunity-feedback role="status" class="break-words text-sm text-slate-300"></p>
    <a href="{{ route('opportunities.show', $opportunity) }}" class="inline-block text-sm text-violet-300 underline">Refresh status</a>
</section>
