<?php

namespace App\Console\Commands;

use App\Models\PaperPosition;
use Illuminate\Console\Command;

class ShowOpenPaperPositions extends Command
{
    protected $signature = 'tokens:paper-open';

    protected $description = 'Show all currently open paper trades and their exit levels';

    public function handle(): int
    {
        $positions = PaperPosition::query()
            ->where('status', 'open')
            ->where('initial_investment_sol', '>', 0)
            ->orderBy('entry_at')
            ->get();

        if ($positions->isEmpty()) {
            $this->info('No funded paper positions are currently open.');

            return self::SUCCESS;
        }

        $this->info('OPEN PAPER POSITIONS');
        $this->newLine();

        foreach ($positions as $position) {
            $entryMc = (float) $position->entry_market_cap;
            $lastMc = (float) ($position->last_market_cap ?? 0);
            $peakMc = max($entryMc, (float) ($position->peak_market_cap ?? 0));

            $currentMultiple = ($entryMc > 0 && $lastMc > 0)
                ? $lastMc / $entryMc
                : 1.0;

            $currentReturn = ($currentMultiple - 1) * 100;
            $peakMultiple = $entryMc > 0 ? $peakMc / $entryMc : 1.0;

            $remainingFraction = $position->remaining_fraction !== null
                ? (float) $position->remaining_fraction
                : 1.0;

            $remainingCost = (float) ($position->remaining_investment_sol ?? 0);

            if ($remainingCost <= 0 && $remainingFraction > 0) {
                $remainingCost =
                    (float) $position->initial_investment_sol * $remainingFraction;
            }

            $currentValue = $remainingCost * $currentMultiple;
            $unrealizedPnl = $currentValue - $remainingCost;

            $stopLossMc = $entryMc * 0.90;
            $profit1xMc = $entryMc * 2.00;
            $protectionMc = $entryMc * 2.50;
            $profit2xMc = $entryMc * 3.00;

            $protectionArmed =
                (bool) ($position->tp_50_hit ?? false)
                || $peakMultiple >= 2.50;

            $timeOpen = $position->entry_at
                ? $position->entry_at->diffForHumans(now(), true, false, 2)
                : 'N/A';

            $freshness = $position->last_checked_at
                ? $position->last_checked_at->diffForHumans(now(), true, false, 2).' ago'
                : 'Never';

            $nextAction = match (true) {
                ! $protectionArmed && $currentMultiple <= 0.90 => 'STOP LOSS threshold reached — tracker should close 100%',
                ! $protectionArmed && $currentMultiple < 2.50 => 'Hold 100% — waiting for +150% profit or -10% stop',
                $protectionArmed && $currentMultiple >= 3.00 => '+200% profit target reached — tracker should close 100%',
                $protectionArmed && $currentMultiple <= 2.00 => 'Protected floor reached — tracker should close 100%',
                $protectionArmed => 'Protection armed — hold for +200% profit; exit if back to +100%',
                default => 'Monitoring',
            };

            $this->line(
                sprintf(
                    '<fg=cyan;options=bold>%s</>  <fg=green>OPEN</>',
                    $position->symbol ?: $position->address
                )
            );

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Invested', number_format((float) $position->initial_investment_sol, 4).' SOL'],
                    ['Entry MC', '$'.number_format($entryMc, 2)],
                    ['Current MC', $lastMc > 0 ? '$'.number_format($lastMc, 2) : 'Awaiting tracker update'],
                    ['Current Return', sprintf('%+.2f%%', $currentReturn)],
                    ['Current Multiple', number_format($currentMultiple, 2).'x'],
                    ['Peak', number_format($peakMultiple, 2).'x'],
                    ['Remaining', number_format($remainingFraction * 100, 0).'%'],
                    ['Current Value', number_format($currentValue, 4).' SOL'],
                    ['Unrealized P/L', sprintf('%+.4f SOL', $unrealizedPnl)],
                    ['Realized SOL', number_format((float) ($position->realized_sol ?? 0), 4).' SOL'],
                    ['Realized P/L', sprintf('%+.4f SOL', (float) ($position->trade_pnl_sol ?? 0))],
                    ['Stop Loss', '$'.number_format($stopLossMc, 2).' (-10% / close 100%)'],
                    ['1X Profit', '$'.number_format($profit1xMc, 2).' (+100% / hold)'],
                    [
                        'Protection',
                        '$'.number_format($protectionMc, 2).
                        ' (+150% / arm only) — '.
                        ($protectionArmed ? 'ARMED' : 'Not armed'),
                    ],
                    ['2X Profit', '$'.number_format($profit2xMc, 2).' (+200% / close 100%)'],
                    [
                        'Protected Floor',
                        '$'.number_format($profit1xMc, 2).
                        ' (+100% / close 100% after protection)',
                    ],
                    ['Time Open', $timeOpen],
                    ['Last Checked', $freshness],
                    ['Next Action', $nextAction],
                ]
            );

            $this->newLine();
        }

        $this->line(
            'Run <fg=yellow>php artisan tokens:paper-track</> for an immediate market refresh.'
        );

        return self::SUCCESS;
    }
}
