<section
    id="solana-wallet"
    aria-labelledby="solana-wallet-heading"
    class="rounded-xl border border-slate-800 bg-slate-900/70"
    data-challenge-url="{{ route('wallets.solana.challenge') }}"
    data-verify-url="{{ route('wallets.solana.verify') }}"
    data-disconnect-url="{{ route('wallets.solana.disconnect') }}"
    data-balance-url="{{ route('wallets.solana.balance') }}"
    data-quote-url="{{ route('wallets.solana.quote') }}"
    data-order-url="{{ route('wallets.solana.order') }}"
    data-execute-url="{{ route('wallets.solana.execute') }}"
    data-history-url="{{ route('wallets.solana.history') }}"
>
    <article class="p-4 sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <x-wallet-chain-icon chain="solana" :size="42" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="solana-wallet-heading" class="text-sm font-semibold text-white">Solana Wallet</h3>
                        <span
                            data-wallet-status
                            @class([
                                'rounded-full border px-2 py-0.5 text-[10px] font-semibold',
                                'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => $connectedWallet['connected'],
                                'border-slate-700 text-slate-400' => ! $connectedWallet['connected'],
                            ])
                        >{{ $connectedWallet['connected'] ? 'Verified' : 'Not connected' }}</span>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">{{ $connectedWallet['network'] }} · <span data-wallet-provider>{{ $connectedWallet['provider'] }}</span></p>
                </div>
            </div>

            <div class="sm:text-right">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Balance</p>
                <p data-wallet-balance class="mt-1 text-lg font-semibold tabular-nums text-white">{{ $connectedWallet['connected'] ? 'Loading…' : '—' }}</p>
                <p data-wallet-balance-usd class="mt-0.5 text-[11px] text-slate-500">{{ $connectedWallet['connected'] ? 'Loading USD value…' : 'Unavailable until verified' }}</p>
            </div>
        </div>

        <div data-wallet-empty @if($connectedWallet['connected']) hidden @endif class="mt-4 rounded-lg border border-dashed border-slate-700 px-4 py-4">
            <p class="text-sm font-medium text-slate-300">No verified Solana wallet</p>
            <p class="mt-1 text-xs leading-5 text-slate-500">Connect an injected Phantom, Solflare, or compatible browser wallet and sign a one-time ownership message.</p>
        </div>

        <div data-wallet-details @if(! $connectedWallet['connected']) hidden @endif class="mt-4">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Verified address</p>
                    <div class="mt-1.5 flex min-w-0 items-center gap-2">
                        <span data-wallet-address class="truncate font-mono text-sm text-slate-200" title="{{ $connectedWallet['address'] }}">{{ $connectedWallet['short_address'] }}</span>
                        <button type="button" class="copy-value rounded-md border border-slate-700 px-2 py-1 text-[11px] text-slate-400 hover:text-white" data-copy-value="{{ $connectedWallet['address'] }}" aria-label="Copy Solana wallet address">Copy</button>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-500">Verified {{ $connectedWallet['verified_at']?->diffForHumans() ?? 'time unavailable' }} · Browser session checked on action</p>
                </div>
                <button type="button" data-wallet-balance-refresh @if(! $connectedWallet['connected']) hidden @endif class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 hover:text-white disabled:opacity-50">Refresh balance</button>
            </div>

            <details id="solana-wallet-tools" class="mt-4 rounded-lg border border-slate-800 bg-slate-950/40">
                <summary class="cursor-pointer list-none px-4 py-3 text-xs font-semibold text-slate-300 marker:hidden">Transactions &amp; confirm-first swap tools</summary>
                <div class="space-y-5 border-t border-slate-800 p-4">
                    <form data-wallet-quote-form class="space-y-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-sky-400">Explicit confirmation required</p>
                            <h4 class="mt-1 text-sm font-semibold text-white">SOL swap quote</h4>
                        </div>
                        <div class="grid gap-3 md:grid-cols-3">
                            <label class="text-xs text-slate-300">Spend SOL<input data-quote-spend name="spend_sol" inputmode="decimal" required class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white" placeholder="Loading safe default…"><span data-quote-spend-help class="mt-1 block text-[11px] text-slate-500">Based on the verified wallet balance and configured risk limit.</span></label>
                            <label class="text-xs text-slate-300 md:col-span-2">Output token mint<input data-quote-output-mint name="output_mint" required autocomplete="off" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-xs text-white" placeholder="Solana token mint address"></label>
                            <label class="text-xs text-slate-300">Slippage %<input data-quote-slippage inputmode="decimal" value="1.00" required class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"></label>
                        </div>
                        <button type="submit" data-wallet-quote-submit class="rounded-lg border border-sky-400/40 px-3 py-2 text-xs font-semibold text-sky-300 disabled:opacity-50">Get quote</button>
                        <p class="text-[11px] leading-5 text-amber-200">A quote never signs or broadcasts a transaction. Final submission still opens the wallet for explicit approval.</p>
                        <dl data-wallet-quote-preview hidden class="grid gap-3 rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-xs sm:grid-cols-2 lg:grid-cols-3">
                            <div><dt class="text-slate-500">Spend</dt><dd data-quote-preview-spend class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Spend value</dt><dd data-quote-preview-usd class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Expected received</dt><dd data-quote-preview-output class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Minimum received</dt><dd data-quote-preview-minimum class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Slippage</dt><dd data-quote-preview-slippage class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Price impact</dt><dd data-quote-preview-impact class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Route</dt><dd data-quote-preview-route class="mt-1 text-white"></dd></div>
                            <div><dt class="text-slate-500">Provider fees</dt><dd data-quote-preview-fees class="mt-1 text-white"></dd></div>
                        </dl>
                        <button type="button" data-wallet-swap-confirm hidden class="rounded-lg bg-emerald-400 px-3 py-2 text-xs font-semibold text-slate-950 disabled:opacity-50">Confirm swap in wallet</button>
                    </form>

                    <div data-wallet-history class="space-y-3 border-t border-slate-800 pt-5">
                        <div class="flex items-center justify-between gap-3">
                            <div><p class="text-[10px] font-semibold uppercase tracking-wider text-sky-400">Recent activity</p><h4 class="mt-1 text-sm font-semibold text-white">Solana Transactions</h4></div>
                            <button type="button" data-wallet-history-refresh class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 hover:text-white disabled:opacity-50">Refresh</button>
                        </div>
                        <div data-wallet-history-loading class="text-xs text-slate-400">Loading recent transactions…</div>
                        <div data-wallet-history-empty hidden class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 text-xs text-slate-400">No Solana transactions yet.</div>
                        <div data-wallet-history-list class="space-y-3"></div>
                        <p data-wallet-history-error hidden class="text-xs text-red-300"></p>
                    </div>
                </div>
            </details>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2.5">
            @if($user->hasVerifiedEmail() || $user->is_admin)
                <button type="button" data-wallet-connect class="rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-500 disabled:opacity-50">{{ $connectedWallet['connected'] ? 'Change wallet' : 'Connect Solana' }}</button>
                <button type="button" data-wallet-disconnect @if(! $connectedWallet['connected']) hidden @endif class="rounded-lg border border-red-400/30 px-3.5 py-2 text-xs font-semibold text-red-300 hover:bg-red-400/10 disabled:opacity-50">Disconnect</button>
            @else
                <a href="{{ route('verification.notice') }}" class="text-xs font-medium text-sky-400 underline">Verify your email before connecting</a>
            @endif

            @if($connectedWallet['explorer_url'])
                <a href="{{ $connectedWallet['explorer_url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-slate-700 px-3.5 py-2 text-xs font-medium text-slate-300 hover:text-white">View on Solscan</a>
            @endif
        </div>

        <div data-wallet-disconnect-confirmation hidden class="mt-4 rounded-lg border border-amber-400/20 bg-amber-400/5 p-4">
            <p class="text-xs leading-5 text-amber-100">Disconnect this verified association? No funds move, and the wallet extension remains independently connected.</p>
            <div class="mt-3 flex gap-2">
                <button type="button" data-wallet-disconnect-cancel class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-200">Cancel</button>
                <button type="button" data-wallet-disconnect-confirm class="rounded-lg bg-red-500 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Confirm disconnect</button>
            </div>
        </div>

        <p data-wallet-feedback role="status" aria-live="polite" class="mt-3 text-xs leading-5 text-slate-400"></p>
        <noscript><p class="mt-3 text-xs text-amber-300">Enable JavaScript to connect a browser wallet.</p></noscript>
    </article>

    <dialog data-wallet-picker hidden class="w-[min(27rem,calc(100vw-2rem))] rounded-xl border border-slate-700 bg-slate-900 p-0 text-slate-100 shadow-2xl backdrop:bg-slate-950/80">
        <div class="flex items-start justify-between gap-4 border-b border-slate-800 px-5 py-4">
            <div><h3 class="text-base font-semibold text-white">Choose a Solana provider</h3><p class="mt-1 text-xs text-slate-400">Only compatible wallets detected in this browser are listed.</p></div>
            <button type="button" data-wallet-picker-cancel class="rounded-lg border border-slate-700 p-2 text-slate-400 hover:text-white" aria-label="Cancel Solana wallet connection"><x-app-icon name="close" :size="17" /></button>
        </div>
        <div data-wallet-options class="grid gap-3 p-5"></div>
    </dialog>
</section>
