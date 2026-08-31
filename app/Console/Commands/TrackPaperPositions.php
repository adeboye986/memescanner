<?php

namespace App\Console\Commands;

use App\Models\PaperPosition;
use App\Models\PaperPositionSnapshot;
use App\Services\DexScreenerService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

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

        $position->update([
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

            'status' => $strategyClosed ? 'closed' : 'open',
            'closed_at' => $strategyClosed ? now() : null,
        ]);

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
                'PAPER: %s | Entry $%s | Now $%s | Token %+.2f%% | %.2fx | Peak %.2fx | Strategy %+.2f%% | Remaining %.0f%%',
                $position->symbol,
                number_format($entryMc, 2),
                number_format($marketCap, 2),
                $returnPercent,
                $multiple,
                $peakMultiple,
                $strategyReturnPercent,
                $remainingFraction * 100
            )
        );

        foreach ($strategyEvents as $event) {
            try {
                $telegram->send(
                    "🧪 <b>PAPER EXIT: {$event['label']}</b>\n\n" .
                    "<b>{$position->symbol}</b>\n" .
                    "💰 Entry MC: $" .
                    number_format($entryMc, 2) . "\n" .
                    "📊 Current MC: $" .
                    number_format($marketCap, 2) . "\n" .
                    "✖️ Observed: " .
                    number_format($multiple, 2) . "x\n" .
                    "💵 Simulated fill: " .
                    number_format(
                        (float) $event['fill_multiple'],
                        2
                    ) . "x\n" .
                    "📤 Sold: " .
                    number_format(
                        (float) $event['sold_fraction'] * 100,
                        0
                    ) . "%\n" .
                    "📦 Remaining: " .
                    number_format(
                        $remainingFraction * 100,
                        0
                    ) . "%\n" .
                    "📈 Strategy return: " .
                    sprintf(
                        '%+.2f%%',
                        $strategyReturnPercent
                    ) .
                    "\n\n📍 <code>{$position->address}</code>"
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
