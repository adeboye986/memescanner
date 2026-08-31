<?php

namespace App\Console\Commands;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcilePaperWallet extends Command
{
    protected $signature = 'tokens:paper-reconcile
        {--fix : Rewrite the wallet balances from the paper-position ledger}';

    protected $description = 'Check and optionally reconcile the virtual SOL wallet against paper positions';

    public function handle(): int
    {
        $wallet = PaperWallet::query()
            ->where('name', 'default')
            ->first();

        if (!$wallet) {
            $this->error('Default paper wallet not found.');

            return self::FAILURE;
        }

        $positions = PaperPosition::query()
            ->where('initial_investment_sol', '>', 0)
            ->get();

        $startingBalance = (float) $wallet->starting_balance_sol;

        $totalFundedBuys = (float) $positions->sum(
            fn (PaperPosition $position) =>
                (float) $position->initial_investment_sol
        );

        $totalSolReturned = (float) $positions->sum(
            fn (PaperPosition $position) =>
                (float) $position->realized_sol
        );

        $expectedInvested = (float) $positions
            ->where('status', 'open')
            ->sum(
                fn (PaperPosition $position) =>
                    (float) $position->remaining_investment_sol
            );

        $expectedRealizedPnl = (float) $positions->sum(
            fn (PaperPosition $position) =>
                (float) $position->trade_pnl_sol
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

        $this->info('PAPER WALLET RECONCILIATION');
        $this->newLine();

        $this->table(
            ['Metric', 'Actual', 'Expected', 'Difference'],
            [
                [
                    'Available SOL',
                    number_format($actualAvailable, 8),
                    number_format($expectedAvailable, 8),
                    sprintf('%+.8f', $availableDiff),
                ],
                [
                    'Invested SOL',
                    number_format($actualInvested, 8),
                    number_format($expectedInvested, 8),
                    sprintf('%+.8f', $investedDiff),
                ],
                [
                    'Realized P/L SOL',
                    number_format($actualRealizedPnl, 8),
                    number_format($expectedRealizedPnl, 8),
                    sprintf('%+.8f', $realizedPnlDiff),
                ],
            ]
        );

        $this->newLine();
        $this->line(
            sprintf(
                'Funded buys: %.8f SOL | Returned from exits: %.8f SOL | Funded positions: %d',
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

        if (!$this->option('fix')) {
            $this->line(
                'Run php artisan tokens:paper-reconcile --fix to repair the wallet.'
            );

            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $wallet,
            $expectedAvailable,
            $expectedInvested,
            $expectedRealizedPnl
        ): void {
            $lockedWallet = PaperWallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

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
