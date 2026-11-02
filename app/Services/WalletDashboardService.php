<?php

namespace App\Services;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\PaperWallet;
use App\Models\TradingEnginePaperWalletProjection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WalletDashboardService
{
    /** @return array{connected: array<string, array<string, mixed>>, engine_paper: Collection<int, array<string, mixed>>, legacy_paper: Collection<int, array<string, mixed>>} */
    public function forUser(User $user): array
    {
        $connectedWallets = $user->connectedWallets()
            ->whereNotNull('verified_at')
            ->whereNull('disconnected_at')
            ->orderBy('chain')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (ConnectedWallet $wallet): string => $wallet->chain->value);

        return [
            'connected' => collect(Chain::cases())
                ->mapWithKeys(fn (Chain $chain): array => [
                    $chain->value => $this->presentConnectedWallet($chain, $connectedWallets->get($chain->value)),
                ])
                ->all(),
            'engine_paper' => $this->enginePaperWallets($user),
            'legacy_paper' => $this->legacyPaperWallets($user),
        ];
    }

    /** @return array<string, mixed> */
    private function presentConnectedWallet(Chain $chain, ?ConnectedWallet $wallet): array
    {
        $address = $wallet?->address;

        return [
            'connected' => $wallet !== null,
            'chain' => $chain->value,
            'chain_label' => $chain->label(),
            'network' => $chain === Chain::Ethereum ? 'Ethereum Mainnet' : 'Solana Mainnet',
            'address' => $address,
            'short_address' => $address === null ? null : $this->shortAddress($chain, $address),
            'provider' => $this->providerLabel($wallet?->provider),
            'verified_at' => $wallet?->verified_at,
            'last_connected_at' => $wallet?->last_connected_at,
            'explorer_url' => $address === null ? null : $this->explorerUrl($chain, $address),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function enginePaperWallets(User $user): Collection
    {
        return TradingEnginePaperWalletProjection::query()
            ->where('user_id', $user->id)
            ->orderBy('network_id')
            ->orderBy('id')
            ->get()
            ->map(fn (TradingEnginePaperWalletProjection $wallet): array => [
                'network' => $this->networkLabel($wallet->network_id),
                'currency' => $wallet->currency,
                'opening' => $this->formatNative($wallet->opening_balance_native),
                'available' => $this->formatNative($wallet->available_balance_native),
                'invested' => $this->formatNative($wallet->invested_balance_native),
                'realized_pnl' => $this->formatNative($wallet->realized_pnl_native, true),
                'observed_at' => $wallet->projected_at,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function legacyPaperWallets(User $user): Collection
    {
        return PaperWallet::query()
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id);

                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            })
            ->orderBy('chain')
            ->orderBy('id')
            ->get()
            ->map(fn (PaperWallet $wallet): array => [
                'network' => $wallet->chain->label(),
                'currency' => $wallet->currencyCode(),
                'opening' => $this->formatNative($wallet->starting_balance_sol),
                'available' => $this->formatNative($wallet->available_balance_sol),
                'invested' => $this->formatNative($wallet->invested_balance_sol),
                'realized_pnl' => $this->formatNative($wallet->realized_pnl_sol, true),
                'observed_at' => $wallet->updated_at,
            ]);
    }

    private function providerLabel(?string $provider): string
    {
        return match (strtolower((string) $provider)) {
            'phantom' => 'Phantom',
            'solflare' => 'Solflare',
            'metamask' => 'MetaMask',
            'compatible' => 'Compatible browser wallet',
            default => 'Provider not reported',
        };
    }

    private function shortAddress(Chain $chain, string $address): string
    {
        $prefixLength = $chain === Chain::Ethereum ? 6 : 4;

        return mb_substr($address, 0, $prefixLength).'…'.mb_substr($address, -4);
    }

    private function explorerUrl(Chain $chain, string $address): string
    {
        return match ($chain) {
            Chain::Solana => 'https://solscan.io/account/'.rawurlencode($address),
            Chain::Ethereum => 'https://etherscan.io/address/'.rawurlencode($address),
        };
    }

    private function networkLabel(string $networkId): string
    {
        return match (true) {
            str_starts_with(strtolower($networkId), 'solana') => 'Solana',
            str_starts_with(strtolower($networkId), 'ethereum') => 'Ethereum',
            default => $networkId,
        };
    }

    private function formatNative(mixed $value, bool $signed = false): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $amount = (float) $value;
        $formatted = rtrim(rtrim(number_format(abs($amount), 8, '.', ''), '0'), '.');
        $prefix = $amount < 0 ? '−' : ($signed && $amount > 0 ? '+' : '');

        return $prefix.($formatted === '' ? '0' : $formatted);
    }
}
