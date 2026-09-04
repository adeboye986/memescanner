<?php

namespace App\Console\Commands;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\User;
use App\Services\PaperWalletService;
use Illuminate\Console\Command;

class PaperTradingReport extends Command
{
    protected $signature = 'tokens:paper-report
        {--chain=solana : Paper wallet chain (solana or ethereum)}
        {--user= : Report one user-owned wallet}';

    protected $description = 'Show one chain virtual wallet and paper trading performance';

    public function handle(PaperWalletService $wallets): int
    {
        try {
            $chain = Chain::fromInput($this->option('chain'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $user = $this->option('user') ? User::query()->findOrFail($this->option('user')) : null;

        $wallet = $user ? $wallets->forUser($user, $chain) : $wallets->query($chain)->whereNull('user_id')->first();

        if (! $wallet) {
            $this->error("Default {$chain->label()} paper wallet not found.");

            return self::FAILURE;
        }

        $currency = $wallet->currencyCode();
        /*
         * Only positions funded by the virtual wallet belong in the
         * wallet-performance report. Legacy positions with a zero
         * investment are intentionally excluded.
         */
        $positions = PaperPosition::query()
            ->where('user_id', $user?->id)
            ->where('chain', $chain->value)
            ->where('initial_investment_sol', '>', 0)
            ->orderByDesc('id')
            ->get();

        $openPositions = $positions->where('status', 'open');
        $closedPositions = $positions->where('status', 'closed');

        $startingBalance = (float) $wallet->starting_balance_sol;
        $availableBalance = (float) $wallet->available_balance_sol;
        $investedBalance = (float) $wallet->invested_balance_sol;
        $realizedPnl = (float) $wallet->realized_pnl_sol;

        /*
         * Mark each open position to its most recently tracked market cap.
         * If no current market cap is available yet, value it at remaining
         * cost basis rather than pretending it has made or lost money.
         */
        $openEquity = 0.0;

        foreach ($openPositions as $position) {
            $initialInvestment =
                (float) $position->initial_investment_sol;

            $remainingFraction =
                max(0.0, (float) $position->remaining_fraction);

            $remainingCostBasis =
                (float) $position->remaining_investment_sol;

            if ($remainingCostBasis <= 0 && $remainingFraction > 0) {
                $remainingCostBasis =
                    $initialInvestment * $remainingFraction;
            }

            $entryMc = (float) $position->entry_market_cap;
            $lastMc = (float) $position->last_market_cap;

            $currentMultiple =
                ($entryMc > 0 && $lastMc > 0)
                    ? $lastMc / $entryMc
                    : 1.0;

            $openEquity +=
                $remainingCostBasis * $currentMultiple;
        }

        $totalEquity = $availableBalance + $openEquity;
        $netPnl = $totalEquity - $startingBalance;

        $totalReturnPercent =
            $startingBalance > 0
                ? ($netPnl / $startingBalance) * 100
                : 0.0;

        $closedCount = $closedPositions->count();

        $wins = $closedPositions->filter(
            fn (PaperPosition $position) => (float) $position->trade_pnl_sol > 0
        )->count();

        $losses = $closedPositions->filter(
            fn (PaperPosition $position) => (float) $position->trade_pnl_sol < 0
        )->count();

        $breakeven = $closedCount - $wins - $losses;

        $winRate =
            $closedCount > 0
                ? ($wins / $closedCount) * 100
                : 0.0;

        $averageClosedPnl =
            $closedCount > 0
                ? (float) $closedPositions->avg('trade_pnl_sol')
                : 0.0;

        $stopLosses =
            $positions->where('stop_loss_hit', true)->count();

        $tp50Hits =
            $positions->where('tp_50_hit', true)->count();

        $tp2xHits =
            $positions->where('tp_2x_hit', true)->count();

        $trailingStops =
            $positions->where('trailing_stop_hit', true)->count();

        $bestTrade = $closedPositions
            ->sortByDesc(
                fn (PaperPosition $position) => (float) $position->trade_pnl_sol
            )
            ->first();

        $worstTrade = $closedPositions
            ->sortBy(
                fn (PaperPosition $position) => (float) $position->trade_pnl_sol
            )
            ->first();

        $this->info(strtoupper($chain->label()).' PAPER TRADING REPORT');
        $this->newLine();

        $this->info('WALLET');

        $this->table(
            ['Metric', 'Value'],
            [
                [
                    'Starting Balance',
                    number_format($startingBalance, 4)." {$currency}",
                ],
                [
                    'Available',
                    number_format($availableBalance, 4)." {$currency}",
                ],
                [
                    'Invested Cost Basis',
                    number_format($investedBalance, 4)." {$currency}",
                ],
                [
                    'Open Position Equity',
                    number_format($openEquity, 4)." {$currency}",
                ],
                [
                    'Total Equity',
                    number_format($totalEquity, 4)." {$currency}",
                ],
                [
                    'Realized P/L',
                    sprintf('%+.4f %s', $realizedPnl, $currency),
                ],
                [
                    'Net P/L',
                    sprintf('%+.4f %s', $netPnl, $currency),
                ],
                [
                    'Total Return',
                    sprintf('%+.2f%%', $totalReturnPercent),
                ],
            ]
        );

        $this->newLine();
        $this->info('PERFORMANCE');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Funded Trades', $positions->count()],
                ['Open Trades', $openPositions->count()],
                ['Closed Trades', $closedCount],
                ['Wins', $wins],
                ['Losses', $losses],
                ['Breakeven', $breakeven],
                ['Win Rate', number_format($winRate, 2).'%'],
                [
                    'Avg Closed P/L',
                    sprintf('%+.4f %s', $averageClosedPnl, $currency),
                ],
            ]
        );

        $this->newLine();
        $this->info('EXIT RESULTS');

        $this->table(
            ['Exit', 'Count'],
            [
                ['Stop Losses', $stopLosses],
                ['+50% TP Hits', $tp50Hits],
                ['2x TP Hits', $tp2xHits],
                ['Trailing Stops', $trailingStops],
            ]
        );

        if ($openPositions->isNotEmpty()) {
            $this->newLine();
            $this->info('OPEN POSITIONS');

            $rows = [];

            foreach ($openPositions as $position) {
                $entryMc = (float) $position->entry_market_cap;
                $lastMc = (float) $position->last_market_cap;

                $multiple =
                    ($entryMc > 0 && $lastMc > 0)
                        ? $lastMc / $entryMc
                        : 1.0;

                $remainingCost =
                    (float) $position->remaining_investment_sol;

                $currentValue =
                    $remainingCost * $multiple;

                $unrealizedPnl =
                    $currentValue - $remainingCost;

                $rows[] = [
                    $position->symbol ?: $position->address,
                    number_format(
                        (float) $position->initial_investment_sol,
                        4
                    ),
                    number_format($remainingCost, 4),
                    number_format($multiple, 2).'x',
                    number_format($currentValue, 4),
                    sprintf('%+.4f', $unrealizedPnl),
                ];
            }

            $this->table(
                [
                    'Token',
                    "Initial {$currency}",
                    'Remaining Cost',
                    'Multiple',
                    "Current {$currency}",
                    'Unrealized P/L',
                ],
                $rows
            );
        }

        if ($closedCount > 0) {
            $this->newLine();
            $this->info('BEST / WORST CLOSED TRADE');

            $this->table(
                ['Result', 'Token', 'P/L', 'Strategy Return'],
                [
                    [
                        'Best',
                        $bestTrade?->symbol ?: $bestTrade?->address,
                        sprintf(
                            '%+.4f %s',
                            (float) $bestTrade?->trade_pnl_sol,
                            $currency,
                        ),
                        sprintf(
                            '%+.2f%%',
                            (float) $bestTrade?->strategy_return_percent
                        ),
                    ],
                    [
                        'Worst',
                        $worstTrade?->symbol ?: $worstTrade?->address,
                        sprintf(
                            '%+.4f %s',
                            (float) $worstTrade?->trade_pnl_sol,
                            $currency,
                        ),
                        sprintf(
                            '%+.2f%%',
                            (float) $worstTrade?->strategy_return_percent
                        ),
                    ],
                ]
            );
        }

        $this->newLine();

        if ($totalEquity >= $startingBalance) {
            $this->info(
                sprintf(
                    "Virtual wallet is %+.4f {$currency} (%+.2f%%) versus its starting balance.",
                    $netPnl,
                    $totalReturnPercent
                )
            );
        } else {
            $this->warn(
                sprintf(
                    "Virtual wallet is %+.4f {$currency} (%+.2f%%) versus its starting balance.",
                    $netPnl,
                    $totalReturnPercent
                )
            );
        }

        return self::SUCCESS;
    }
}
