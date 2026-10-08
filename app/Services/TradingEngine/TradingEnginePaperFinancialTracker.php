<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Models\TradingEnginePaperPositionState;
use App\Services\Chains\ChainManager;
use App\Services\PaperMarketObservation;
use Closure;
use Throwable;

class TradingEnginePaperFinancialTracker
{
    public function __construct(
        private PaperMarketObservation $marketObservations,
        private TradingEnginePaperFinancialLifecycleIntegration $lifecycles,
    ) {}

    /**
     * @return array{open_positions: int, priced_positions: int, provider_requests: int, provider_successes: int, provider_failures: int, rate_limited: bool}
     */
    public function track(ChainManager $chains, int $limit, ?Closure $heartbeat = null): array
    {
        $metrics = [
            'open_positions' => 0,
            'priced_positions' => 0,
            'provider_requests' => 0,
            'provider_successes' => 0,
            'provider_failures' => 0,
            'rate_limited' => false,
        ];

        if (! $this->lifecycles->enabled()) {
            return $metrics;
        }

        $states = TradingEnginePaperPositionState::query()
            ->with('position')
            ->where('state', 'open')
            ->orderBy('updated_at')
            ->limit(max(1, min($limit, 200)))
            ->get()
            ->filter(fn (TradingEnginePaperPositionState $state): bool => $state->position !== null);
        $metrics['open_positions'] = $states->count();

        foreach ($states->chunk(30) as $chunk) {
            $heartbeat?->__invoke();
            $metrics['provider_requests']++;

            try {
                $marketData = $chains->for(Chain::Solana)->marketDataMany(
                    $chunk->pluck('position.asset_address')->unique()->values()->all(),
                );
                $metrics['provider_successes']++;
            } catch (Throwable $exception) {
                $metrics['provider_failures']++;
                $metrics['rate_limited'] = $metrics['rate_limited'] || str_contains($exception->getMessage(), '429');

                continue;
            }

            foreach ($chunk as $state) {
                $heartbeat?->__invoke();
                $position = $state->position;

                if ($position === null) {
                    continue;
                }

                $observation = $this->marketObservations->evaluateEnginePosition(
                    $position,
                    $state,
                    $marketData[$position->asset_address] ?? [
                        'available' => false,
                        'reason' => 'no_valid_provider_observation',
                    ],
                );

                if (($observation['simulation_allowed'] ?? false) !== true) {
                    continue;
                }

                $this->lifecycles->submitValidated($position, $observation);
                $metrics['priced_positions']++;
            }
        }

        return $metrics;
    }
}
