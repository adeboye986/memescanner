<?php

namespace App\Console\Commands;

use App\Models\PaperPosition;
use App\Models\PaperPositionSnapshot;
use App\Services\Chains\ChainManager;
use App\Services\PaperWalletService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TrackPaperPositions extends Command
{
    protected $signature = 'tokens:paper-track
        {--limit=50 : Maximum open paper positions to check per run}';

    protected $description =
        'Track paper positions, milestones, peaks, drawdowns and simulated exits';

    /** @var array{open_positions: int, priced_positions: int, provider_failures: int, provider_requests: int, rate_limited: bool} */
    private array $cycleMetrics = [
        'open_positions' => 0,
        'priced_positions' => 0,
        'provider_failures' => 0,
        'provider_requests' => 0,
        'rate_limited' => false,
    ];

    public function handle(
        ChainManager $chains,
        TelegramService $telegram,
        PaperWalletService $wallets,
    ): int {
        $limit = max(1, min((int) $this->option('limit'), 200));

        return $this->trackCycle($chains, $telegram, $wallets, $limit);
    }

    public function trackCycle(
        ChainManager $chains,
        TelegramService $telegram,
        PaperWalletService $wallets,
        int $limit = 50,
        bool $fastProcess = false,
    ): int {
        $limit = max(1, min($limit, 200));
        $this->cycleMetrics = [
            'open_positions' => 0,
            'priced_positions' => 0,
            'provider_failures' => 0,
            'provider_requests' => 0,
            'rate_limited' => false,
        ];

        if (! $fastProcess && Cache::lock('paper-tracker.fast.process')->isLocked()) {
            $this->warn('Paper tracker fallback skipped because the fast tracker is active.');

            return self::SUCCESS;
        }

        $cycleLock = Cache::lock(
            'paper-tracker.position-cycle',
            max(30, (int) config('services.trading.paper_tracker_lock_seconds', 300)),
        );

        if (! $cycleLock->get()) {
            $this->warn('Paper tracker cycle skipped because another cycle is active.');

            return self::SUCCESS;
        }

        try {

            $positions = PaperPosition::query()
                ->where('status', 'open')
                ->where('initial_investment_sol', '>', 0)
                ->orderByRaw('last_checked_at IS NULL DESC')
                ->orderBy('last_checked_at')
                ->limit($limit)
                ->get();

            if ($positions->isEmpty()) {
                $this->info('No open paper positions.');

                return self::SUCCESS;
            }

            $this->cycleMetrics['open_positions'] = $positions->count();

            foreach ($positions->groupBy(fn (PaperPosition $position): string => $position->chain->value) as $chainPositions) {
                $addresses = $chainPositions->pluck('address')->all();
                $this->cycleMetrics['provider_requests'] += (int) ceil(count($addresses) / 30);

                try {
                    $marketData = $chains->for($chainPositions->first()->chain)->marketDataMany($addresses);
                } catch (\Throwable $exception) {
                    $this->cycleMetrics['provider_failures'] += $chainPositions->count();
                    $this->cycleMetrics['rate_limited'] = $this->cycleMetrics['rate_limited']
                        || str_contains($exception->getMessage(), '429');
                    $this->warn('PAPER TRACK PROVIDER FAILURE: '.$exception->getMessage());

                    continue;
                }

                foreach ($chainPositions as $position) {
                    $key = $position->chain->value === 'ethereum'
                        ? strtolower($position->address)
                        : $position->address;
                    $dex = $marketData[$key] ?? null;

                    if (! is_array($dex) || ! ($dex['available'] ?? false)) {
                        $this->cycleMetrics['provider_failures']++;
                        $this->warn("PAPER TRACK UNAVAILABLE: {$position->symbol} | no valid market data");

                        continue;
                    }

                    $this->cycleMetrics['priced_positions']++;
                    $this->trackOne($position, $dex, $telegram, $wallets);
                }
            }

            return self::SUCCESS;
        } finally {
            $cycleLock->release();
        }
    }

    private function trackOne(
        PaperPosition $position,
        array $dex,
        TelegramService $telegram,
        PaperWalletService $wallets,
    ): void {
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
            'profit_1x' => 2.00,
            'protection_1_5x' => 2.50,
            'profit_2x' => 3.00,
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
         * PAPER EXIT STRATEGY — FULL-POSITION MODEL
         *
         * Entry value = 1.00x position value.
         * +100% profit = 2.00x value.
         * +150% profit = 2.50x value.
         * +200% profit = 3.00x value.
         *
         * Rules:
         * - Before +100% profit is reached, -10% closes 100% at 0.90x.
         * - Reaching +100% profit (2.00x) arms a +100% protected floor.
         * - Between +100% and +200% profit, keep holding.
         * - If the token falls back to +100% before reaching +200%, close
         *   100% at the protected 2.00x floor.
         * - Reaching +200% profit (3.00x) upgrades the protected floor to
         *   +200%; do not sell just because the target was reached.
         * - Above +200% profit, keep holding.
         * - If the token later falls back to +200%, close 100% at 3.00x.
         * - No partial exits.
         * - Protected levels are triggers, not guaranteed fills. Once a
         *   trigger is crossed, paper accounting uses the market multiple
         *   actually observed by the tracker as the simulated fill.
         *
         * Existing booleans are reused for compatibility:
         * tp_50_hit = +100% profit floor armed
         * tp_2x_hit = +200% profit floor armed
         * trailing_stop_hit = protected-floor exit hit
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

        $protectionArmed = (bool) ($position->tp_50_hit ?? false);
        $twoXProfitProtectionArmed = (bool) ($position->tp_2x_hit ?? false);
        $stopLossHit = (bool) ($position->stop_loss_hit ?? false);
        $protectedExitHit =
            (bool) ($position->trailing_stop_hit ?? false);

        $strategyEvents = [];
        $protectionJustArmed = false;
        $twoXProtectionJustArmed = false;

        if (
            ! $protectionArmed
            && $peakMultiple >= 2.00
            && $remainingFraction > 0
        ) {
            $protectionArmed = true;
            $protectionJustArmed = true;
        }

        if (
            $protectionArmed
            && ! $twoXProfitProtectionArmed
            && $peakMultiple >= 3.00
            && $remainingFraction > 0
        ) {
            $twoXProfitProtectionArmed = true;
            $twoXProtectionJustArmed = true;
        }

        if (
            ! $protectionArmed
            && ! $stopLossHit
            && $multiple <= 0.90
            && $remainingFraction > 0
        ) {
            $soldFraction = $remainingFraction;
            $triggerMultiple = 0.90;
            $fillMultiple = $multiple;

            $realizedValue += $soldFraction * $fillMultiple;
            $remainingFraction = 0.0;
            $stopLossHit = true;

            $event = [
                'type' => 'stop_loss',
                'label' => 'STOP LOSS -10%',
                'sold_fraction' => $soldFraction,
                'trigger_multiple' => $triggerMultiple,
                'trigger_market_cap' => $entryMc * $triggerMultiple,
                'fill_multiple' => $fillMultiple,
                'fill_market_cap' => $entryMc * $fillMultiple,
                'observed_multiple' => $multiple,
                'observed_market_cap' => $marketCap,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents[] = $event;
            $strategyEvents[] = $event;
        }

        $protectedFloorMultiple = match (true) {
            $twoXProfitProtectionArmed => 3.00,
            $protectionArmed => 2.00,
            default => null,
        };

        if (
            $protectedFloorMultiple !== null
            && ! $protectionJustArmed
            && ! $twoXProtectionJustArmed
            && ! $protectedExitHit
            && $multiple <= $protectedFloorMultiple
            && $remainingFraction > 0
        ) {
            $soldFraction = $remainingFraction;
            $triggerMultiple = $protectedFloorMultiple;
            $fillMultiple = $multiple;
            $protectedFloorProfitPercent =
                (int) round(($protectedFloorMultiple - 1) * 100);

            $realizedValue += $soldFraction * $fillMultiple;
            $remainingFraction = 0.0;
            $protectedExitHit = true;

            $event = [
                'type' => 'protected_floor_exit',
                'label' => "PROTECTED EXIT +{$protectedFloorProfitPercent}% PROFIT",
                'protected_floor_profit_percent' => $protectedFloorProfitPercent,
                'sold_fraction' => $soldFraction,
                'trigger_multiple' => $triggerMultiple,
                'trigger_market_cap' => $entryMc * $triggerMultiple,
                'fill_multiple' => $fillMultiple,
                'fill_market_cap' => $entryMc * $fillMultiple,
                'observed_multiple' => $multiple,
                'peak_multiple' => $peakMultiple,
                'observed_market_cap' => $marketCap,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents[] = $event;
            $strategyEvents[] = $event;
        }

        $tp50Hit = $protectionArmed;
        $tp2xHit = $twoXProfitProtectionArmed;
        $trailingStopHit = $protectedExitHit;

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

        if ($initialInvestmentSol > 0 && ! empty($strategyEvents)) {
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
        $observationApplied = false;

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
            $walletRealizedPnl,
            $wallets,
            &$observationApplied,
        ): void {
            $lockedPosition = PaperPosition::query()
                ->whereKey($position->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedPosition->status !== 'open'
                || ($lockedPosition->remaining_fraction !== null && (float) $lockedPosition->remaining_fraction <= 0)
            ) {
                return;
            }

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
                && ! $hasAlreadyAppliedExit
            ) {
                $wallet = $wallets->lockedDefault($lockedPosition->chain);

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

                'remaining_investment_sol' => $remainingInvestmentSol,

                'realized_sol' => $positionRealizedSol,

                'trade_pnl_sol' => $positionTradePnlSol,

                'status' => $strategyClosed ? 'closed' : 'open',

                'closed_at' => $strategyClosed ? now() : null,
            ]);

            $position->setRawAttributes(
                $lockedPosition->getAttributes(),
                true
            );
            $observationApplied = true;
        });

        if (! $observationApplied) {
            $this->warn("PAPER TRACK SKIP: {$position->symbol} | position changed concurrently");

            return;
        }

        $currency = $wallets->currency($position->chain);

        $importantSnapshotType = match (true) {
            ! empty($strategyEvents) => 'exit',
            $twoXProtectionJustArmed => 'protection_200_armed',
            $protectionJustArmed => 'protection_100_armed',
            default => null,
        };
        $snapshotInterval = max(1, (int) config('services.trading.paper_tracker_snapshot_seconds', 10));
        $periodicSnapshotDue = ! PaperPositionSnapshot::query()
            ->where('paper_position_id', $position->id)
            ->where('snapshot_type', 'periodic')
            ->where('recorded_at', '>', now()->subSeconds($snapshotInterval))
            ->exists();

        if ($importantSnapshotType !== null || $periodicSnapshotDue) {
            PaperPositionSnapshot::create([
                'paper_position_id' => $position->id,
                'snapshot_type' => $importantSnapshotType ?? 'periodic',
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
                        'strategy_value_multiple' => $strategyValueMultiple,
                        'strategy_return_percent' => $strategyReturnPercent,
                        'tp_50_hit' => $tp50Hit,
                        'tp_2x_hit' => $tp2xHit,
                        'stop_loss_hit' => $stopLossHit,
                        'trailing_stop_hit' => $trailingStopHit,
                    ],
                ],
                'recorded_at' => now(),
            ]);
        }

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
                'PAPER: %s | Entry $%s | Now $%s | Token %+.2f%% | %.2fx | Peak %.2fx | Strategy %+.2f%% | Remaining %.0f%% | Realized %.4f %s',
                $position->symbol,
                number_format($entryMc, 2),
                number_format($marketCap, 2),
                $returnPercent,
                $multiple,
                $peakMultiple,
                $strategyReturnPercent,
                $remainingFraction * 100,
                $positionRealizedSol,
                $currency,
            )
        );

        if ($protectionJustArmed && $remainingFraction > 0) {
            try {
                $telegram->send(
                    "🛡️ <b>+100% PROFIT PROTECTED</b>\n\n".
                    "Token: <b>{$position->symbol}</b>\n".
                    'Chain: <b>'.$position->chain->label()."</b>\n".
                    'Current: <b>'.number_format($multiple, 2)."x</b>\n".
                    "Protected floor: <b>2.00x</b>\n".
                    "Position remains <b>OPEN</b>.\n".
                    "Full exit will trigger on a later tracker observation at or below the protected floor.\n\n".
                    "📍 <code>{$position->address}</code>\n\n".
                    "⚠️ <b>PAPER TRADE — NO REAL {$currency} USED</b>"
                );
            } catch (\Throwable $e) {
                $this->warn(
                    'PROTECTION ALERT TELEGRAM FAILED: '.$e->getMessage()
                );
            }
        }

        if ($twoXProtectionJustArmed && $remainingFraction > 0) {
            try {
                $telegram->send(
                    "🛡️ <b>+200% PROFIT PROTECTED</b>\n\n".
                    "Token: <b>{$position->symbol}</b>\n".
                    'Chain: <b>'.$position->chain->label()."</b>\n".
                    'Current: <b>'.number_format($multiple, 2)."x</b>\n".
                    "Protected floor: <b>3.00x</b>\n".
                    "Position remains <b>OPEN</b>.\n".
                    "Full exit will trigger on a later tracker observation at or below the protected floor.\n\n".
                    "📍 <code>{$position->address}</code>\n\n".
                    "⚠️ <b>PAPER TRADE — NO REAL {$currency} USED</b>"
                );
            } catch (\Throwable $e) {
                $this->warn(
                    'PROTECTION UPGRADE TELEGRAM FAILED: '.$e->getMessage()
                );
            }
        }

        foreach ($strategyEvents as $event) {
            try {
                $walletAfterExit = $wallets->default($position->chain);

                $eventType = $event['type'] ?? 'exit';

                $heading = match ($eventType) {
                    'stop_loss' => '🔴🔴🔴 <b>PAPER STOP LOSS — FULL EXIT</b> 🔴🔴🔴',
                    'full_target_2x_profit' => '🚀🚀 <b>PAPER +200% TARGET — FULL EXIT</b> 🚀🚀',
                    'protected_floor_exit' => '🛡️🔴 <b>PAPER PROTECTED FLOOR — FULL EXIT</b> 🔴🛡️',
                    default => '🔴 <b>PAPER SELL EXECUTED</b>',
                };

                $protectedFloorProfitPercent =
                    (int) ($event['protected_floor_profit_percent'] ?? 0);

                $actionText = match ($eventType) {
                    'stop_loss' => '🛑 <b>-10% STOP LOSS TRIGGERED</b>',
                    'full_target_2x_profit' => '✅ <b>+200% PROFIT TARGET HIT</b>',
                    'protected_floor_exit' => "🛡️ <b>PROTECTED +{$protectedFloorProfitPercent}% PROFIT FLOOR TRIGGERED</b>",
                    default => '✅ <b>EXIT EXECUTED</b>',
                };

                $positionStatusText =
                    $strategyClosed
                        ? '❌ <b>POSITION CLOSED</b>'
                        : '📦 <b>POSITION STILL OPEN: '.
                            number_format(
                                $remainingFraction * 100,
                                0
                            ).
                            '% remaining</b>';

                $walletText =
                    $walletAfterExit
                        ? "💳 <b>WALLET AFTER SELL</b>\n".
                            'Available: <b>'.
                            number_format(
                                (float) $walletAfterExit->available_balance_sol,
                                4
                            ).
                            " {$currency}</b>\n".
                            'Invested: <b>'.
                            number_format(
                                (float) $walletAfterExit->invested_balance_sol,
                                4
                            ).
                            " {$currency}</b>\n".
                            'Realized P/L: <b>'.
                            sprintf(
                                '%+.4f %s',
                                (float) $walletAfterExit->realized_pnl_sol,
                                $currency,
                            ).
                            "</b>\n\n"
                        : '';

                $telegram->send(
                    "{$heading}\n\n".
                    "💰 <b>{$position->symbol}</b>\n\n".
                    "{$actionText}\n\n".
                    '🎯 <b>Entry MC:</b> $'.
                    number_format($entryMc, 2)."\n".
                    '📊 <b>Current MC:</b> $'.
                    number_format($marketCap, 2)."\n".
                    '✖️ <b>Observed:</b> '.
                    number_format($multiple, 2)."x\n".
                    '🎯 <b>Trigger Floor:</b> '.
                    number_format(
                        (float) ($event['trigger_multiple'] ?? $event['fill_multiple']),
                        2
                    )."x\n".
                    '💵 <b>Simulated Fill:</b> '.
                    number_format(
                        (float) $event['fill_multiple'],
                        2
                    )."x\n".
                    '📤 <b>Sold:</b> '.
                    number_format(
                        (float) $event['sold_fraction'] * 100,
                        0
                    )."%\n".
                    "🪙 <b>{$currency} Returned:</b> ".
                    number_format(
                        (float) ($event['sol_returned'] ?? 0),
                        4
                    )." {$currency}\n".
                    '💹 <b>Trade P/L on this exit:</b> '.
                    sprintf(
                        '%+.4f %s',
                        (float) ($event['realized_pnl_sol'] ?? 0),
                        $currency,
                    )."\n".
                    '📈 <b>Strategy Return:</b> '.
                    sprintf(
                        '%+.2f%%',
                        $strategyReturnPercent
                    )."\n\n".
                    $walletText.
                    "{$positionStatusText}\n\n".
                    "📍 <code>{$position->address}</code>\n\n".
                    "⚠️ <b>PAPER TRADE — NO REAL {$currency} USED</b>"
                );
            } catch (\Throwable $e) {
                $this->warn(
                    'PAPER EXIT TELEGRAM FAILED: '.
                    $e->getMessage()
                );
            }
        }

        foreach ($newMilestones as $key => $targetMultiple) {
            $label = match ($key) {
                'profit_1x' => '1X PROFIT (+100%)',
                'protection_1_5x' => '1.50X PROFIT (+150%)',
                'profit_2x' => '2X PROFIT (+200%)',
                default => strtoupper($key),
            };

            try {
                $telegram->send(
                    "🎯 <b>PAPER MILESTONE: {$label}</b>\n\n".
                    "<b>{$position->symbol}</b>\n".
                    "🧪 Paper only\n".
                    '💰 Entry MC: $'.
                    number_format($entryMc, 2)."\n".
                    '📈 Current MC: $'.
                    number_format($marketCap, 2)."\n".
                    '🚀 Token return: '.
                    sprintf('%+.2f%%', $returnPercent)."\n".
                    '✖️ Multiple: '.
                    number_format($multiple, 2)."x\n".
                    '🏔 Peak: '.
                    number_format($peakMultiple, 2)."x\n".
                    '📈 Strategy return: '.
                    sprintf(
                        '%+.2f%%',
                        $strategyReturnPercent
                    ).
                    "\n\n📍 <code>{$position->address}</code>"
                );
            } catch (\Throwable $e) {
                $this->warn(
                    'PAPER MILESTONE TELEGRAM FAILED: '.
                    $e->getMessage()
                );
            }
        }
    }

    /** @return array{open_positions: int, priced_positions: int, provider_failures: int, provider_requests: int, rate_limited: bool} */
    public function cycleMetrics(): array
    {
        return $this->cycleMetrics;
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
            'drawdown_from_peak_percent' => $drawdownFromPeak,
            'raw_data' => [
                'dex' => $dex,
                'elapsed_seconds' => $elapsedSeconds,
            ],
            'recorded_at' => now(),
        ]);
    }
}
