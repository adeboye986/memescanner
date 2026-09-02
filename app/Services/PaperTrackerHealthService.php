<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PaperTrackerHealthService
{
    private const CACHE_KEY = 'paper-tracker.fast.health';

    /**
     * @param  array{open_positions: int, priced_positions: int, provider_failures: int, provider_requests: int, rate_limited: bool}  $metrics
     */
    public function recordCycle(array $metrics, float $durationMilliseconds): void
    {
        Cache::put(self::CACHE_KEY, [
            'last_successful_cycle' => now()->toIso8601String(),
            'last_successful_market_observation' => $metrics['priced_positions'] > 0
                ? now()->toIso8601String()
                : data_get($this->raw(), 'last_successful_market_observation'),
            'cycle_duration_ms' => round($durationMilliseconds, 2),
            ...$metrics,
        ], now()->addDay());
    }

    /** @return array<string, mixed>|null */
    public function raw(): ?array
    {
        $health = Cache::get(self::CACHE_KEY);

        return is_array($health) ? $health : null;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $health = $this->raw();
        $lastCycle = isset($health['last_successful_cycle'])
            ? Carbon::parse($health['last_successful_cycle'])
            : null;
        $staleAfter = max(2, (int) config('services.trading.paper_tracker_stale_seconds', 5));

        return [
            'status' => match (true) {
                $lastCycle === null => 'unknown',
                $lastCycle->gte(now()->subSeconds($staleAfter)) => 'active',
                default => 'stale',
            },
            'last_tracker_check' => $lastCycle,
            'last_successful_market_observation' => isset($health['last_successful_market_observation'])
                ? Carbon::parse($health['last_successful_market_observation'])
                : null,
            'cycle_duration_ms' => $health['cycle_duration_ms'] ?? null,
            'open_positions' => $health['open_positions'] ?? null,
            'priced_positions' => $health['priced_positions'] ?? null,
            'provider_failures' => $health['provider_failures'] ?? null,
            'provider_requests' => $health['provider_requests'] ?? null,
            'rate_limited' => $health['rate_limited'] ?? false,
        ];
    }
}
