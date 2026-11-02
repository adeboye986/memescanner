<?php

namespace App\Services;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\TradingEnginePaperExitSettlementProjection;
use App\Models\TradingEnginePaperPositionProjection;
use App\Models\TradingEnginePaperWalletProjection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TradingDashboardService
{
    public function __construct(private PaperTradeHistoryService $legacyHistory) {}

    /**
     * @param  array{tab: string, source: string, search: string, status: string, result: string, exit_type: string, chain: string}  $filters
     * @return array<string, mixed>
     */
    public function forUser(User $user, array $filters, int $page): array
    {
        $source = $filters['source'];
        $performance = $source === 'engine'
            ? $this->enginePerformance($user)
            : $this->legacyPerformance($user);
        $wallets = $source === 'engine'
            ? $this->engineWallets($user)
            : $this->legacyWallets($user);
        $openPositionCount = $source === 'engine'
            ? $this->engineOpenPositionQuery($user)->count()
            : $this->legacyPositionQuery($user)->where('status', 'open')->count();

        return [
            'source' => $source,
            'source_label' => $source === 'engine' ? 'Engine PAPER' : 'Legacy PAPER',
            'source_description' => $source === 'engine'
                ? 'Read-only projections owned by the TypeScript trading engine.'
                : 'Historical Laravel PAPER wallets and positions, including supported manual exits.',
            'wallets' => $wallets,
            'positions' => $filters['tab'] === 'positions'
                ? ($source === 'engine'
                    ? $this->enginePositions($user, $filters, $page)
                    : $this->legacyPositions($user, $filters, $page))
                : null,
            'history' => $filters['tab'] === 'history'
                ? ($source === 'engine'
                    ? $this->engineHistory($user, $filters, $page)
                    : $this->legacyHistory($user, $filters, $page))
                : null,
            'performance' => $performance,
            'summary' => [
                'portfolio' => $this->portfolioMetric($wallets, $source),
                'open_positions' => [
                    'value' => number_format($openPositionCount),
                    'detail' => $openPositionCount === 1 ? 'Open simulated position' : 'Open simulated positions',
                    'tone' => 'neutral',
                ],
                'realized_pnl' => $performance['realized_pnl_metric'],
                'win_rate' => [
                    'value' => $performance['win_rate'] === null
                        ? '—'
                        : number_format($performance['win_rate'], 1).'%',
                    'detail' => $performance['wins'].' / '.$performance['decided_trades'].' decided trades',
                    'tone' => 'neutral',
                ],
            ],
        ];
    }

    /**
     * @param  array{tab: string, source: string, search: string, status: string, result: string, exit_type: string, chain: string}  $filters
     */
    private function enginePositions(User $user, array $filters, int $page): LengthAwarePaginator
    {
        $paginator = $this->engineOpenPositionQuery($user)
            ->with(['wallet', 'lifecycleState', 'opportunity'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $this->applyEngineSearch($query, $filters['search']);
            })
            ->when(
                $filters['chain'] !== 'all',
                fn (Builder $query): Builder => $query->where('network_id', 'like', $filters['chain'].'%')
            )
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'page', $page)
            ->withQueryString();

        $paginator->setCollection($paginator->getCollection()->map(
            fn (TradingEnginePaperPositionProjection $position): array => $this->presentEnginePosition($position)
        ));

        return $paginator;
    }

    /**
     * @param  array{tab: string, source: string, search: string, status: string, result: string, exit_type: string, chain: string}  $filters
     */
    private function legacyPositions(User $user, array $filters, int $page): LengthAwarePaginator
    {
        $paginator = $this->legacyPositionQuery($user)
            ->where('status', 'open')
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $this->applyLegacySearch($query, $filters['search']);
            })
            ->when(
                $filters['chain'] !== 'all',
                fn (Builder $query): Builder => $query->where('chain', $filters['chain'])
            )
            ->orderByDesc('entry_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'page', $page)
            ->withQueryString();

        $paginator->setCollection($paginator->getCollection()->map(
            fn (PaperPosition $position): array => $this->presentLegacyPosition($position)
        ));

        return $paginator;
    }

    private function engineOpenPositionQuery(User $user): Builder
    {
        return TradingEnginePaperPositionProjection::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('lifecycleState', fn (Builder $state): Builder => $state->where('state', 'open'))
                    ->orWhere(function (Builder $fallback): void {
                        $fallback->whereDoesntHave('lifecycleState')->where('state', 'open');
                    });
            });
    }

    private function legacyPositionQuery(User $user): Builder
    {
        return PaperPosition::query()
            ->where('initial_investment_sol', '>', 0)
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id);

                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            });
    }

    /**
     * @param  array{tab: string, source: string, search: string, status: string, result: string, exit_type: string, chain: string}  $filters
     */
    private function engineHistory(User $user, array $filters, int $page): LengthAwarePaginator
    {
        $settlements = TradingEnginePaperExitSettlementProjection::query()
            ->with(['position.wallet', 'position.opportunity', 'state', 'wallet'])
            ->where('user_id', $user->id)
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $query->whereHas(
                    'position',
                    fn (Builder $position): mixed => $this->applyEngineSearch($position, $filters['search'])
                );
            })
            ->when($filters['chain'] !== 'all', function (Builder $query) use ($filters): void {
                $query->whereHas(
                    'position',
                    fn (Builder $position): Builder => $position->where('network_id', 'like', $filters['chain'].'%')
                );
            })
            ->orderByDesc('settled_at')
            ->orderByDesc('id')
            ->get()
            ->map(
                fn (TradingEnginePaperExitSettlementProjection $settlement): array => $this->presentEngineHistory($settlement)
            );

        return $this->collectionPaginator(
            $this->filterResults($settlements, $filters['result']),
            $page
        );
    }

    /**
     * @param  array{tab: string, source: string, search: string, status: string, result: string, exit_type: string, chain: string}  $filters
     */
    private function legacyHistory(User $user, array $filters, int $page): LengthAwarePaginator
    {
        $positions = $this->legacyPositionQuery($user)
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $this->applyLegacySearch($query, $filters['search']);
            })
            ->when(
                $filters['status'] !== 'all',
                fn (Builder $query): Builder => $query->where('status', $filters['status'])
            )
            ->when(
                $filters['chain'] !== 'all',
                fn (Builder $query): Builder => $query->where('chain', $filters['chain'])
            )
            ->orderByDesc('entry_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (PaperPosition $position): array {
                return $this->presentLegacyHistory($position, $this->legacyHistory->present($position));
            });

        $positions = $this->filterResults($positions, $filters['result']);

        if ($filters['exit_type'] !== 'all') {
            $positions = $positions
                ->filter(fn (array $position): bool => $position['exit_filter'] === $filters['exit_type'])
                ->values();
        }

        return $this->collectionPaginator($positions, $page);
    }

    private function applyEngineSearch(Builder $query, string $term): void
    {
        $search = '%'.$term.'%';
        $query->where(function (Builder $searchQuery) use ($search): void {
            $searchQuery
                ->where('symbol', 'like', $search)
                ->orWhere('asset_address', 'like', $search);
        });
    }

    private function applyLegacySearch(Builder $query, string $term): void
    {
        $search = '%'.$term.'%';
        $query->where(function (Builder $searchQuery) use ($search): void {
            $searchQuery
                ->where('symbol', 'like', $search)
                ->orWhere('address', 'like', $search);
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function engineWallets(User $user): Collection
    {
        return TradingEnginePaperWalletProjection::query()
            ->where('user_id', $user->id)
            ->orderBy('network_id')
            ->get()
            ->map(fn (TradingEnginePaperWalletProjection $wallet): array => [
                'network' => $this->networkLabel($wallet->network_id),
                'currency' => $wallet->currency,
                'available' => (float) $wallet->available_balance_native,
                'invested' => (float) $wallet->invested_balance_native,
                'realized_pnl' => (float) $wallet->realized_pnl_native,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function legacyWallets(User $user): Collection
    {
        return PaperWallet::query()
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id);

                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            })
            ->orderBy('chain')
            ->orderBy('id')
            ->get()
            ->map(fn (PaperWallet $wallet): array => [
                'network' => $wallet->chain->label(),
                'currency' => $wallet->currencyCode(),
                'available' => (float) $wallet->available_balance_sol,
                'invested' => (float) $wallet->invested_balance_sol,
                'realized_pnl' => (float) $wallet->realized_pnl_sol,
            ]);
    }

    /** @return array<string, mixed> */
    private function enginePerformance(User $user): array
    {
        $trades = TradingEnginePaperExitSettlementProjection::query()
            ->with(['position.wallet', 'wallet'])
            ->where('user_id', $user->id)
            ->orderByDesc('settled_at')
            ->get()
            ->map(function (TradingEnginePaperExitSettlementProjection $settlement): array {
                $costBasis = (float) $settlement->cost_basis_native;
                $pnl = (float) $settlement->realized_pnl_native;

                return [
                    'symbol' => $settlement->position->symbol ?: $settlement->position->asset_address,
                    'pnl' => $pnl,
                    'currency' => $settlement->wallet?->currency ?? $settlement->position->wallet?->currency ?? 'native',
                    'return_percent' => $costBasis > 0 ? ($pnl / $costBasis) * 100 : null,
                ];
            });

        return $this->performanceSummary($trades, null);
    }

    /** @return array<string, mixed> */
    private function legacyPerformance(User $user): array
    {
        $trades = $this->legacyPositionQuery($user)
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->get()
            ->map(function (PaperPosition $position): array {
                $costBasis = (float) $position->initial_investment_sol;
                $pnl = (float) $position->trade_pnl_sol;

                return [
                    'symbol' => $position->symbol ?: $position->address,
                    'pnl' => $pnl,
                    'currency' => $this->currencyForChain($position->chain),
                    'return_percent' => $costBasis > 0 ? ($pnl / $costBasis) * 100 : null,
                    'exit_reason' => $this->legacyHistory->present($position)['exit_reason'],
                ];
            });

        return $this->performanceSummary(
            $trades,
            $trades->countBy(fn (array $trade): string => $trade['exit_reason'])->sortKeys()->all()
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $trades
     * @param  array<string, int>|null  $exitBreakdown
     * @return array<string, mixed>
     */
    private function performanceSummary(Collection $trades, ?array $exitBreakdown): array
    {
        $wins = $trades->where('pnl', '>', 0)->count();
        $losses = $trades->where('pnl', '<', 0)->count();
        $decidedTrades = $wins + $losses;

        return [
            'total_trades' => $trades->count(),
            'wins' => $wins,
            'losses' => $losses,
            'break_even' => $trades->count() - $decidedTrades,
            'decided_trades' => $decidedTrades,
            'win_rate' => $decidedTrades > 0 ? ($wins / $decidedTrades) * 100 : null,
            'realized_pnl_metric' => $this->currencyMetric($trades, 'pnl', 'No settled trades reported'),
            'average_pnl_metric' => $this->averageCurrencyMetric($trades),
            'best_trade' => $trades->sortByDesc('pnl')->first(),
            'worst_trade' => $trades->sortBy('pnl')->first(),
            'exit_breakdown' => $exitBreakdown,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $wallets
     * @return array{value: string, detail: string, tone: string}
     */
    private function portfolioMetric(Collection $wallets, string $source): array
    {
        $records = $wallets->map(fn (array $wallet): array => [
            'currency' => $wallet['currency'],
            'amount' => $wallet['available'] + $wallet['invested'],
        ]);

        $metric = $this->currencyMetric(
            $records,
            'amount',
            $source === 'engine' ? 'No engine wallet projection' : 'No legacy PAPER wallet'
        );

        $metric['value'] = ltrim($metric['value'], '+');

        return $metric;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array{value: string, detail: string, tone: string}
     */
    private function currencyMetric(Collection $records, string $amountKey, string $emptyDetail): array
    {
        if ($records->isEmpty()) {
            return ['value' => '—', 'detail' => $emptyDetail, 'tone' => 'neutral'];
        }

        $currencies = $records->pluck('currency')->filter()->unique()->values();

        if ($currencies->count() !== 1) {
            return ['value' => '—', 'detail' => 'Separate native-currency ledgers', 'tone' => 'neutral'];
        }

        $amount = (float) $records->sum($amountKey);

        return [
            'value' => $this->formatNative($amount).' '.$currencies->first(),
            'detail' => $records->count() === 1
                ? 'Authoritative source ledger'
                : $records->count().' same-currency ledgers',
            'tone' => $amount > 0 ? 'positive' : ($amount < 0 ? 'negative' : 'neutral'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $trades
     * @return array{value: string, detail: string, tone: string}
     */
    private function averageCurrencyMetric(Collection $trades): array
    {
        if ($trades->isEmpty()) {
            return ['value' => '—', 'detail' => 'No settled trades reported', 'tone' => 'neutral'];
        }

        $currencies = $trades->pluck('currency')->filter()->unique()->values();

        if ($currencies->count() !== 1) {
            return ['value' => '—', 'detail' => 'Separate native-currency ledgers', 'tone' => 'neutral'];
        }

        $average = (float) $trades->avg('pnl');

        return [
            'value' => $this->formatNative($average).' '.$currencies->first(),
            'detail' => 'Average settled P/L',
            'tone' => $average > 0 ? 'positive' : ($average < 0 ? 'negative' : 'neutral'),
        ];
    }

    /** @return array<string, mixed> */
    private function presentEnginePosition(TradingEnginePaperPositionProjection $position): array
    {
        $state = $position->lifecycleState;
        $observedPrice = $this->positiveNumber($state?->last_price_usd);
        $observation = $this->observationState($observedPrice, $state?->last_observed_at);
        $multiple = $observation['state'] === 'fresh'
            ? $this->positiveNumber($state?->observed_multiple)
            : null;
        $costBasis = (float) $position->cost_basis_native;
        $currentValue = $multiple !== null ? $costBasis * $multiple : null;

        return [
            'id' => $position->id,
            'symbol' => $position->symbol ?: 'Unknown token',
            'address' => $position->asset_address,
            'network' => $this->networkLabel($position->network_id),
            'currency' => $position->wallet?->currency ?? 'native',
            'amount' => $this->trimDecimal($position->quantity).' '.str_replace('_', ' ', $position->quantity_unit),
            'entry_price' => $this->formatUsd($this->positiveNumber($position->entry_price_usd)),
            'observed_price' => $this->formatUsd($observedPrice),
            'observation' => $observation,
            'pnl' => $currentValue !== null ? $currentValue - $costBasis : null,
            'pnl_percent' => $multiple !== null ? ($multiple - 1) * 100 : null,
            'current_value' => $currentValue,
            'remaining' => '100%',
            'cost_basis' => $costBasis,
            'opened_at' => $position->opened_at,
            'entry_market_cap' => $this->positiveNumber($position->entry_market_cap_usd),
            'last_market_cap' => $this->positiveNumber($state?->last_market_cap_usd),
            'protection' => str_replace('_', ' ', $state?->protection_state ?? 'none'),
            'strategy' => $position->strategy_snapshot ?? [],
            'opportunity_id' => $position->trade_opportunity_id,
            'manual_close' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function presentLegacyPosition(PaperPosition $position): array
    {
        $observedPrice = $this->positiveNumber($position->last_price);
        $observation = $this->observationState($observedPrice, $position->last_checked_at);
        $entryMarketCap = $this->positiveNumber($position->entry_market_cap);
        $lastMarketCap = $this->positiveNumber($position->last_market_cap);
        $multiple = $observation['state'] === 'fresh' && $entryMarketCap !== null && $lastMarketCap !== null
            ? $lastMarketCap / $entryMarketCap
            : null;
        $remainingFraction = $position->remaining_fraction === null ? 1.0 : (float) $position->remaining_fraction;
        $costBasis = (float) ($position->remaining_investment_sol ?? 0);

        if ($costBasis <= 0 && $remainingFraction > 0) {
            $costBasis = (float) $position->initial_investment_sol * $remainingFraction;
        }

        $currentValue = $multiple !== null ? $costBasis * $multiple : null;

        return [
            'id' => $position->id,
            'symbol' => $position->symbol ?: 'Unknown token',
            'address' => $position->address,
            'network' => $position->chain->label(),
            'currency' => $this->currencyForChain($position->chain),
            'amount' => number_format($remainingFraction * 100, 1).'% remaining',
            'entry_price' => $this->formatUsd($this->positiveNumber($position->entry_price)),
            'observed_price' => $this->formatUsd($observedPrice),
            'observation' => $observation,
            'pnl' => $currentValue !== null ? $currentValue - $costBasis : null,
            'pnl_percent' => $multiple !== null ? ($multiple - 1) * 100 : null,
            'current_value' => $currentValue,
            'remaining' => number_format($remainingFraction * 100, 1).'%',
            'cost_basis' => $costBasis,
            'opened_at' => $position->entry_at,
            'entry_market_cap' => $entryMarketCap,
            'last_market_cap' => $lastMarketCap,
            'protection' => match (true) {
                (bool) $position->tp_2x_hit => 'Level 2 protected',
                (bool) $position->tp_50_hit => 'Level 1 protected',
                default => 'Unprotected',
            },
            'strategy' => $position->strategy_snapshot ?? [],
            'opportunity_id' => null,
            'manual_close' => true,
            'close_url' => route('paper-trades.close', $position),
        ];
    }

    /** @return array<string, mixed> */
    private function presentEngineHistory(TradingEnginePaperExitSettlementProjection $settlement): array
    {
        $position = $settlement->position;
        $costBasis = (float) $settlement->cost_basis_native;
        $pnl = (float) $settlement->realized_pnl_native;

        return [
            'symbol' => $position->symbol ?: 'Unknown token',
            'address' => $position->asset_address,
            'network' => $this->networkLabel($position->network_id),
            'currency' => $settlement->wallet?->currency ?? $position->wallet?->currency ?? 'native',
            'entry_price' => $this->formatUsd($this->positiveNumber($position->entry_price_usd)),
            'exit_price' => $this->formatUsd($this->positiveNumber($settlement->exit_price_usd)),
            'cost_basis' => $costBasis,
            'proceeds' => (float) $settlement->proceeds_native,
            'pnl' => $pnl,
            'entry_market_cap' => $this->positiveNumber($position->entry_market_cap_usd),
            'observed_market_cap' => $this->positiveNumber($settlement->exit_market_cap_usd),
            'fill_multiple' => $this->positiveNumber($settlement->observed_multiple),
            'evidence_label' => str_replace('_', ' ', $settlement->fill_model),
            'return_percent' => $costBasis > 0 ? ($pnl / $costBasis) * 100 : null,
            'result' => $this->resultForPnl($pnl),
            'status' => 'closed',
            'exit_reason' => 'Engine settlement',
            'exit_filter' => 'all',
            'settled_at' => $settlement->settled_at,
            'opportunity_id' => $position->trade_opportunity_id,
        ];
    }

    /** @param  array<string, mixed>  $trade */
    private function presentLegacyHistory(PaperPosition $position, array $trade): array
    {
        $costBasis = (float) $position->initial_investment_sol;
        $pnl = (float) $position->trade_pnl_sol;

        return [
            'model' => $position,
            'symbol' => $position->symbol ?: 'Unknown token',
            'address' => $position->address,
            'network' => $position->chain->label(),
            'currency' => $this->currencyForChain($position->chain),
            'entry_price' => $this->formatUsd($this->positiveNumber($position->entry_price)),
            'exit_price' => $this->formatUsd($this->positiveNumber($position->last_price)),
            'cost_basis' => $costBasis,
            'proceeds' => $position->status === 'closed' ? $costBasis + $pnl : null,
            'pnl' => $pnl,
            'entry_market_cap' => $this->positiveNumber($position->entry_market_cap),
            'observed_market_cap' => $trade['observed_market_cap'],
            'trigger_market_cap' => $trade['trigger_market_cap'],
            'fill_market_cap' => $trade['fill_market_cap'],
            'fill_multiple' => $trade['fill_multiple'],
            'evidence_label' => $trade['exit_mode'],
            'return_percent' => $trade['return_percent'],
            'result' => $this->resultForPnl($pnl),
            'status' => $position->status,
            'exit_reason' => $trade['exit_reason'],
            'exit_filter' => $trade['exit_filter'],
            'settled_at' => $position->closed_at,
            'opportunity_id' => null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return Collection<int, array<string, mixed>>
     */
    private function filterResults(Collection $records, string $result): Collection
    {
        return match ($result) {
            'wins' => $records->where('pnl', '>', 0)->values(),
            'losses' => $records->where('pnl', '<', 0)->values(),
            'break-even' => $records->where('pnl', 0)->values(),
            default => $records,
        };
    }

    /** @param  Collection<int, array<string, mixed>>  $records */
    private function collectionPaginator(Collection $records, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $records->forPage($page, 15)->values(),
            $records->count(),
            15,
            $page,
            ['path' => url()->current(), 'query' => request()->query()],
        );
    }

    /** @return array{state: string, label: string, observed_at: mixed} */
    private function observationState(?float $value, mixed $observedAt): array
    {
        if ($value === null) {
            return ['state' => 'unavailable', 'label' => 'Not reported', 'observed_at' => $observedAt];
        }

        $staleAfterSeconds = max(1, (int) config('services.trading.paper_tracker_stale_seconds', 30));
        $isFresh = $observedAt !== null && $observedAt->gte(now()->subSeconds($staleAfterSeconds));

        return [
            'state' => $isFresh ? 'fresh' : 'stale',
            'label' => $isFresh ? 'Current observation' : 'Stale observation',
            'observed_at' => $observedAt,
        ];
    }

    private function networkLabel(string $networkId): string
    {
        return match (true) {
            str_starts_with(strtolower($networkId), 'solana') => 'Solana',
            str_starts_with(strtolower($networkId), 'ethereum') => 'Ethereum',
            default => $networkId,
        };
    }

    private function currencyForChain(Chain $chain): string
    {
        return $chain === Chain::Ethereum ? 'ETH' : 'SOL';
    }

    private function resultForPnl(float $pnl): string
    {
        return match (true) {
            $pnl > 0 => 'Win',
            $pnl < 0 => 'Loss',
            default => 'Break-even',
        };
    }

    private function positiveNumber(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function formatUsd(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        $precision = $value < 0.01 ? 8 : ($value < 1 ? 6 : 2);

        return '$'.number_format($value, $precision);
    }

    private function formatNative(float $value): string
    {
        return ($value > 0 ? '+' : '').number_format($value, 4);
    }

    private function trimDecimal(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
