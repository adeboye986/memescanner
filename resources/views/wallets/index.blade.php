@php
    $connectedWallets = $walletDashboard['connected'];
    $connectedCount = collect($connectedWallets)->where('connected', true)->count();
@endphp

<x-layouts.shell title="Wallets" :administrator="(bool) auth()->user()->is_admin">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-wallets-page>
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-[1.45rem] font-semibold leading-7 tracking-[-0.025em] text-white">Wallets</h1>
                <p class="mt-1 text-[13px] leading-5 text-slate-400">Connect and manage your verified trading wallets.</p>
            </div>

            @if($user->hasVerifiedEmail() || $user->is_admin)
                <button
                    type="button"
                    data-wallet-page-connect
                    class="inline-flex min-h-10 items-center justify-center gap-2 self-start rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-blue-950/20 transition hover:bg-blue-500"
                >
                    <x-app-icon name="wallet" :size="17" />
                    Connect Wallet
                </button>
            @else
                <a href="{{ route('verification.notice') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                    Verify email to connect
                </a>
            @endif
        </header>

        @if(session('success'))
            <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">
                {{ session('success') }}
            </div>
        @endif

        <nav class="flex gap-7 overflow-x-auto overflow-y-hidden border-b border-slate-800 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Wallet page sections">
            <a href="#connected-wallets" class="border-b-2 border-blue-500 px-0.5 pb-3 text-[13px] font-semibold text-blue-300">Connected Wallets</a>
            <a href="#solana-wallet-tools" class="border-b-2 border-transparent px-0.5 pb-3 text-[13px] font-medium text-slate-400 hover:text-slate-200">Transactions</a>
            <a href="#paper-wallets" class="border-b-2 border-transparent px-0.5 pb-3 text-[13px] font-medium text-slate-400 hover:text-slate-200">PAPER Wallets</a>
        </nav>

        <section id="connected-wallets" aria-labelledby="connected-wallets-heading" class="flex flex-col gap-3">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h2 id="connected-wallets-heading" class="text-base font-semibold text-white">Connected Wallets</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $connectedCount }} of 2 supported networks verified to this account.</p>
                </div>
                <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Non-custodial</span>
            </div>

            @include('account.wallet', ['connectedWallet' => $connectedWallets['solana']])
            @include('account.ethereum-wallet', ['ethereumWallet' => $connectedWallets['ethereum']])
        </section>

        <section id="paper-wallets" aria-labelledby="paper-wallets-heading" class="flex flex-col gap-4 border-t border-slate-800 pt-6">
            <div>
                <h2 id="paper-wallets-heading" class="text-base font-semibold text-white">PAPER Wallets</h2>
                <p class="mt-1 max-w-3xl text-xs leading-5 text-slate-500">Simulated balances remain separate from verified blockchain wallets and from each other. Connecting a wallet never deposits, transfers, or combines funds.</p>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @foreach([
                    ['key' => 'engine_paper', 'label' => 'Engine PAPER', 'description' => 'TypeScript trading-engine projections'],
                    ['key' => 'legacy_paper', 'label' => 'Legacy PAPER', 'description' => 'Historical Laravel-managed balances'],
                ] as $source)
                    <article class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900/70">
                        <header class="flex items-center justify-between gap-3 border-b border-slate-800 px-4 py-3">
                            <div>
                                <h3 class="text-sm font-semibold text-white">{{ $source['label'] }}</h3>
                                <p class="mt-0.5 text-[11px] text-slate-500">{{ $source['description'] }}</p>
                            </div>
                            <span class="rounded-full border border-blue-400/20 bg-blue-400/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-wider text-blue-300">Simulated</span>
                        </header>

                        <div class="divide-y divide-slate-800">
                            @forelse($walletDashboard[$source['key']] as $paperWallet)
                                <div class="px-4 py-3.5">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-100">{{ $paperWallet['network'] }} · {{ $paperWallet['currency'] }}</p>
                                            <p class="mt-0.5 text-[11px] text-slate-500">Updated {{ $paperWallet['observed_at']?->diffForHumans() ?? 'time unavailable' }}</p>
                                        </div>
                                        <p class="text-right text-sm font-semibold tabular-nums text-slate-100">{{ $paperWallet['available'] }} {{ $paperWallet['currency'] }}</p>
                                    </div>
                                    <dl class="mt-3 grid grid-cols-3 gap-3 text-[11px]">
                                        <div><dt class="text-slate-500">Invested</dt><dd class="mt-1 tabular-nums text-slate-300">{{ $paperWallet['invested'] }} {{ $paperWallet['currency'] }}</dd></div>
                                        <div><dt class="text-slate-500">Opening</dt><dd class="mt-1 tabular-nums text-slate-300">{{ $paperWallet['opening'] }} {{ $paperWallet['currency'] }}</dd></div>
                                        <div><dt class="text-slate-500">Realized P/L</dt><dd class="mt-1 tabular-nums text-slate-300">{{ $paperWallet['realized_pnl'] }} {{ $paperWallet['currency'] }}</dd></div>
                                    </dl>
                                </div>
                            @empty
                                <div class="px-4 py-8 text-center">
                                    <p class="text-sm font-medium text-slate-300">No {{ $source['label'] }} wallet reported</p>
                                    <p class="mt-1 text-xs text-slate-500">Balances are unavailable until an authoritative wallet record exists.</p>
                                </div>
                            @endforelse
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <dialog data-wallet-network-dialog class="w-[min(29rem,calc(100vw-2rem))] rounded-xl border border-slate-700 bg-slate-900 p-0 text-slate-100 shadow-2xl backdrop:bg-slate-950/80">
            <div class="border-b border-slate-800 px-5 py-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-white">Connect a wallet</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-400">Choose a supported network. The next step only lists browser providers actually detected on this device.</p>
                    </div>
                    <button type="button" data-wallet-network-cancel class="rounded-lg border border-slate-700 p-2 text-slate-400 hover:text-white" aria-label="Cancel wallet connection">
                        <x-app-icon name="close" :size="17" />
                    </button>
                </div>
            </div>
            <div class="grid gap-3 p-5 sm:grid-cols-2">
                <button type="button" data-wallet-network="solana" class="flex items-center gap-3 rounded-xl border border-slate-700 bg-slate-950/50 p-4 text-left hover:border-blue-400/60">
                    <x-wallet-chain-icon chain="solana" :size="36" />
                    <span><strong class="block text-sm text-white">Solana</strong><span class="mt-1 block text-[11px] text-slate-400">Phantom, Solflare, compatible</span></span>
                </button>
                <button type="button" data-wallet-network="ethereum" class="flex items-center gap-3 rounded-xl border border-slate-700 bg-slate-950/50 p-4 text-left hover:border-blue-400/60">
                    <x-wallet-chain-icon chain="ethereum" :size="36" />
                    <span><strong class="block text-sm text-white">Ethereum</strong><span class="mt-1 block text-[11px] text-slate-400">Phantom, MetaMask, EIP-1193</span></span>
                </button>
            </div>
            <p class="border-t border-slate-800 px-5 py-4 text-[11px] leading-5 text-amber-200">On mobile, connection currently requires a supported wallet-provider browser that injects its provider. QR and WalletConnect sessions are not implemented.</p>
        </dialog>
    </div>
</x-layouts.shell>
