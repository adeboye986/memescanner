    <section id="solana-wallet"
        aria-labelledby="wallet-heading"
        class="space-y-5 rounded-2xl border border-slate-800 bg-slate-900/70 p-6"
        data-challenge-url="{{ route('wallets.solana.challenge') }}"
        data-verify-url="{{ route('wallets.solana.verify') }}"
        data-disconnect-url="{{ route('wallets.solana.disconnect') }}"
        data-balance-url="{{ route('wallets.solana.balance') }}"
        data-quote-url="{{ route('wallets.solana.quote') }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-widest text-sky-400">Solana · Non-custodial</p><h2 id="wallet-heading" class="mt-2 text-xl font-semibold text-white">Connect Wallet</h2></div>
        <span data-wallet-status class="rounded-lg border border-slate-700 px-3 py-1 text-sm text-slate-200">{{ $connectedWallet ? 'Connected / Verified' : 'Not connected' }}</span>
    </div>
    <p class="text-sm text-slate-400">Verify ownership of your existing browser wallet by signing a message. This does not authorize a transaction or enable live trading. Never share your seed phrase or private key.</p>
    <p data-wallet-empty @if($connectedWallet) hidden @endif class="text-sm text-slate-300">No live wallet is connected.</p>
    <div data-wallet-details @if(!$connectedWallet) hidden @endif class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-4">
        <p class="text-sm text-emerald-300">Solana · <span data-wallet-provider>{{ ucfirst($connectedWallet?->provider ?? 'compatible') }}</span></p>
        <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                    SOL Balance
                </p>
                <p data-wallet-balance class="mt-1 text-2xl font-semibold text-white">
                    {{ $connectedWallet ? 'Loading…' : '—' }}
                </p>
                <p data-wallet-balance-usd class="mt-1 text-sm text-slate-400">{{ $connectedWallet ? 'Loading USD value…' : '' }}</p>
            </div>

            <button
                type="button"
                data-wallet-balance-refresh
                @if(!$connectedWallet) hidden @endif
                class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:text-white disabled:opacity-50">
                Refresh balance
            </button>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <span data-wallet-address class="font-mono text-slate-100" title="{{ $connectedWallet?->address }}">{{ $connectedWallet ? substr($connectedWallet->address, 0, 4).'…'.substr($connectedWallet->address, -4) : '' }}</span>
            <button type="button" class="copy-value rounded-lg border border-slate-700 px-3 py-1 text-sm text-slate-300 hover:text-white" data-copy-value="{{ $connectedWallet?->address }}">Copy</button>
        </div>
        <p class="mt-2 text-xs text-slate-400">Ownership verified for this account. Disconnecting here removes this wallet from active use on your account without moving funds or disconnecting Phantom or Solflare.</p>
        <form data-wallet-quote-form class="mt-5 space-y-4 border-t border-slate-800 pt-5">
            <div><p class="text-xs font-semibold uppercase tracking-wider text-sky-400">Read-only preview</p><h3 class="mt-1 font-semibold text-white">SOL swap quote</h3></div>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm text-slate-300">Spend SOL<input data-quote-spend name="spend_sol" inputmode="decimal" required class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white" placeholder="Loading safe default…"><span data-quote-spend-help class="mt-1 block text-xs text-slate-500">Based on the verified wallet balance and configured risk limit.</span></label>
                <label class="text-sm text-slate-300 md:col-span-2">Output token mint<input data-quote-output-mint name="output_mint" required autocomplete="off" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-white" placeholder="Solana token mint address"></label>
                <label class="text-sm text-slate-300">Slippage %<input data-quote-slippage inputmode="decimal" value="1.00" required class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"></label>
            </div>
            <button type="submit" data-wallet-quote-submit class="rounded-lg border border-sky-400/40 px-4 py-2 text-sm font-semibold text-sky-300 disabled:opacity-50">Get quote</button>
            <p class="text-xs text-amber-200">Quote only — no transaction has been created or signed.</p>
            <dl data-wallet-quote-preview hidden class="grid gap-3 rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-slate-500">Spend</dt><dd data-quote-preview-spend class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Spend value</dt><dd data-quote-preview-usd class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Expected received</dt><dd data-quote-preview-output class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Minimum received</dt><dd data-quote-preview-minimum class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Slippage</dt><dd data-quote-preview-slippage class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Price impact</dt><dd data-quote-preview-impact class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Route</dt><dd data-quote-preview-route class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Provider fees</dt><dd data-quote-preview-fees class="mt-1 text-white"></dd></div>
            </dl>
        </form>
    </div>
    @if($user->hasVerifiedEmail() || $user->is_admin)
        <div class="flex flex-wrap gap-3">
            <button type="button" data-wallet-connect class="rounded-xl bg-sky-400 px-5 py-3 font-semibold text-slate-950 disabled:opacity-50">{{ $connectedWallet ? 'Change wallet' : 'Connect Wallet' }}</button>
            <button type="button" data-wallet-disconnect @if(!$connectedWallet) hidden @endif class="rounded-xl border border-red-400/30 px-5 py-3 font-semibold text-red-300 hover:bg-red-400/10 disabled:opacity-50">Disconnect wallet</button>
        </div>
        <div data-wallet-disconnect-confirmation hidden class="space-y-3 rounded-xl border border-amber-400/20 bg-amber-400/5 p-4">
            <p class="text-sm text-amber-100">Disconnect this wallet from your account? This does not move funds or revoke the wallet extension's access.</p>
            <div class="flex flex-wrap gap-3">
                <button type="button" data-wallet-disconnect-cancel class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-200">Cancel</button>
                <button type="button" data-wallet-disconnect-confirm class="rounded-lg bg-red-400 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-50">Confirm disconnect</button>
            </div>
        </div>
        <div data-wallet-picker hidden class="space-y-3"><p class="text-sm text-slate-300">Choose a wallet to connect:</p><div data-wallet-options class="flex flex-wrap gap-3"></div></div>
    @else
        <a href="{{ route('verification.notice') }}" class="text-sm text-sky-400 underline">Verify your email before connecting a wallet</a>
    @endif
    <p data-wallet-feedback role="status" aria-live="polite" class="text-sm text-slate-300"></p>
    <noscript><p class="text-sm text-amber-300">Enable JavaScript to connect your browser wallet.</p></noscript>
</section>
