<?php

namespace App\Services\TradingEngine;

use App\Jobs\SubmitTradingEnginePaperObservation;
use App\Models\PaperPosition;
use App\Models\TradingEnginePaperObservation;
use App\Models\TradingEnginePaperPositionLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TradingEnginePaperLifecycleIntegration
{
    private const NETWORK_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public function __construct(
        private CanonicalDecimal $decimals,
        private TradingEngineCanonicalJson $canonicalJson,
        private TradingEnginePaperLifecycleEnrollment $enrollment,
    ) {}

    public function linkFor(PaperPosition $position): ?TradingEnginePaperPositionLink
    {
        if (! $this->enabled() && ! Schema::hasTable('trading_engine_paper_position_links')) {
            return null;
        }

        return TradingEnginePaperPositionLink::query()
            ->where('paper_position_id', $position->getKey())
            ->first();
    }

    /** @param array<string, mixed> $observation */
    public function submitValidated(
        PaperPosition $position,
        TradingEnginePaperPositionLink $link,
        array $observation,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        if ($link->ownership_state === 'pending_registration') {
            $this->enrollment->ensureRegistration($link);

            return;
        }

        if ($link->ownership_state !== 'registered' || $link->engine_position_id === null) {
            return;
        }

        $export = DB::transaction(function () use ($position, $link, $observation): ?TradingEnginePaperObservation {
            $locked = TradingEnginePaperPositionLink::query()->lockForUpdate()->find($link->getKey());

            if (! $locked instanceof TradingEnginePaperPositionLink
                || $locked->ownership_state !== 'registered'
                || $locked->engine_position_id === null
                || $locked->paper_position_id !== $position->getKey()
                || $locked->user_id !== $position->user_id
                || $locked->chain !== $position->chain->value
                || $locked->asset_address !== $position->address) {
                return null;
            }

            $unresolved = TradingEnginePaperObservation::query()
                ->where('position_link_id', $locked->getKey())
                ->whereIn('status', ['pending', 'submitted', 'failed'])
                ->orderByDesc('observation_sequence')
                ->first();

            if ($unresolved instanceof TradingEnginePaperObservation) {
                return $unresolved->status === 'pending' ? $unresolved : null;
            }

            $sequence = $locked->next_observation_sequence;
            $observationId = 'paper-position-'.$position->getKey().'-observation-'.$sequence;
            $checkedAt = (string) ($observation['checked_at'] ?? now()->toISOString());
            $payload = [
                'schema_version' => 1,
                'position_id' => $locked->engine_position_id,
                'source' => [
                    'paper_position_id' => (string) $position->getKey(),
                    'observation_id' => $observationId,
                    'sequence' => $sequence,
                ],
                'subject' => ['control_plane_user_id' => (string) $position->user_id],
                'network' => ['id' => self::NETWORK_ID],
                'asset' => ['address' => $position->address],
                'market' => array_filter([
                    'market_cap_usd' => $this->requiredDecimal($observation['market_cap'] ?? null),
                    'price_usd' => $this->decimals->normalize($observation['price_usd'] ?? null),
                    'liquidity_usd' => $this->decimals->normalize($observation['liquidity_usd'] ?? null),
                    'observed_at' => (string) ($observation['provider_observed_at'] ?? $checkedAt),
                    'fetched_at' => (string) ($observation['fetched_at'] ?? $checkedAt),
                    'provider' => (string) ($observation['provider'] ?? 'unknown'),
                ], fn (mixed $value): bool => $value !== null),
                'validation' => [
                    'status' => 'eligible',
                    'identity_verified' => true,
                    'simulation_allowed' => true,
                ],
            ];

            $created = TradingEnginePaperObservation::query()->create([
                'position_link_id' => $locked->getKey(),
                'paper_position_id' => $position->getKey(),
                'observation_id' => $observationId,
                'observation_sequence' => $sequence,
                'payload' => $payload,
                'payload_sha256' => $this->canonicalJson->hash($payload),
                'idempotency_key' => 'paper:position:observe:laravel:'.$position->getKey().':'.$sequence.':v1',
                'status' => 'pending',
            ]);

            $locked->increment('next_observation_sequence');

            return $created;
        }, 3);

        if ($export instanceof TradingEnginePaperObservation) {
            $this->dispatch($export);
        }
    }

    private function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true;
    }

    private function requiredDecimal(mixed $value): string
    {
        return $this->decimals->normalize($value)
            ?? throw new \LogicException('An eligible PAPER market observation is missing a required value.');
    }

    private function dispatch(TradingEnginePaperObservation $observation): void
    {
        try {
            SubmitTradingEnginePaperObservation::dispatch(
                $observation->getKey(),
                $observation->payload,
                $observation->idempotency_key,
            );
        } catch (Throwable) {
            $observation->forceFill(['error_code' => 'OBSERVATION_DISPATCH_FAILED'])->save();
        }
    }
}
