<section
    id="ethereum-wallet"
    aria-labelledby="ethereum-wallet-heading"
    class="rounded-xl border border-slate-800 bg-slate-900/70"
    data-recovery-user="{{ auth()->id() }}"
    data-challenge-url="{{ route('wallets.ethereum.challenge') }}"
    data-verify-url="{{ route('wallets.ethereum.verify') }}"
    data-disconnect-url="{{ route('wallets.ethereum.disconnect') }}"
    data-balance-url="{{ route('wallets.ethereum.balance') }}"
    data-price-url="{{ route('wallets.ethereum.price') }}"
    data-order-url="{{ route('wallets.ethereum.order') }}"
    data-history-url="{{ route('wallets.ethereum.history') }}"
    data-submitted-url="{{ route('wallets.ethereum.submitted') }}"
    data-cancelled-url="{{ route('wallets.ethereum.cancelled') }}"
>
    <article class="p-4 sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <x-wallet-chain-icon chain="ethereum" :size="42" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="ethereum-wallet-heading" class="text-sm font-semibold text-white">Ethereum Wallet</h3>
                        <span
                            data-eth-status
                            @class([
                                'rounded-full border px-2 py-0.5 text-[10px] font-semibold',
                                'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => $ethereumWallet['connected'],
                                'border-slate-700 text-slate-400' => ! $ethereumWallet['connected'],
                            ])
                        >{{ $ethereumWallet['connected'] ? 'Verified' : 'Not connected' }}</span>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">{{ $ethereumWallet['network'] }} · <span data-eth-provider>{{ $ethereumWallet['provider'] }}</span></p>
                </div>
            </div>

            <div class="sm:text-right">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Balance</p>
                <p data-eth-balance class="mt-1 text-lg font-semibold tabular-nums text-white">{{ $ethereumWallet['connected'] ? 'Loading…' : '—' }}</p>
                <p data-eth-balance-usd class="mt-0.5 text-[11px] text-slate-500">{{ $ethereumWallet['connected'] ? 'Loading USD value…' : 'Unavailable until verified' }}</p>
            </div>
        </div>

        <div data-eth-empty @if($ethereumWallet['connected']) hidden @endif class="mt-4 rounded-lg border border-dashed border-slate-700 px-4 py-4">
            <p class="text-sm font-medium text-slate-300">No verified Ethereum wallet</p>
            <p class="mt-1 text-xs leading-5 text-slate-500">Connect an injected Phantom, MetaMask, or compatible EIP-1193 wallet on Ethereum Mainnet.</p>
        </div>

        <div data-eth-details @if(! $ethereumWallet['connected']) hidden @endif class="mt-4">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Verified address</p>
                    <div class="mt-1.5 flex min-w-0 items-center gap-2">
                        <span data-eth-address class="truncate font-mono text-sm text-slate-200" title="{{ $ethereumWallet['address'] }}">{{ $ethereumWallet['short_address'] }}</span>
                        <button type="button" class="copy-value rounded-md border border-slate-700 px-2 py-1 text-[11px] text-slate-400 hover:text-white" data-copy-value="{{ $ethereumWallet['address'] }}" aria-label="Copy Ethereum wallet address">Copy</button>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-500">Verified {{ $ethereumWallet['verified_at']?->diffForHumans() ?? 'time unavailable' }} · Browser session checked on action</p>
                </div>
                <button type="button" data-eth-balance-refresh @if(! $ethereumWallet['connected']) hidden @endif class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 hover:text-white disabled:opacity-50">Refresh balance</button>
            </div>

            <details id="ethereum-wallet-tools" class="mt-4 rounded-lg border border-slate-800 bg-slate-950/40">
                <summary class="cursor-pointer list-none px-4 py-3 text-xs font-semibold text-slate-300 marker:hidden">Transactions &amp; confirm-first swap tools</summary>
                <div class="space-y-5 border-t border-slate-800 p-4">
                    <form data-eth-swap-form class="space-y-4">
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-violet-400">Explicit confirmation required</p><h4 class="mt-1 text-sm font-semibold text-white">ETH to token</h4></div>
                        <div class="grid gap-3 md:grid-cols-3">
                            <label class="text-xs text-slate-300">Spend ETH<input data-eth-spend required inputmode="decimal" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white" placeholder="0.001"></label>
                            <label class="text-xs text-slate-300 md:col-span-2">Output token address<input data-eth-buy-token required class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-xs text-white" placeholder="0x…"></label>
                            <label class="text-xs text-slate-300">Slippage %<input data-eth-slippage required inputmode="decimal" value="1.00" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"></label>
                        </div>
                        <button type="submit" data-eth-price-submit class="rounded-lg border border-violet-400/40 px-3 py-2 text-xs font-semibold text-violet-300 disabled:opacity-50">Get price</button>
                        <dl data-eth-price-preview hidden class="grid gap-3 rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-xs sm:grid-cols-2">
                            <div><dt class="text-slate-500">Expected amount</dt><dd data-eth-buy-amount class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Minimum received</dt><dd data-eth-minimum class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Estimated network fee</dt><dd data-eth-network-fee class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Route</dt><dd data-eth-route class="mt-1 text-white"></dd></div>
                        </dl>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" data-eth-swap-confirm hidden class="rounded-lg bg-emerald-400 px-3 py-2 text-xs font-semibold text-slate-950 disabled:opacity-50">Confirm swap in wallet</button>
                            <button type="button" data-eth-report-retry hidden class="rounded-lg border border-violet-400/40 px-3 py-2 text-xs font-semibold text-violet-300 disabled:opacity-50">Retry transaction report</button>
                        </div>
                        <p class="text-[11px] leading-5 text-amber-200">Laravel validates the firm transaction before the wallet displays final approval. No request is sent on page load.</p>
                    </form>

                    <div data-eth-history class="space-y-3 border-t border-slate-800 pt-5">
                        <div class="flex items-center justify-between gap-3">
                            <div><p class="text-[10px] font-semibold uppercase tracking-wider text-violet-400">Recent activity</p><h4 class="mt-1 text-sm font-semibold text-white">Ethereum Transactions</h4></div>
                            <button type="button" data-eth-history-refresh class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 hover:text-white disabled:opacity-50">Refresh</button>
                        </div>
                        <div data-eth-history-loading class="text-xs text-slate-400">Loading recent transactions…</div>
                        <div data-eth-history-empty hidden class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 text-xs text-slate-400">No Ethereum transactions yet.</div>
                        <div data-eth-history-list class="space-y-3"></div>
                        <p data-eth-history-error hidden class="text-xs text-red-300"></p>
                    </div>
                </div>
            </details>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2.5">
            @if($user->hasVerifiedEmail() || $user->is_admin)
                <button type="button" data-eth-connect class="rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-500 disabled:opacity-50">{{ $ethereumWallet['connected'] ? 'Change wallet' : 'Connect Ethereum' }}</button>
                <button type="button" data-eth-disconnect @if(! $ethereumWallet['connected']) hidden @endif class="rounded-lg border border-red-400/30 px-3.5 py-2 text-xs font-semibold text-red-300 hover:bg-red-400/10 disabled:opacity-50">Disconnect</button>
            @else
                <a href="{{ route('verification.notice') }}" class="text-xs font-medium text-sky-400 underline">Verify your email before connecting</a>
            @endif

            @if($ethereumWallet['explorer_url'])
                <a href="{{ $ethereumWallet['explorer_url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-slate-700 px-3.5 py-2 text-xs font-medium text-slate-300 hover:text-white">View on Etherscan</a>
            @endif
        </div>

        <p data-eth-feedback role="status" aria-live="polite" class="mt-3 text-xs leading-5 text-slate-400"></p>
        <noscript><p class="mt-3 text-xs text-amber-300">Enable JavaScript to connect a browser wallet.</p></noscript>
    </article>

    <dialog data-eth-picker hidden class="w-[min(27rem,calc(100vw-2rem))] rounded-xl border border-slate-700 bg-slate-900 p-0 text-slate-100 shadow-2xl backdrop:bg-slate-950/80">
        <div class="flex items-start justify-between gap-4 border-b border-slate-800 px-5 py-4">
            <div><h3 class="text-base font-semibold text-white">Choose an Ethereum provider</h3><p class="mt-1 text-xs text-slate-400">Only EIP-1193 providers detected in this browser are listed.</p></div>
            <button type="button" data-eth-picker-cancel class="rounded-lg border border-slate-700 p-2 text-slate-400 hover:text-white" aria-label="Cancel Ethereum wallet connection"><x-app-icon name="close" :size="17" /></button>
        </div>
        <div data-eth-options class="grid gap-3 p-5"></div>
    </dialog>
</section>
