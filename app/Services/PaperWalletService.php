<?php

namespace App\Services;

use App\Chain;
use App\Models\PaperWallet;
use Illuminate\Database\Eloquent\Builder;

class PaperWalletService
{
    public function default(Chain|string $chain): PaperWallet
    {
        $resolved = $chain instanceof Chain ? $chain : Chain::fromInput($chain);

        return PaperWallet::query()->firstOrCreate(
            ['name' => 'default', 'chain' => $resolved->value],
            [
                'currency' => $this->currency($resolved),
                'starting_balance_sol' => $this->startingBalance($resolved),
                'available_balance_sol' => $this->startingBalance($resolved),
                'invested_balance_sol' => 0,
                'realized_pnl_sol' => 0,
            ],
        );
    }

    public function lockedDefault(Chain|string $chain): PaperWallet
    {
        $wallet = $this->default($chain);

        return $this->query($chain)->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @return Builder<PaperWallet> */
    public function query(Chain|string $chain): Builder
    {
        $resolved = $chain instanceof Chain ? $chain : Chain::fromInput($chain);

        return PaperWallet::query()->where('name', 'default')->where('chain', $resolved->value);
    }

    public function currency(Chain|string $chain): string
    {
        $resolved = $chain instanceof Chain ? $chain : Chain::fromInput($chain);

        return match ($resolved) {
            Chain::Solana => 'SOL',
            Chain::Ethereum => 'ETH',
        };
    }

    public function tradeSize(Chain|string $chain): float
    {
        $resolved = $chain instanceof Chain ? $chain : Chain::fromInput($chain);

        return match ($resolved) {
            Chain::Solana => (float) config('services.trading.paper_trade_size_sol', 0.10),
            Chain::Ethereum => (float) config('services.trading.paper_trade_size_eth', 0.10),
        };
    }

    private function startingBalance(Chain $chain): float
    {
        return match ($chain) {
            Chain::Solana => (float) config('services.trading.paper_starting_balance_sol', 5),
            Chain::Ethereum => (float) config('services.trading.paper_starting_balance_eth', 5),
        };
    }
}
