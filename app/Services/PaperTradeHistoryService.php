<?php

namespace App\Services;

use App\Models\PaperPosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PaperTradeHistoryService
{
    /** @var array<string, array{label: string, filter: string, mode: string}> */
    private const EXIT_TYPES = [
        'manual_close' => ['label' => 'Manual Close', 'filter' => 'manual', 'mode' => 'Manual'],
        'stop_loss' => ['label' => 'Stop Loss (-10%)', 'filter' => 'stop-loss', 'mode' => 'Automatic'],
        'full_target_2x_profit' => ['label' => 'Full Target (+200% Profit)', 'filter' => 'full-target', 'mode' => 'Automatic'],
        'protected_floor_exit' => ['label' => 'Protected Floor', 'filter' => 'protected-floor', 'mode' => 'Automatic'],
        'tp_50' => ['label' => 'Legacy Take Profit', 'filter' => 'other', 'mode' => 'Automatic'],
        'tp_2x' => ['label' => 'Legacy 2x Take Profit', 'filter' => 'other', 'mode' => 'Automatic'],
        'trailing_stop' => ['label' => 'Legacy Trailing Stop', 'filter' => 'other', 'mode' => 'Automatic'],
    ];

    /**
     * @param  array{status: string, result: string, exit_type: string, chain: string}  $filters
     */
    public function paginate(array $filters, int $page, int $perPage = 24, ?User $user = null): LengthAwarePaginator
    {
        $query = PaperPosition::query()
            ->when($user, fn ($query) => $this->scopeForUser($query, $user))
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
    public function performanceSummary(?User $user = null): array
    {
        $closed = PaperPosition::query()
            ->when($user, fn ($query) => $this->scopeForUser($query, $user))
            ->where('initial_investment_sol', '>', 0)
            ->where('status', 'closed')
            ->get();

        $wins = $closed->filter(
            fn (PaperPosition $position): bool => (float) $position->trade_pnl_sol > 0
        );

        $losses = $closed->filter(
            fn (PaperPosition $position): bool => (float) $position->trade_pnl_sol < 0
        );

        $decidedCount = $wins->count() + $losses->count();

        $best = $closed
            ->sortByDesc(
                fn (PaperPosition $position): float => (float) $position->trade_pnl_sol
            )
            ->first();

        $worst = $closed
            ->sortBy(
                fn (PaperPosition $position): float => (float) $position->trade_pnl_sol
            )
            ->first();

        return [
            'total_trades' => $closed->count(),
            'wins' => $wins->count(),
            'losses' => $losses->count(),
            'break_even' => $closed->count() - $decidedCount,
            'win_rate' => $decidedCount > 0
                ? ($wins->count() / $decidedCount) * 100
                : null,
            'total_pnl' => (float) $closed->sum('trade_pnl_sol'),
            'average_pnl' => $closed->isNotEmpty()
                ? (float) $closed->avg('trade_pnl_sol')
                : null,
            'best_trade' => $best ? $this->summaryTrade($best) : null,
            'worst_trade' => $worst ? $this->summaryTrade($worst) : null,
            'exit_breakdown' => $this->exitBreakdown($closed),
        ];
    }

    private function scopeForUser(Builder $query, User $user): void
    {
        $query->where(function ($query) use ($user): void {
            $query->where('user_id', $user->id);
            if ($user->is_admin) {
                $query->orWhereNull('user_id');
            }
        });
    }

    /** @return array<string, mixed> */
    public function present(PaperPosition $position): array
    {
        $exit = $this->finalExit($position);
        $event = $exit['event'];

        $initialInvestment = (float) $position->initial_investment_sol;

        $returnPercent =
            $position->status === 'closed'
            && $initialInvestment > 0
                ? ((float) $position->trade_pnl_sol / $initialInvestment) * 100
                : null;

        $peakMultiple = (float) ($position->peak_multiple ?? 0);

        $observedMarketCap = $this->numericValue(
            $event['observed_market_cap'] ?? null
        );

        $triggerMarketCap = $this->numericValue(
            $event['trigger_market_cap'] ?? null
        );

        $fillMarketCap = $this->numericValue(
            $event['fill_market_cap'] ?? null
        );

        $triggerMultiple = $this->numericValue(
            $event['trigger_multiple'] ?? null
        );

        $fillMultiple = $this->numericValue(
            $event['fill_multiple'] ?? null
        );

        /*
         * Backward compatibility for older exits recorded before
         * trigger/fill fields were introduced.
         */
        if (
            $observedMarketCap === null
            && $position->status === 'closed'
        ) {
            $observedMarketCap = $this->numericValue(
                $position->last_market_cap
            );
        }

        if (
            $fillMarketCap === null
            && $fillMultiple !== null
            && (float) $position->entry_market_cap > 0
        ) {
            $fillMarketCap =
                (float) $position->entry_market_cap * $fillMultiple;
        }

        if (
            $triggerMarketCap === null
            && $triggerMultiple !== null
            && (float) $position->entry_market_cap > 0
        ) {
            $triggerMarketCap =
                (float) $position->entry_market_cap * $triggerMultiple;
        }

        return [
            'model' => $position,

            'return_percent' => $returnPercent,

            'observed_market_cap' => $observedMarketCap,
            'trigger_market_cap' => $triggerMarketCap,
            'fill_market_cap' => $fillMarketCap,

            'trigger_multiple' => $triggerMultiple,
            'fill_multiple' => $fillMultiple,

            'duration' => $position->entry_at
                && $position->closed_at
                    ? $position->entry_at->diffForHumans(
                        $position->closed_at,
                        true,
                        false,
                        2
                    )
                    : null,

            'exit_reason' => $exit['label'],
            'exit_filter' => $exit['filter'],
            'exit_mode' => $exit['mode'],

            'highest_profit_percent' => $peakMultiple > 0
                    ? ($peakMultiple - 1) * 100
                    : null,
        ];
    }

    /**
     * @return array{
     *     event: array<string, mixed>,
     *     label: string,
     *     filter: string,
     *     mode: string
     * }
     */
    private function finalExit(PaperPosition $position): array
    {
        $events = collect($position->exit_events ?? [])
            ->filter(
                fn (mixed $event): bool => is_array($event)
            );

        $event = $events->last() ?? [];

        $type = (string) ($event['type'] ?? '');

        $definition = self::EXIT_TYPES[$type] ?? null;

        $label = $definition['label'] ?? 'Other / Unknown';

        /*
         * Protected-floor exits can now occur at either:
         *
         * +100% profit = 2.00x position value
         * +200% profit = 3.00x position value
         */
        if ($type === 'protected_floor_exit') {
            $protectedProfitPercent =
                $event['protected_floor_profit_percent'] ?? null;

            if (is_numeric($protectedProfitPercent)) {
                $label = sprintf(
                    'Protected Floor (+%d%% Profit)',
                    (int) $protectedProfitPercent
                );
            } else {
                /*
                 * Historical protected-floor exits were +100%.
                 */
                $label = 'Protected Floor (+100% Profit)';
            }
        }

        return [
            'event' => $event,
            'label' => $label,
            'filter' => $definition['filter'] ?? 'other',
            'mode' => $definition['mode'] ?? 'N/A',
        ];
    }

    /** @return array{symbol: string, pnl: float, return_percent: ?float} */
    private function summaryTrade(PaperPosition $position): array
    {
        $initialInvestment =
            (float) $position->initial_investment_sol;

        return [
            'symbol' => $position->symbol ?: $position->address,

            'pnl' => (float) $position->trade_pnl_sol,

            'return_percent' => $initialInvestment > 0
                    ? (
                        (float) $position->trade_pnl_sol
                        / $initialInvestment
                    ) * 100
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
            'Protected Floor (+200% Profit)' => 0,
            'Other / Unknown' => 0,
        ];

        foreach ($positions as $position) {
            $label = $this->finalExit($position)['label'];

            $key = array_key_exists($label, $breakdown)
                ? $label
                : 'Other / Unknown';

            $breakdown[$key]++;
        }

        return $breakdown;
    }

    private function numericValue(mixed $value): ?float
    {
        if (
            ! is_numeric($value)
            || (float) $value <= 0
        ) {
            return null;
        }

        return (float) $value;
    }
}
