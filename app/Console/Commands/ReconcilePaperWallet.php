<?php

namespace App\Console\Commands;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Services\PaperWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcilePaperWallet extends Command
{
    protected $signature = 'tokens:paper-reconcile
        {--chain=solana : Paper wallet chain (solana or ethereum)}
        {--fix : Rewrite the wallet balances from the paper-position ledger}';

    protected $description = 'Check and optionally reconcile one chain paper wallet against its positions';

    public function handle(PaperWalletService $wallets): int
    {
        try {
            $chain = Chain::fromInput($this->option('chain'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $wallet = $wallets->query($chain)->first();

        if (! $wallet) {
            $this->error("Default {$chain->label()} paper wallet not found.");

            return self::FAILURE;
        }

        $currency = $wallet->currencyCode();
        $positions = PaperPosition::query()
            ->where('chain', $chain->value)
            ->where('initial_investment_sol', '>', 0)
            ->get();

        $startingBalance = (float) $wallet->starting_balance_sol;

        $totalFundedBuys = (float) $positions->sum(
            fn (PaperPosition $position) => (float) $position->initial_investment_sol
        );

        $totalSolReturned = (float) $positions->sum(
            fn (PaperPosition $position) => (float) $position->realized_sol
        );

        $expectedInvested = (float) $positions
            ->where('status', 'open')
            ->sum(
                fn (PaperPosition $position) => (float) $position->remaining_investment_sol
            );

        $expectedRealizedPnl = (float) $positions->sum(
            fn (PaperPosition $position) => (float) $position->trade_pnl_sol
        );

        $expectedAvailable =
            $startingBalance
            - $totalFundedBuys
            + $totalSolReturned;

        $actualAvailable = (float) $wallet->available_balance_sol;
        $actualInvested = (float) $wallet->invested_balance_sol;
        $actualRealizedPnl = (float) $wallet->realized_pnl_sol;

        $availableDiff = $actualAvailable - $expectedAvailable;
        $investedDiff = $actualInvested - $expectedInvested;
        $realizedPnlDiff = $actualRealizedPnl - $expectedRealizedPnl;

        $this->info(strtoupper($chain->label()).' PAPER WALLET RECONCILIATION');
        $this->newLine();

        $this->table(
            ['Metric', 'Actual', 'Expected', 'Difference'],
            [
                [
                    "Available {$currency}",
                    number_format($actualAvailable, 8),
                    number_format($expectedAvailable, 8),
                    sprintf('%+.8f', $availableDiff),
                ],
                [
                    "Invested {$currency}",
                    number_format($actualInvested, 8),
                    number_format($expectedInvested, 8),
                    sprintf('%+.8f', $investedDiff),
                ],
                [
                    "Realized P/L {$currency}",
                    number_format($actualRealizedPnl, 8),
                    number_format($expectedRealizedPnl, 8),
                    sprintf('%+.8f', $realizedPnlDiff),
                ],
            ]
        );

        $this->newLine();
        $this->line(
            sprintf(
                "Funded buys: %.8f {$currency} | Returned from exits: %.8f {$currency} | Funded positions: %d",
                $totalFundedBuys,
                $totalSolReturned,
                $positions->count()
            )
        );

        $tolerance = 0.00000001;

        $inSync =
            abs($availableDiff) <= $tolerance
            && abs($investedDiff) <= $tolerance
            && abs($realizedPnlDiff) <= $tolerance;

        if ($inSync) {
            $this->info('Wallet is in sync with the paper-position ledger.');

            return self::SUCCESS;
        }

        $this->warn('Wallet does NOT match the paper-position ledger.');

        if (! $this->option('fix')) {
            $this->line(
                "Run php artisan tokens:paper-reconcile --chain={$chain->value} --fix to repair the wallet."
            );

            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $wallet,
            $chain
        ): void {
            $lockedPositions = PaperPosition::query()
                ->where('chain', $chain->value)
                ->where('initial_investment_sol', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedWallet = PaperWallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->firstOrFail();
            $expectedAvailable = (float) $lockedWallet->starting_balance_sol
                - (float) $lockedPositions->sum(
                    fn (PaperPosition $position) => (float) $position->initial_investment_sol
                )
                + (float) $lockedPositions->sum(
                    fn (PaperPosition $position) => (float) $position->realized_sol
                );
            $expectedInvested = (float) $lockedPositions
                ->where('status', 'open')
                ->sum(
                    fn (PaperPosition $position) => (float) $position->remaining_investment_sol
                );
            $expectedRealizedPnl = (float) $lockedPositions->sum(
                fn (PaperPosition $position) => (float) $position->trade_pnl_sol
            );

            $lockedWallet->update([
                'available_balance_sol' => round($expectedAvailable, 8),
                'invested_balance_sol' => round($expectedInvested, 8),
                'realized_pnl_sol' => round($expectedRealizedPnl, 8),
            ]);
        });

        $this->info('Wallet reconciled successfully.');

        return self::SUCCESS;
    }
}
