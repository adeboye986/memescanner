<section id="ethereum-wallet"
    aria-labelledby="ethereum-wallet-heading"
    class="space-y-5 rounded-2xl border border-slate-800 bg-slate-900/70 p-6"
    data-challenge-url="{{ route('wallets.ethereum.challenge') }}"
    data-verify-url="{{ route('wallets.ethereum.verify') }}"
    data-disconnect-url="{{ route('wallets.ethereum.disconnect') }}"
    data-balance-url="{{ route('wallets.ethereum.balance') }}"
    data-price-url="{{ route('wallets.ethereum.price') }}"
    data-order-url="{{ route('wallets.ethereum.order') }}"
    data-submitted-url="{{ route('wallets.ethereum.submitted') }}"
    data-cancelled-url="{{ route('wallets.ethereum.cancelled') }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-violet-400">Ethereum · Non-custodial</p>
            <h2 id="ethereum-wallet-heading" class="mt-2 text-xl font-semibold text-white">Connect Ethereum Wallet</h2>
        </div>
        <span data-eth-status class="rounded-lg border border-slate-700 px-3 py-1 text-sm text-slate-200">{{ $ethereumWallet ? 'Connected / Verified' : 'Not connected' }}</span>
    </div>
    <p class="text-sm text-slate-400">Use Phantom, MetaMask, or another compatible EIP-1193 wallet. Signing proves ownership only and never exposes your private key.</p>
    <div data-eth-details @if(!$ethereumWallet) hidden @endif class="space-y-4 rounded-xl border border-violet-400/20 bg-violet-400/5 p-4">
        <p class="text-sm text-violet-300">Ethereum Mainnet · <span data-eth-provider>{{ ucfirst($ethereumWallet?->provider ?? 'compatible') }}</span></p>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">ETH Balance</p>
                <p data-eth-balance class="mt-1 text-2xl font-semibold text-white">{{ $ethereumWallet ? 'Loading…' : '—' }}</p>
                <p data-eth-balance-usd class="mt-1 text-sm text-slate-400"></p>
            </div>
            <button type="button" data-eth-balance-refresh @if(!$ethereumWallet) hidden @endif class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:text-white disabled:opacity-50">Refresh balance</button>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <span data-eth-address class="font-mono text-slate-100" title="{{ $ethereumWallet?->address }}">{{ $ethereumWallet ? substr($ethereumWallet->address, 0, 6).'…'.substr($ethereumWallet->address, -4) : '' }}</span>
            <button type="button" class="copy-value rounded-lg border border-slate-700 px-3 py-1 text-sm text-slate-300 hover:text-white" data-copy-value="{{ $ethereumWallet?->address }}">Copy</button>
        </div>
        <form data-eth-swap-form class="space-y-4 border-t border-slate-800 pt-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-violet-400">Confirm-first swap</p>
                <h3 class="mt-1 font-semibold text-white">ETH to token</h3>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm text-slate-300">Spend ETH<input data-eth-spend required inputmode="decimal" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white" placeholder="0.001"></label>
                <label class="text-sm text-slate-300 md:col-span-2">Output token address<input data-eth-buy-token required class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-white" placeholder="0x…"></label>
                <label class="text-sm text-slate-300">Slippage %<input data-eth-slippage required inputmode="decimal" value="1.00" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"></label>
            </div>
            <button type="submit" data-eth-price-submit class="rounded-lg border border-violet-400/40 px-4 py-2 text-sm font-semibold text-violet-300 disabled:opacity-50">Get price</button>
            <dl data-eth-price-preview hidden class="grid gap-3 rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Expected amount</dt><dd data-eth-buy-amount class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Minimum received</dt><dd data-eth-minimum class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Estimated network fee</dt><dd data-eth-network-fee class="mt-1 text-white"></dd></div>
                <div><dt class="text-slate-500">Route</dt><dd data-eth-route class="mt-1 text-white"></dd></div>
            </dl>
            <button type="button" data-eth-swap-confirm hidden class="rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-50">Confirm swap in wallet</button>
            <p data-eth-feedback role="status" aria-live="polite" class="text-sm text-slate-300"></p>
            <p class="text-xs text-amber-200">The firm 0x transaction is validated by Laravel before Phantom, MetaMask, or your compatible wallet displays the final approval.</p>
        </form>
    </div>
    <div class="flex flex-wrap gap-3">
        <button type="button" data-eth-connect class="rounded-xl bg-violet-400 px-5 py-3 font-semibold text-slate-950 disabled:opacity-50">{{ $ethereumWallet ? 'Change wallet' : 'Connect Ethereum Wallet' }}</button>
        <button type="button" data-eth-disconnect @if(!$ethereumWallet) hidden @endif class="rounded-xl border border-red-400/30 px-5 py-3 font-semibold text-red-300 hover:bg-red-400/10 disabled:opacity-50">Disconnect wallet</button>
    </div>
    <div data-eth-picker hidden class="space-y-3">
        <p class="text-sm text-slate-300">Choose an Ethereum wallet:</p>
        <div data-eth-options class="flex flex-wrap gap-3"></div>
    </div>
</section>
