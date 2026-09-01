<?php

namespace App\Console\Commands;

use App\Models\PaperPosition;
use App\Services\PaperTradeExitService;
use Illuminate\Console\Command;
use Throwable;

class ClosePaperPosition extends Command
{
    protected $signature = 'tokens:paper-close
        {position : Open position ID, token symbol, or token address}
        {--force : Close without interactive confirmation}';

    protected $description =
        'Manually close the remaining amount of an open paper trade at the current simulated market price';

    public function handle(PaperTradeExitService $exitService): int
    {
        $selector = trim((string) $this->argument('position'));

        $matches = PaperPosition::query()
            ->where('status', 'open')
            ->where('initial_investment_sol', '>', 0)
            ->where(function ($query) use ($selector): void {
                if (ctype_digit($selector)) {
                    $query->orWhereKey((int) $selector);
                }

                $query->orWhere('address', $selector)->orWhere('symbol', $selector);
            })
            ->get();

        if ($matches->isEmpty()) {
            $this->error("No funded open paper position found for: {$selector}");

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error("More than one open position matched '{$selector}'.");
            $this->table(
                ['ID', 'Symbol', 'Address', 'Entry MC'],
                $matches->map(fn (PaperPosition $position): array => [
                    $position->id,
                    $position->symbol,
                    $position->address,
                    '$'.number_format((float) $position->entry_market_cap, 2),
                ])->all(),
            );
            $this->line('Run the command again using the position ID or full address.');

            return self::FAILURE;
        }

        /** @var PaperPosition $position */
        $position = $matches->first();

        $this->table(['Metric', 'Value'], [
            ['Position ID', $position->id],
            ['Token', $position->symbol],
            ['Initial Investment', number_format((float) $position->initial_investment_sol, 4).' SOL'],
            ['Remaining', number_format($this->remainingFraction($position) * 100, 0).'%'],
            ['Entry MC', '$'.number_format((float) $position->entry_market_cap, 2)],
            ['Address', $position->address],
        ]);

        if (! $this->option('force') && ! $this->confirm("Close the remaining {$position->symbol} paper position now?", false)) {
            $this->warn('Manual close cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $exitService->closeManually($position);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $closed = $result['position'];
        $wallet = $result['wallet'];
        $event = $result['event'];

        $this->newLine();
        $this->info("PAPER TRADE CLOSED: {$closed->symbol}");
        $this->table(['Metric', 'Value'], [
            ['Closed At MC', '$'.number_format($result['market_cap'], 2)],
            ['Fill Multiple', number_format($result['multiple'], 2).'x'],
            ['Sold', number_format((float) $event['sold_fraction'] * 100, 0).'%'],
            ['SOL Returned', number_format((float) $event['sol_returned'], 4).' SOL'],
            ['P/L This Exit', sprintf('%+.4f SOL', (float) $event['realized_pnl_sol'])],
            ['Total Trade P/L', sprintf('%+.4f SOL', (float) $closed->trade_pnl_sol)],
            ['Strategy Return', sprintf('%+.2f%%', (float) $closed->strategy_return_percent)],
            ['Wallet Available', number_format((float) $wallet->available_balance_sol, 4).' SOL'],
        ]);

        if ($result['notification_error'] !== null) {
            $this->warn('Trade closed, but Telegram notification failed: '.$result['notification_error']);
        }

        return self::SUCCESS;
    }

    private function remainingFraction(PaperPosition $position): float
    {
        return $position->remaining_fraction !== null
            ? (float) $position->remaining_fraction
            : 1.0;
    }
}
