<?php

namespace App\Console\Commands;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Services\DexScreenerService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClosePaperPosition extends Command
{
    protected $signature = 'tokens:paper-close
        {position : Open position ID, token symbol, or token address}
        {--force : Close without interactive confirmation}';

    protected $description =
        'Manually close the remaining amount of an open paper trade at the current simulated market price';

    public function handle(
        DexScreenerService $dexscreener,
        TelegramService $telegram
    ): int {
        $selector = trim((string) $this->argument('position'));

        $matches = PaperPosition::query()
            ->where('status', 'open')
            ->where('initial_investment_sol', '>', 0)
            ->where(function ($query) use ($selector) {
                if (ctype_digit($selector)) {
                    $query->orWhereKey((int) $selector);
                }

                $query
                    ->orWhere('address', $selector)
                    ->orWhere('symbol', $selector);
            })
            ->get();

        if ($matches->isEmpty()) {
            $this->error(
                "No funded open paper position found for: {$selector}"
            );

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error(
                "More than one open position matched '{$selector}'."
            );

            $this->table(
                ['ID', 'Symbol', 'Address', 'Entry MC'],
                $matches->map(fn ($position) => [
                    $position->id,
                    $position->symbol,
                    $position->address,
                    '$' . number_format(
                        (float) $position->entry_market_cap,
                        2
                    ),
                ])->all()
            );

            $this->line(
                'Run the command again using the position ID or full address.'
            );

            return self::FAILURE;
        }

        $position = $matches->first();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Position ID', $position->id],
                ['Token', $position->symbol],
                [
                    'Initial Investment',
                    number_format(
                        (float) $position->initial_investment_sol,
                        4
                    ) . ' SOL',
                ],
                [
                    'Remaining',
                    number_format(
                        (
                            $position->remaining_fraction !== null
                                ? (float) $position->remaining_fraction
                                : 1.0
                        ) * 100,
                        0
                    ) . '%',
                ],
                [
                    'Entry MC',
                    '$' . number_format(
                        (float) $position->entry_market_cap,
                        2
                    ),
                ],
                ['Address', $position->address],
            ]
        );

        if (
            !$this->option('force')
            && !$this->confirm(
                "Close the remaining {$position->symbol} paper position now?",
                false
            )
        ) {
            $this->warn('Manual close cancelled.');

            return self::SUCCESS;
        }

        try {
            $dex = $dexscreener->analyzeToken($position->address);
        } catch (\Throwable $e) {
            $this->error(
                'Could not fetch current Dex price: ' . $e->getMessage()
            );

            return self::FAILURE;
        }

        if (!($dex['available'] ?? false)) {
            $this->error(
                'Current Dex pair is unavailable. Position was NOT closed.'
            );

            return self::FAILURE;
        }

        if (!($dex['requested_token_is_base'] ?? false)) {
            $this->error(
                'Dex pair does not identify the requested token as the base token. Position was NOT closed.'
            );

            return self::FAILURE;
        }

        $marketCap = (float) ($dex['market_cap'] ?? 0);
        $price = $dex['price_usd'] ?? $dex['price'] ?? null;

        $entryMc = (float) $position->entry_market_cap;

        if ($marketCap <= 0 || $entryMc <= 0) {
            $this->error(
                'Invalid market-cap data. Position was NOT closed.'
            );

            return self::FAILURE;
        }

        $multiple = $marketCap / $entryMc;

        $result = DB::transaction(function () use (
            $position,
            $marketCap,
            $price,
            $multiple
        ) {
            $lockedPosition = PaperPosition::query()
                ->whereKey($position->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPosition->status !== 'open') {
                throw new \RuntimeException(
                    'Position is no longer open.'
                );
            }

            $remainingFraction =
                $lockedPosition->remaining_fraction !== null
                    ? (float) $lockedPosition->remaining_fraction
                    : 1.0;

            if ($remainingFraction <= 0.000001) {
                throw new \RuntimeException(
                    'Position has no remaining amount to close.'
                );
            }

            $initialInvestment =
                (float) $lockedPosition->initial_investment_sol;

            $costBasis =
                (float) ($lockedPosition->remaining_investment_sol ?? 0);

            if ($costBasis <= 0) {
                $costBasis =
                    $initialInvestment * $remainingFraction;
            }

            $solReturned = $costBasis * $multiple;
            $realizedPnl = $solReturned - $costBasis;

            $existingRealizedValue =
                (float) (
                    $lockedPosition->realized_value_multiple ?? 0
                );

            $realizedValue =
                $existingRealizedValue
                + ($remainingFraction * $multiple);

            $strategyReturnPercent =
                ($realizedValue - 1) * 100;

            $event = [
                'type' => 'manual_close',
                'label' => 'MANUAL CLOSE',
                'sold_fraction' => $remainingFraction,
                'fill_multiple' => $multiple,
                'observed_multiple' => $multiple,
                'observed_market_cap' => $marketCap,
                'cost_basis_sol' => round($costBasis, 8),
                'sol_returned' => round($solReturned, 8),
                'realized_pnl_sol' => round($realizedPnl, 8),
                'wallet_applied' => true,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents = $lockedPosition->exit_events ?? [];
            $exitEvents[] = $event;

            $wallet = PaperWallet::query()
                ->where('name', 'default')
                ->lockForUpdate()
                ->firstOrFail();

            $wallet->available_balance_sol =
                (float) $wallet->available_balance_sol
                + $solReturned;

            $wallet->invested_balance_sol =
                max(
                    0.0,
                    (float) $wallet->invested_balance_sol
                    - $costBasis
                );

            $wallet->realized_pnl_sol =
                (float) $wallet->realized_pnl_sol
                + $realizedPnl;

            $wallet->save();

            $positionRealizedSol =
                (float) ($lockedPosition->realized_sol ?? 0)
                + $solReturned;

            $positionTradePnl =
                (float) ($lockedPosition->trade_pnl_sol ?? 0)
                + $realizedPnl;

            $peakMc = max(
                (float) ($lockedPosition->peak_market_cap ?? 0),
                (float) $lockedPosition->entry_market_cap,
                $marketCap
            );

            $lockedPosition->update([
                'last_market_cap' => $marketCap,
                'last_price' => $price,
                'last_checked_at' => now(),
                'peak_market_cap' => $peakMc,
                'peak_multiple' =>
                    $peakMc / (float) $lockedPosition->entry_market_cap,

                'remaining_fraction' => 0,
                'remaining_investment_sol' => 0,

                'realized_value_multiple' => $realizedValue,
                'strategy_value_multiple' => $realizedValue,
                'strategy_return_percent' => $strategyReturnPercent,

                'realized_sol' => $positionRealizedSol,
                'trade_pnl_sol' => $positionTradePnl,

                'exit_events' => $exitEvents,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            return [
                'position' => $lockedPosition->fresh(),
                'wallet' => $wallet->fresh(),
                'event' => $event,
                'strategy_return_percent' =>
                    $strategyReturnPercent,
            ];
        });

        $closed = $result['position'];
        $wallet = $result['wallet'];
        $event = $result['event'];

        $this->newLine();
        $this->info(
            "PAPER TRADE CLOSED: {$closed->symbol}"
        );

        $this->table(
            ['Metric', 'Value'],
            [
                [
                    'Closed At MC',
                    '$' . number_format($marketCap, 2),
                ],
                [
                    'Fill Multiple',
                    number_format($multiple, 2) . 'x',
                ],
                [
                    'Sold',
                    number_format(
                        (float) $event['sold_fraction'] * 100,
                        0
                    ) . '%',
                ],
                [
                    'SOL Returned',
                    number_format(
                        (float) $event['sol_returned'],
                        4
                    ) . ' SOL',
                ],
                [
                    'P/L This Exit',
                    sprintf(
                        '%+.4f SOL',
                        (float) $event['realized_pnl_sol']
                    ),
                ],
                [
                    'Total Trade P/L',
                    sprintf(
                        '%+.4f SOL',
                        (float) $closed->trade_pnl_sol
                    ),
                ],
                [
                    'Strategy Return',
                    sprintf(
                        '%+.2f%%',
                        (float) $closed->strategy_return_percent
                    ),
                ],
                [
                    'Wallet Available',
                    number_format(
                        (float) $wallet->available_balance_sol,
                        4
                    ) . ' SOL',
                ],
            ]
        );

        try {
            $telegram->send(
                "🛑🛑 <b>PAPER TRADE MANUALLY CLOSED</b> 🛑🛑\n\n" .
                "💰 <b>{$closed->symbol}</b>\n\n" .
                "👤 <b>Manual close requested</b>\n" .
                "📊 <b>Close MC:</b> $" .
                number_format($marketCap, 2) . "\n" .
                "✖️ <b>Fill:</b> " .
                number_format($multiple, 2) . "x\n" .
                "📤 <b>Sold:</b> " .
                number_format(
                    (float) $event['sold_fraction'] * 100,
                    0
                ) . "% of original position\n" .
                "🪙 <b>SOL Returned:</b> " .
                number_format(
                    (float) $event['sol_returned'],
                    4
                ) . " SOL\n" .
                "💹 <b>P/L This Exit:</b> " .
                sprintf(
                    '%+.4f SOL',
                    (float) $event['realized_pnl_sol']
                ) . "\n" .
                "💰 <b>Total Trade P/L:</b> " .
                sprintf(
                    '%+.4f SOL',
                    (float) $closed->trade_pnl_sol
                ) . "\n" .
                "📈 <b>Final Strategy Return:</b> " .
                sprintf(
                    '%+.2f%%',
                    (float) $closed->strategy_return_percent
                ) . "\n\n" .
                "💳 <b>WALLET AFTER CLOSE</b>\n" .
                "Available: <b>" .
                number_format(
                    (float) $wallet->available_balance_sol,
                    4
                ) . " SOL</b>\n" .
                "Invested: <b>" .
                number_format(
                    (float) $wallet->invested_balance_sol,
                    4
                ) . " SOL</b>\n" .
                "Realized P/L: <b>" .
                sprintf(
                    '%+.4f SOL',
                    (float) $wallet->realized_pnl_sol
                ) . "</b>\n\n" .
                "❌ <b>POSITION CLOSED</b>\n\n" .
                "📍 <code>{$closed->address}</code>\n\n" .
                "⚠️ <b>PAPER TRADE — NO REAL SOL USED</b>"
            );
        } catch (\Throwable $e) {
            $this->warn(
                'Trade closed, but Telegram notification failed: ' .
                $e->getMessage()
            );
        }

        return self::SUCCESS;
    }
}
