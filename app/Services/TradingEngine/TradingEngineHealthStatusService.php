<?php

namespace App\Services\TradingEngine;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TradingEngineHealthStatusService
{
    private const CACHE_KEY = 'operations.trading_engine.health';

    private const LAST_RESPONSE_KEY = 'operations.trading_engine.last_successful_response';

    private const CACHE_SECONDS = 15;

    public function __construct(private TradingEngineClient $client) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        if (config('services.trading_engine.enabled', false) !== true) {
            return $this->disabledStatus();
        }

        $cached = $this->cache()->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $status = $this->inspect();
        $this->cache()->put(self::CACHE_KEY, $status, now()->addSeconds(self::CACHE_SECONDS));

        return $status;
    }

    /** @return array<string, mixed> */
    private function inspect(): array
    {
        $checkedAt = now()->toIso8601String();
        $lastSuccessfulResponseAt = $this->lastSuccessfulResponseAt();
        $live = null;
        $ready = null;

        try {
            $live = $this->client->liveness();
        } catch (Throwable) {
        }

        try {
            $ready = $this->client->readiness();
        } catch (Throwable) {
        }

        if (is_array($live) || is_array($ready)) {
            $lastSuccessfulResponseAt = $checkedAt;
            $this->cache()->forever(self::LAST_RESPONSE_KEY, $lastSuccessfulResponseAt);
        }

        return [
            'connectivity' => is_array($live) || is_array($ready) ? 'reachable' : 'unavailable',
            'liveness' => is_array($live) ? $live['status'] : 'unavailable',
            'readiness' => is_array($ready) ? $ready['status'] : 'unavailable',
            'postgres' => is_array($ready) && is_string(data_get($ready, 'dependencies.postgres'))
                ? data_get($ready, 'dependencies.postgres')
                : 'unknown',
            'workflow_leadership' => 'not_available',
            'workflow_leadership_detail' => 'The supported engine health contract does not expose workflow leadership.',
            'service' => $live['service'] ?? $ready['service'] ?? null,
            'version' => $live['version'] ?? $ready['version'] ?? null,
            'checked_at' => $checkedAt,
            'last_successful_response_at' => $lastSuccessfulResponseAt,
        ];
    }

    /** @return array<string, mixed> */
    private function disabledStatus(): array
    {
        return [
            'connectivity' => 'disabled',
            'liveness' => 'disabled',
            'readiness' => 'disabled',
            'postgres' => 'unknown',
            'workflow_leadership' => 'not_available',
            'workflow_leadership_detail' => 'The supported engine health contract does not expose workflow leadership.',
            'service' => null,
            'version' => null,
            'checked_at' => now()->toIso8601String(),
            'last_successful_response_at' => $this->lastSuccessfulResponseAt(),
        ];
    }

    private function lastSuccessfulResponseAt(): ?string
    {
        $value = $this->cache()->get(self::LAST_RESPONSE_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('services.operations.cache_store', 'file'));
    }
}
