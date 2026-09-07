<section id="solana-wallet" aria-labelledby="wallet-heading" class="space-y-5 rounded-2xl border border-slate-800 bg-slate-900/70 p-6"
    data-challenge-url="{{ route('wallets.solana.challenge') }}" data-verify-url="{{ route('wallets.solana.verify') }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-widest text-sky-400">Solana · Non-custodial</p><h2 id="wallet-heading" class="mt-2 text-xl font-semibold text-white">Connect Wallet</h2></div>
        <span data-wallet-status class="rounded-lg border border-slate-700 px-3 py-1 text-sm text-slate-200">{{ $connectedWallet ? 'Connected / Verified' : 'Not connected' }}</span>
    </div>
    <p class="text-sm text-slate-400">Verify ownership of your existing browser wallet by signing a message. This does not authorize a transaction or enable live trading. Never share your seed phrase or private key.</p>
    <p data-wallet-empty @if($connectedWallet) hidden @endif class="text-sm text-slate-300">No live wallet is connected.</p>
    <div data-wallet-details @if(!$connectedWallet) hidden @endif class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-4">
        <p class="text-sm text-emerald-300">Solana · <span data-wallet-provider>{{ ucfirst($connectedWallet?->provider ?? 'compatible') }}</span></p>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <span data-wallet-address class="font-mono text-slate-100" title="{{ $connectedWallet?->address }}">{{ $connectedWallet ? substr($connectedWallet->address, 0, 4).'…'.substr($connectedWallet->address, -4) : '' }}</span>
            <button type="button" class="copy-value rounded-lg border border-slate-700 px-3 py-1 text-sm text-slate-300 hover:text-white" data-copy-value="{{ $connectedWallet?->address }}">Copy</button>
        </div>
        <p class="mt-2 text-xs text-slate-400">Ownership verified for this account. Browser wallet sessions may disconnect independently.</p>
    </div>
    @if($user->hasVerifiedEmail() || $user->is_admin)
        <button type="button" data-wallet-connect class="rounded-xl bg-sky-400 px-5 py-3 font-semibold text-slate-950 disabled:opacity-50">{{ $connectedWallet ? 'Connect another wallet' : 'Connect Wallet' }}</button>
        <div data-wallet-picker hidden class="space-y-3"><p class="text-sm text-slate-300">Choose a wallet to connect:</p><div data-wallet-options class="flex flex-wrap gap-3"></div></div>
    @else
        <a href="{{ route('verification.notice') }}" class="text-sm text-sky-400 underline">Verify your email before connecting a wallet</a>
    @endif
    <p data-wallet-feedback role="status" aria-live="polite" class="text-sm text-slate-300"></p>
    <noscript><p class="text-sm text-amber-300">Enable JavaScript to connect your browser wallet.</p></noscript>
</section>
