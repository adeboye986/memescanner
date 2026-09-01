<?php

namespace App\Services;

use App\Models\PaperPosition;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PaperTradeHistoryService
{
    /** @var array<string, array{label: string, filter: string, mode: string}> */
    private const EXIT_TYPES = [
        'manual_close' => ['label' => 'Manual Close', 'filter' => 'manual', 'mode' => 'Manual'],
        'stop_loss' => ['label' => 'Stop Loss (-10%)', 'filter' => 'stop-loss', 'mode' => 'Automatic'],
        'full_target_2x_profit' => ['label' => 'Full Target (+200% Profit)', 'filter' => 'full-target', 'mode' => 'Automatic'],
        'protected_floor_exit' => ['label' => 'Protected Floor (+100% Profit)', 'filter' => 'protected-floor', 'mode' => 'Automatic'],
        'tp_50' => ['label' => 'Legacy Take Profit', 'filter' => 'other', 'mode' => 'Automatic'],
        'tp_2x' => ['label' => 'Legacy 2x Take Profit', 'filter' => 'other', 'mode' => 'Automatic'],
        'trailing_stop' => ['label' => 'Legacy Trailing Stop', 'filter' => 'other', 'mode' => 'Automatic'],
    ];

    /**
     * @param  array{status: string, result: string, exit_type: string, chain: string}  $filters
     */
    public function paginate(array $filters, int $page, int $perPage = 24): LengthAwarePaginator
    {
        $query = PaperPosition::query()
            ->where('initial_investment_sol', '>', 0)
            ->orderByDesc('entry_at')
            ->orderByDesc('id');

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if ($filters['chain'] !== 'all') {
            $query->where('chain', $filters['chain']);
        }

        match ($filters['result']) {
            'wins' => $query->where('trade_pnl_sol', '>', 0),
            'losses' => $query->where('trade_pnl_sol', '<', 0),
            'break-even' => $query->where('trade_pnl_sol', 0),
            default => null,
        };

        $trades = $query->get()
            ->map(fn (PaperPosition $position): array => $this->present($position));

        if ($filters['exit_type'] !== 'all') {
            $trades = $trades
                ->filter(fn (array $trade): bool => $trade['exit_filter'] === $filters['exit_type'])
                ->values();
        }

        return new LengthAwarePaginator(
            $trades->forPage($page, $perPage)->values(),
            $trades->count(),
            $perPage,
            $page,
            ['path' => url()->current(), 'query' => $filters],
        );
    }

    /** @return array<string, mixed> */
    public function performanceSummary(): array
    {
        $closed = PaperPosition::query()
            ->where('initial_investment_sol', '>', 0)
            ->where('status', 'closed')
            ->get();

        $wins = $closed->filter(fn (PaperPosition $position): bool => (float) $position->trade_pnl_sol > 0);
        $losses = $closed->filter(fn (PaperPosition $position): bool => (float) $position->trade_pnl_sol < 0);
        $decidedCount = $wins->count() + $losses->count();
        $best = $closed->sortByDesc(fn (PaperPosition $position): float => (float) $position->trade_pnl_sol)->first();
        $worst = $closed->sortBy(fn (PaperPosition $position): float => (float) $position->trade_pnl_sol)->first();

        return [
            'total_trades' => $closed->count(),
            'wins' => $wins->count(),
            'losses' => $losses->count(),
            'break_even' => $closed->count() - $decidedCount,
            'win_rate' => $decidedCount > 0 ? ($wins->count() / $decidedCount) * 100 : null,
            'total_pnl' => (float) $closed->sum('trade_pnl_sol'),
            'average_pnl' => $closed->isNotEmpty() ? (float) $closed->avg('trade_pnl_sol') : null,
            'best_trade' => $best ? $this->summaryTrade($best) : null,
            'worst_trade' => $worst ? $this->summaryTrade($worst) : null,
            'exit_breakdown' => $this->exitBreakdown($closed),
        ];
    }

    /** @return array<string, mixed> */
    public function present(PaperPosition $position): array
    {
        $exit = $this->finalExit($position);
        $initialInvestment = (float) $position->initial_investment_sol;
        $returnPercent = $position->status === 'closed' && $initialInvestment > 0
            ? ((float) $position->trade_pnl_sol / $initialInvestment) * 100
            : null;
        $peakMultiple = (float) ($position->peak_multiple ?? 0);
        $finalMarketCap = $this->numericValue($exit['event']['observed_market_cap'] ?? null);

        if ($finalMarketCap === null && $position->status === 'closed') {
            $finalMarketCap = $this->numericValue($position->last_market_cap);
        }

        return [
            'model' => $position,
            'return_percent' => $returnPercent,
            'final_market_cap' => $finalMarketCap,
            'duration' => $position->entry_at && $position->closed_at
                ? $position->entry_at->diffForHumans($position->closed_at, true, false, 2)
                : null,
            'exit_reason' => $exit['label'],
            'exit_filter' => $exit['filter'],
            'exit_mode' => $exit['mode'],
            'highest_profit_percent' => $peakMultiple > 0 ? ($peakMultiple - 1) * 100 : null,
        ];
    }

    /**
     * @return array{event: array<string, mixed>, label: string, filter: string, mode: string}
     */
    private function finalExit(PaperPosition $position): array
    {
        $events = collect($position->exit_events ?? [])
            ->filter(fn (mixed $event): bool => is_array($event));
        $event = $events->last() ?? [];
        $type = (string) ($event['type'] ?? '');
        $definition = self::EXIT_TYPES[$type] ?? null;

        return [
            'event' => $event,
            'label' => $definition['label'] ?? 'Other / Unknown',
            'filter' => $definition['filter'] ?? 'other',
            'mode' => $definition['mode'] ?? 'N/A',
        ];
    }

    /** @return array{symbol: string, pnl: float, return_percent: ?float} */
    private function summaryTrade(PaperPosition $position): array
    {
        $initialInvestment = (float) $position->initial_investment_sol;

        return [
            'symbol' => $position->symbol ?: $position->address,
            'pnl' => (float) $position->trade_pnl_sol,
            'return_percent' => $initialInvestment > 0
                ? ((float) $position->trade_pnl_sol / $initialInvestment) * 100
                : null,
        ];
    }

    /**
     * @param  Collection<int, PaperPosition>  $positions
     * @return array<string, int>
     */
    private function exitBreakdown(Collection $positions): array
    {
        $breakdown = [
            'Manual Close' => 0,
            'Stop Loss (-10%)' => 0,
            'Full Target (+200% Profit)' => 0,
            'Protected Floor (+100% Profit)' => 0,
            'Other / Unknown' => 0,
        ];

        foreach ($positions as $position) {
            $label = $this->finalExit($position)['label'];
            $key = array_key_exists($label, $breakdown) ? $label : 'Other / Unknown';
            $breakdown[$key]++;
        }

        return $breakdown;
    }

    private function numericValue(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (float) $value;
    }
}
