<?php

namespace App\Console\Commands;

use App\Models\PaperPosition;
use App\Models\PaperPositionSnapshot;
use App\Models\PaperWallet;
use App\Services\DexScreenerService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TrackPaperPositions extends Command
{
    protected $signature = 'tokens:paper-track
        {--limit=50 : Maximum open paper positions to check per run}';

    protected $description =
        'Track paper positions, milestones, peaks, drawdowns and simulated exits';

    public function handle(
        DexScreenerService $dexscreener,
        TelegramService $telegram
    ): int {
        $limit = max(1, min((int) $this->option('limit'), 200));

        $positions = PaperPosition::query()
            ->where('status', 'open')
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();

        if ($positions->isEmpty()) {
            $this->info('No open paper positions.');
            return self::SUCCESS;
        }

        foreach ($positions as $position) {
            $this->trackOne(
                $position,
                $dexscreener,
                $telegram
            );
        }

        return self::SUCCESS;
    }

    private function trackOne(
        PaperPosition $position,
        DexScreenerService $dexscreener,
        TelegramService $telegram
    ): void {
        try {
            $dex = $dexscreener->analyzeToken($position->address);
        } catch (\Throwable $e) {
            $this->warn(
                "PAPER TRACK UNAVAILABLE: {$position->symbol} | " .
                $e->getMessage()
            );
            return;
        }

        if (!($dex['available'] ?? false)) {
            $this->warn(
                "PAPER TRACK UNAVAILABLE: {$position->symbol} | no Dex pair"
            );
            return;
        }

        $marketCap = (float) ($dex['market_cap'] ?? 0);
        $price = $dex['price_usd']
            ?? $dex['price']
            ?? null;
        $liquidity = $dex['liquidity_usd'] ?? null;

        if ($marketCap <= 0 || $position->entry_market_cap <= 0) {
            $this->warn(
                "PAPER TRACK SKIP: {$position->symbol} | invalid market cap"
            );
            return;
        }

        $entryMc = (float) $position->entry_market_cap;
        $multiple = $marketCap / $entryMc;
        $returnPercent = ($multiple - 1) * 100;

        $oldPeak = max(
            $entryMc,
            (float) ($position->peak_market_cap ?? 0)
        );

        $peakMc = max($oldPeak, $marketCap);
        $peakMultiple = $peakMc / $entryMc;

        $drawdownFromPeak = $peakMc > 0
            ? (($marketCap - $peakMc) / $peakMc) * 100
            : 0;

        $maxDrawdown = min(
            (float) ($position->max_drawdown_percent ?? 0),
            $drawdownFromPeak
        );

        $elapsedSeconds =
            max(0, $position->entry_at->diffInSeconds(now()));

        $milestones = $position->milestones ?? [];
        $newMilestones = [];

        $targets = [
            'plus_25' => 1.25,
            'plus_50' => 1.50,
            'x2' => 2.00,
            'x3' => 3.00,
            'x5' => 5.00,
        ];

        foreach ($targets as $key => $targetMultiple) {
            if (
                $multiple >= $targetMultiple
                && empty($milestones[$key])
            ) {
                $milestones[$key] = [
                    'hit_at' => now()->toIso8601String(),
                    'market_cap' => $marketCap,
                    'multiple' => round($multiple, 4),
                ];

                $newMilestones[$key] = $targetMultiple;
            }
        }

        /*
         * PAPER EXIT STRATEGY
         *
         * Start: 100% virtual position
         * - Stop loss: sell 100% at 0.70x (-30%) if hit before exits.
         * - +50% / 1.50x: sell 25%.
         * - 2.00x: sell another 25%.
         * - Remaining 50%: after 2x is reached, trail 25% below peak.
         *
         * Threshold fills are simulated at the threshold itself rather
         * than the later polling price. This makes comparisons consistent.
         */
        $remainingFraction =
            $position->remaining_fraction !== null
                ? (float) $position->remaining_fraction
                : 1.0;

        $realizedValue =
            $position->realized_value_multiple !== null
                ? (float) $position->realized_value_multiple
                : 0.0;

        $exitEvents = $position->exit_events ?? [];

        $tp50Hit = (bool) ($position->tp_50_hit ?? false);
        $tp2xHit = (bool) ($position->tp_2x_hit ?? false);
        $stopLossHit = (bool) ($position->stop_loss_hit ?? false);
        $trailingStopHit =
            (bool) ($position->trailing_stop_hit ?? false);

        $strategyEvents = [];

        /*
         * Stop loss only applies while the position is still fully open.
         * Once partial profit-taking has happened, the final remainder
         * is managed by the trailing stop after 2x.
         */
        if (
            !$tp50Hit
            && !$tp2xHit
            && !$stopLossHit
            && $multiple <= 0.70
            && $remainingFraction > 0
        ) {
            $soldFraction = $remainingFraction;
            $fillMultiple = 0.70;

            $realizedValue +=
                $soldFraction * $fillMultiple;

            $remainingFraction = 0.0;
            $stopLossHit = true;

            $event = [
                'type' => 'stop_loss',
                'label' => 'STOP LOSS -30%',
                'sold_fraction' => $soldFraction,
                'fill_multiple' => $fillMultiple,
                'observed_multiple' => $multiple,
                'observed_market_cap' => $marketCap,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents[] = $event;
            $strategyEvents[] = $event;
        }

        if (
            !$stopLossHit
            && !$tp50Hit
            && $multiple >= 1.50
            && $remainingFraction > 0
        ) {
            $soldFraction = min(0.25, $remainingFraction);
            $fillMultiple = 1.50;

            $realizedValue +=
                $soldFraction * $fillMultiple;

            $remainingFraction -= $soldFraction;
            $tp50Hit = true;

            $event = [
                'type' => 'take_profit_50',
                'label' => 'TAKE PROFIT +50%',
                'sold_fraction' => $soldFraction,
                'fill_multiple' => $fillMultiple,
                'observed_multiple' => $multiple,
                'observed_market_cap' => $marketCap,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents[] = $event;
            $strategyEvents[] = $event;
        }

        if (
            !$stopLossHit
            && !$tp2xHit
            && $multiple >= 2.00
            && $remainingFraction > 0
        ) {
            $soldFraction = min(0.25, $remainingFraction);
            $fillMultiple = 2.00;

            $realizedValue +=
                $soldFraction * $fillMultiple;

            $remainingFraction -= $soldFraction;
            $tp2xHit = true;

            $event = [
                'type' => 'take_profit_2x',
                'label' => 'TAKE PROFIT 2X',
                'sold_fraction' => $soldFraction,
                'fill_multiple' => $fillMultiple,
                'observed_multiple' => $multiple,
                'observed_market_cap' => $marketCap,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents[] = $event;
            $strategyEvents[] = $event;
        }

        /*
         * Trail the remainder 25% below the highest observed market cap
         * once the 2x take-profit has activated.
         */
        if (
            $tp2xHit
            && !$trailingStopHit
            && $remainingFraction > 0
            && $peakMultiple >= 2.00
        ) {
            $trailingTriggerMultiple =
                $peakMultiple * 0.75;

            if ($multiple <= $trailingTriggerMultiple) {
                $soldFraction = $remainingFraction;
                $fillMultiple = $trailingTriggerMultiple;

                $realizedValue +=
                    $soldFraction * $fillMultiple;

                $remainingFraction = 0.0;
                $trailingStopHit = true;

                $event = [
                    'type' => 'trailing_stop',
                    'label' => 'TRAILING STOP 25%',
                    'sold_fraction' => $soldFraction,
                    'fill_multiple' => $fillMultiple,
                    'observed_multiple' => $multiple,
                    'peak_multiple' => $peakMultiple,
                    'observed_market_cap' => $marketCap,
                    'triggered_at' => now()->toIso8601String(),
                ];

                $exitEvents[] = $event;
                $strategyEvents[] = $event;
            }
        }

        $strategyValueMultiple =
            $realizedValue
            + ($remainingFraction * $multiple);

        $strategyReturnPercent =
            ($strategyValueMultiple - 1) * 100;

        $strategyClosed = $remainingFraction <= 0.000001;

        /*
         * VIRTUAL SOL WALLET ACCOUNTING
         *
         * For every new simulated exit:
         * - return the simulated SOL proceeds to available balance;
         * - reduce invested balance by the original cost basis sold;
         * - add realized P/L to the wallet;
         * - update position-level realized SOL and trade P/L.
         *
         * Wallet + position updates are wrapped in one DB transaction so
         * they either both succeed or both roll back.
         */
        $initialInvestmentSol =
            max(0.0, (float) ($position->initial_investment_sol ?? 0));

        $positionRealizedSol =
            max(0.0, (float) ($position->realized_sol ?? 0));

        $positionTradePnlSol =
            (float) ($position->trade_pnl_sol ?? 0);

        $walletSolReturned = 0.0;
        $walletCostBasisReleased = 0.0;
        $walletRealizedPnl = 0.0;

        if ($initialInvestmentSol > 0 && !empty($strategyEvents)) {
            foreach ($strategyEvents as &$strategyEvent) {
                $soldFraction = max(
                    0.0,
                    min(
                        1.0,
                        (float) ($strategyEvent['sold_fraction'] ?? 0)
                    )
                );

                $fillMultiple = max(
                    0.0,
                    (float) ($strategyEvent['fill_multiple'] ?? 0)
                );

                $costBasisSold =
                    $initialInvestmentSol * $soldFraction;

                $solReturned =
                    $costBasisSold * $fillMultiple;

                $realizedPnl =
                    $solReturned - $costBasisSold;

                $strategyEvent['cost_basis_sol'] =
                    round($costBasisSold, 8);

                $strategyEvent['sol_returned'] =
                    round($solReturned, 8);

                $strategyEvent['realized_pnl_sol'] =
                    round($realizedPnl, 8);

                $strategyEvent['wallet_applied'] = true;

                $walletSolReturned += $solReturned;
                $walletCostBasisReleased += $costBasisSold;
                $walletRealizedPnl += $realizedPnl;
            }

            unset($strategyEvent);

            /*
             * Replace the just-created exit-event copies with the enriched
             * versions so the JSON audit trail also records SOL proceeds.
             */
            foreach ($strategyEvents as $enrichedEvent) {
                foreach ($exitEvents as $index => $exitEvent) {
                    if (
                        ($exitEvent['type'] ?? null)
                            === ($enrichedEvent['type'] ?? null)
                        && ($exitEvent['triggered_at'] ?? null)
                            === ($enrichedEvent['triggered_at'] ?? null)
                    ) {
                        $exitEvents[$index] = $enrichedEvent;
                        break;
                    }
                }
            }

            $positionRealizedSol += $walletSolReturned;
            $positionTradePnlSol += $walletRealizedPnl;
        }

        $remainingInvestmentSol =
            $initialInvestmentSol * $remainingFraction;

        DB::transaction(function () use (
            $position,
            $marketCap,
            $price,
            $peakMc,
            $peakMultiple,
            $maxDrawdown,
            $milestones,
            $remainingFraction,
            $realizedValue,
            $strategyValueMultiple,
            $strategyReturnPercent,
            $tp50Hit,
            $tp2xHit,
            $stopLossHit,
            $trailingStopHit,
            $exitEvents,
            $strategyClosed,
            $positionRealizedSol,
            $positionTradePnlSol,
            $remainingInvestmentSol,
            $walletSolReturned,
            $walletCostBasisReleased,
            $walletRealizedPnl
        ): void {
            $lockedPosition = PaperPosition::query()
                ->whereKey($position->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * If this tracker is ever run concurrently, do not apply the
             * same exit event to the virtual wallet twice.
             */
            $existingExitTypes = collect(
                $lockedPosition->exit_events ?? []
            )->pluck('type')->filter()->all();

            $newExitTypes = collect($exitEvents)
                ->pluck('type')
                ->filter()
                ->all();

            $hasAlreadyAppliedExit =
                count(array_intersect(
                    $existingExitTypes,
                    $newExitTypes
                )) > 0
                && count($existingExitTypes) >= count($newExitTypes);

            if (
                $walletSolReturned > 0
                && !$hasAlreadyAppliedExit
            ) {
                $wallet = PaperWallet::query()
                    ->where('name', 'default')
                    ->lockForUpdate()
                    ->firstOrFail();

                $wallet->available_balance_sol =
                    (float) $wallet->available_balance_sol
                    + $walletSolReturned;

                $wallet->invested_balance_sol =
                    max(
                        0.0,
                        (float) $wallet->invested_balance_sol
                        - $walletCostBasisReleased
                    );

                $wallet->realized_pnl_sol =
                    (float) $wallet->realized_pnl_sol
                    + $walletRealizedPnl;

                $wallet->save();
            }

            $lockedPosition->update([
                'last_market_cap' => $marketCap,
                'last_price' => $price,
                'last_checked_at' => now(),
                'peak_market_cap' => $peakMc,
                'peak_multiple' => $peakMultiple,
                'max_drawdown_percent' => $maxDrawdown,
                'milestones' => $milestones,

                'remaining_fraction' => $remainingFraction,
                'realized_value_multiple' => $realizedValue,
                'strategy_value_multiple' => $strategyValueMultiple,
                'strategy_return_percent' => $strategyReturnPercent,

                'tp_50_hit' => $tp50Hit,
                'tp_2x_hit' => $tp2xHit,
                'stop_loss_hit' => $stopLossHit,
                'trailing_stop_hit' => $trailingStopHit,
                'exit_events' => $exitEvents,

                'remaining_investment_sol' =>
                    $remainingInvestmentSol,

                'realized_sol' =>
                    $positionRealizedSol,

                'trade_pnl_sol' =>
                    $positionTradePnlSol,

                'status' =>
                    $strategyClosed ? 'closed' : 'open',

                'closed_at' =>
                    $strategyClosed ? now() : null,
            ]);

            $position->setRawAttributes(
                $lockedPosition->getAttributes(),
                true
            );
        });

        PaperPositionSnapshot::create([
            'paper_position_id' => $position->id,
            'snapshot_type' => 'periodic',
            'market_cap' => $marketCap,
            'price' => $price,
            'liquidity' => $liquidity,
            'return_percent' => $returnPercent,
            'multiple' => $multiple,
            'drawdown_from_peak_percent' => $drawdownFromPeak,
            'raw_data' => [
                'dex' => $dex,
                'elapsed_seconds' => $elapsedSeconds,
                'strategy' => [
                    'remaining_fraction' => $remainingFraction,
                    'realized_value_multiple' => $realizedValue,
                    'strategy_value_multiple' =>
                        $strategyValueMultiple,
                    'strategy_return_percent' =>
                        $strategyReturnPercent,
                    'tp_50_hit' => $tp50Hit,
                    'tp_2x_hit' => $tp2xHit,
                    'stop_loss_hit' => $stopLossHit,
                    'trailing_stop_hit' => $trailingStopHit,
                ],
            ],
            'recorded_at' => now(),
        ]);

        $this->captureTimedSnapshot(
            $position,
            '1m',
            60,
            $elapsedSeconds,
            $marketCap,
            $price,
            $liquidity,
            $returnPercent,
            $multiple,
            $drawdownFromPeak,
            $dex
        );

        $this->captureTimedSnapshot(
            $position,
            '5m',
            300,
            $elapsedSeconds,
            $marketCap,
            $price,
            $liquidity,
            $returnPercent,
            $multiple,
            $drawdownFromPeak,
            $dex
        );

        $this->captureTimedSnapshot(
            $position,
            '10m',
            600,
            $elapsedSeconds,
            $marketCap,
            $price,
            $liquidity,
            $returnPercent,
            $multiple,
            $drawdownFromPeak,
            $dex
        );

        $this->info(
            sprintf(
                'PAPER: %s | Entry $%s | Now $%s | Token %+.2f%% | %.2fx | Peak %.2fx | Strategy %+.2f%% | Remaining %.0f%% | Realized %.4f SOL',
                $position->symbol,
                number_format($entryMc, 2),
                number_format($marketCap, 2),
                $returnPercent,
                $multiple,
                $peakMultiple,
                $strategyReturnPercent,
                $remainingFraction * 100,
                $positionRealizedSol
            )
        );

        foreach ($strategyEvents as $event) {
            try {
                $walletAfterExit = PaperWallet::query()
                    ->where('name', 'default')
                    ->first();

                $eventType = $event['type'] ?? 'exit';

                $heading = match ($eventType) {
                    'stop_loss' =>
                        '🔴🔴🔴 <b>PAPER SELL EXECUTED</b> 🔴🔴🔴',
                    'take_profit_50' =>
                        '💰💰 <b>PAPER TAKE PROFIT — TP1</b> 💰💰',
                    'take_profit_2x' =>
                        '🚀🚀 <b>PAPER TAKE PROFIT — TP2</b> 🚀🚀',
                    'trailing_stop' =>
                        '🏃🔴 <b>PAPER TRAILING EXIT</b> 🔴🏃',
                    default =>
                        '🔴 <b>PAPER SELL EXECUTED</b>',
                };

                $actionText = match ($eventType) {
                    'stop_loss' => '🛑 <b>STOP LOSS TRIGGERED</b>',
                    'take_profit_50' => '✅ <b>1.50x TARGET HIT</b>',
                    'take_profit_2x' => '✅ <b>2.00x TARGET HIT</b>',
                    'trailing_stop' => '🏃 <b>TRAILING STOP TRIGGERED</b>',
                    default => '✅ <b>EXIT EXECUTED</b>',
                };

                $positionStatusText =
                    $strategyClosed
                        ? "❌ <b>POSITION CLOSED</b>"
                        : "📦 <b>POSITION STILL OPEN: " .
                            number_format(
                                $remainingFraction * 100,
                                0
                            ) .
                            "% remaining</b>";

                $walletText =
                    $walletAfterExit
                        ? "💳 <b>WALLET AFTER SELL</b>\n" .
                            "Available: <b>" .
                            number_format(
                                (float) $walletAfterExit->available_balance_sol,
                                4
                            ) .
                            " SOL</b>\n" .
                            "Invested: <b>" .
                            number_format(
                                (float) $walletAfterExit->invested_balance_sol,
                                4
                            ) .
                            " SOL</b>\n" .
                            "Realized P/L: <b>" .
                            sprintf(
                                '%+.4f SOL',
                                (float) $walletAfterExit->realized_pnl_sol
                            ) .
                            "</b>\n\n"
                        : '';

                $telegram->send(
                    "{$heading}\n\n" .
                    "💰 <b>{$position->symbol}</b>\n\n" .
                    "{$actionText}\n\n" .
                    "🎯 <b>Entry MC:</b> $" .
                    number_format($entryMc, 2) . "\n" .
                    "📊 <b>Current MC:</b> $" .
                    number_format($marketCap, 2) . "\n" .
                    "✖️ <b>Observed:</b> " .
                    number_format($multiple, 2) . "x\n" .
                    "💵 <b>Simulated Fill:</b> " .
                    number_format(
                        (float) $event['fill_multiple'],
                        2
                    ) . "x\n" .
                    "📤 <b>Sold:</b> " .
                    number_format(
                        (float) $event['sold_fraction'] * 100,
                        0
                    ) . "%\n" .
                    "🪙 <b>SOL Returned:</b> " .
                    number_format(
                        (float) ($event['sol_returned'] ?? 0),
                        4
                    ) . " SOL\n" .
                    "💹 <b>Trade P/L on this exit:</b> " .
                    sprintf(
                        '%+.4f SOL',
                        (float) ($event['realized_pnl_sol'] ?? 0)
                    ) . "\n" .
                    "📈 <b>Strategy Return:</b> " .
                    sprintf(
                        '%+.2f%%',
                        $strategyReturnPercent
                    ) . "\n\n" .
                    $walletText .
                    "{$positionStatusText}\n\n" .
                    "📍 <code>{$position->address}</code>\n\n" .
                    "⚠️ <b>PAPER TRADE — NO REAL SOL USED</b>"
                );
            } catch (\Throwable $e) {
                $this->warn(
                    "PAPER EXIT TELEGRAM FAILED: " .
                    $e->getMessage()
                );
            }
        }

        foreach ($newMilestones as $key => $targetMultiple) {
            $label = match ($key) {
                'plus_25' => '+25%',
                'plus_50' => '+50%',
                'x2' => '2X',
                'x3' => '3X',
                'x5' => '5X',
                default => strtoupper($key),
            };

            try {
                $telegram->send(
                    "🎯 <b>PAPER MILESTONE: {$label}</b>\n\n" .
                    "<b>{$position->symbol}</b>\n" .
                    "🧪 Paper only\n" .
                    "💰 Entry MC: $" .
                    number_format($entryMc, 2) . "\n" .
                    "📈 Current MC: $" .
                    number_format($marketCap, 2) . "\n" .
                    "🚀 Token return: " .
                    sprintf('%+.2f%%', $returnPercent) . "\n" .
                    "✖️ Multiple: " .
                    number_format($multiple, 2) . "x\n" .
                    "🏔 Peak: " .
                    number_format($peakMultiple, 2) . "x\n" .
                    "📈 Strategy return: " .
                    sprintf(
                        '%+.2f%%',
                        $strategyReturnPercent
                    ) .
                    "\n\n📍 <code>{$position->address}</code>"
                );
            } catch (\Throwable $e) {
                $this->warn(
                    "PAPER MILESTONE TELEGRAM FAILED: " .
                    $e->getMessage()
                );
            }
        }
    }

    private function captureTimedSnapshot(
        PaperPosition $position,
        string $type,
        int $thresholdSeconds,
        int $elapsedSeconds,
        float $marketCap,
        mixed $price,
        mixed $liquidity,
        float $returnPercent,
        float $multiple,
        float $drawdownFromPeak,
        array $dex
    ): void {
        if ($elapsedSeconds < $thresholdSeconds) {
            return;
        }

        $alreadyCaptured =
            PaperPositionSnapshot::query()
                ->where(
                    'paper_position_id',
                    $position->id
                )
                ->where('snapshot_type', $type)
                ->exists();

        if ($alreadyCaptured) {
            return;
        }

        PaperPositionSnapshot::create([
            'paper_position_id' => $position->id,
            'snapshot_type' => $type,
            'market_cap' => $marketCap,
            'price' => $price,
            'liquidity' => $liquidity,
            'return_percent' => $returnPercent,
            'multiple' => $multiple,
            'drawdown_from_peak_percent' =>
                $drawdownFromPeak,
            'raw_data' => [
                'dex' => $dex,
                'elapsed_seconds' => $elapsedSeconds,
            ],
            'recorded_at' => now(),
        ]);
    }
}
