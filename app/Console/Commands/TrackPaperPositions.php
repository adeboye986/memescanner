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
        'Track open paper positions, milestones, peaks and drawdowns';

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

        $position->update([
            'last_market_cap' => $marketCap,
            'last_price' => $price,
            'last_checked_at' => now(),
            'peak_market_cap' => $peakMc,
            'peak_multiple' => $peakMultiple,
            'max_drawdown_percent' => $maxDrawdown,
            'milestones' => $milestones,
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
                'PAPER: %s | Entry $%s | Now $%s | %+.2f%% | %.2fx | Peak %.2fx | DD %.2f%%',
                $position->symbol,
                number_format($entryMc, 2),
                number_format($marketCap, 2),
                $returnPercent,
                $multiple,
                $peakMultiple,
                $drawdownFromPeak
            )
        );

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
                    "🚀 Return: " .
                    sprintf('%+.2f%%', $returnPercent) . "\n" .
                    "✖️ Multiple: " .
                    number_format($multiple, 2) . "x\n" .
                    "🏔 Peak: " .
                    number_format($peakMultiple, 2) . "x\n\n" .
                    "📍 <code>{$position->address}</code>"
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
