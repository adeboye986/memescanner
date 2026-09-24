<?php

namespace App\Services;

use App\Models\PaperPosition;
use Illuminate\Contracts\Cache\Repository;
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
        $this->cache()->put(self::CACHE_KEY, [
            'last_cycle_attempt' => now()->toIso8601String(),
            'last_successful_cycle' => ($metrics['cycle_completed'] ?? true) ? now()->toIso8601String() : data_get($this->raw(), 'last_successful_cycle'),
            'last_successful_market_observation' => $metrics['priced_positions'] > 0
                ? now()->toIso8601String()
                : data_get($this->raw(), 'last_successful_market_observation'),
            'last_successful_provider_request' => ($metrics['provider_successes'] ?? 0) > 0 ? now()->toIso8601String() : data_get($this->raw(), 'last_successful_provider_request'),
            'cycle_duration_ms' => round($durationMilliseconds, 2),
            ...$metrics,
        ], now()->addDay());
    }

    public function recordProcessHeartbeat(): void
    {
        $this->cache()->put('paper-tracker.fast.heartbeat', now()->toIso8601String(), now()->addDay());
    }

    /** @return array<string, mixed>|null */
    public function raw(): ?array
    {
        $health = $this->cache()->get(self::CACHE_KEY);

        return is_array($health) ? $health : null;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $health = $this->raw();
        $lastCycle = isset($health['last_successful_cycle'])
            ? Carbon::parse($health['last_successful_cycle'])
            : null;
        $staleAfter = max(1, (int) config('services.trading.paper_tracker_stale_seconds', 30));

        $outstanding = PaperPosition::query()->where('status', 'open')->where('initial_investment_sol', '>', 0)
            ->where('meta->market_observation->status', 'unverified')->count();

        return [
            'metrics_scope' => 'last_completed_or_attempted_batch',
            'outstanding_unverified_positions' => $outstanding,
            'status' => match (true) {
                $lastCycle === null => $outstanding > 0 || isset($health['last_cycle_attempt']) ? 'degraded' : 'unknown',
                $lastCycle->lt(now()->subSeconds($staleAfter)) => 'stale',
                $outstanding > 0 || ! ($health['cycle_completed'] ?? true) || ($health['provider_failures'] ?? 0) > 0
                    || ($health['at_risk_positions'] ?? 0) > 0
                    || ($health['priced_positions'] ?? 0) < ($health['open_positions'] ?? 0) => 'degraded',
                default => 'active',
            },
            'process_lock_held' => $this->cache()->lock('paper-tracker.fast.process')->isLocked(),
            'last_process_heartbeat' => $this->cache()->get('paper-tracker.fast.heartbeat'),
            'last_cycle_attempt' => $health['last_cycle_attempt'] ?? null,
            'cycle_completed' => $health['cycle_completed'] ?? null,
            'at_risk_positions' => $health['at_risk_positions'] ?? 0,
            'last_tracker_check' => $lastCycle,
            'last_successful_market_observation' => isset($health['last_successful_market_observation'])
                ? Carbon::parse($health['last_successful_market_observation'])
                : null,
            'cycle_duration_ms' => $health['cycle_duration_ms'] ?? null,
            'open_positions' => $health['open_positions'] ?? null,
            'priced_positions' => $health['priced_positions'] ?? null,
            'provider_failures' => $health['provider_failures'] ?? null,
            'provider_requests' => $health['provider_requests'] ?? null,
            'last_successful_provider_request' => $health['last_successful_provider_request'] ?? null,
            'provider_successes' => $health['provider_successes'] ?? null,
            'rate_limited' => $health['rate_limited'] ?? false,
        ];
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('services.trading.paper_tracker_cache_store', 'file'));
    }
}
