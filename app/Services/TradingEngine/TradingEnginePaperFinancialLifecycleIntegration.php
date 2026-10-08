<?php

namespace App\Services\TradingEngine;

use App\Jobs\SubmitTradingEnginePaperFinancialObservation;
use App\Models\TradingEnginePaperFinancialObservation;
use App\Models\TradingEnginePaperPositionProjection;
use App\Models\TradingEnginePaperPositionState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class TradingEnginePaperFinancialLifecycleIntegration
{
    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public function __construct(
        private TradingEngineCanonicalJson $canonicalJson,
        private CanonicalDecimal $decimals,
    ) {}

    public function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_financial_lifecycle_enabled', false) === true;
    }

    /** @param array<string, mixed> $observation */
    public function submitValidated(
        TradingEnginePaperPositionProjection $position,
        array $observation,
    ): ?TradingEnginePaperFinancialObservation {
        if (! $this->enabled()) {
            return null;
        }

        if ($position->network_id !== self::SOLANA_MAINNET_ID
            || $position->state !== 'open'
            || $position->user_id === null
            || ($observation['simulation_allowed'] ?? false) !== true) {
            throw new LogicException('The engine PAPER position is not eligible for financial observation.');
        }

        return DB::transaction(function () use ($position, $observation): TradingEnginePaperFinancialObservation {
            $state = TradingEnginePaperPositionState::query()
                ->where('position_projection_id', $position->getKey())
                ->lockForUpdate()
                ->first();

            if (! $state instanceof TradingEnginePaperPositionState
                || $state->state !== 'open'
                || $state->position_projection_id !== $position->getKey()) {
                throw new LogicException('The engine PAPER lifecycle state is not open.');
            }

            $outstanding = TradingEnginePaperFinancialObservation::query()
                ->where('position_state_id', $state->getKey())
                ->whereIn('status', ['pending', 'submitted'])
                ->orderByDesc('sequence')
                ->first();

            if ($outstanding instanceof TradingEnginePaperFinancialObservation) {
                if ($outstanding->status === 'pending') {
                    DB::afterCommit(fn () => SubmitTradingEnginePaperFinancialObservation::dispatch($outstanding->getKey()));
                }

                return $outstanding;
            }

            $sequence = $state->next_observation_sequence;
            $observationId = 'engine-paper-position-'.$position->engine_position_id.'-observation-'.$sequence;
            $payload = [
                'schema_version' => 1,
                'position_id' => $position->engine_position_id,
                'source' => [
                    'system' => 'meme-scanner-laravel',
                    'observation_id' => $observationId,
                    'sequence' => $sequence,
                ],
                'subject' => ['control_plane_user_id' => (string) $position->user_id],
                'network' => ['id' => self::SOLANA_MAINNET_ID],
                'asset' => ['address' => $position->asset_address],
                'market' => array_filter([
                    'market_cap_usd' => $this->requiredDecimal($observation['market_cap'] ?? null),
                    'price_usd' => $this->requiredDecimal($observation['price_usd'] ?? null),
                    'liquidity_usd' => $this->decimals->normalize($observation['liquidity_usd'] ?? null),
                    'observed_at' => $this->timestamp($observation['provider_observed_at'] ?? $observation['checked_at'] ?? null),
                    'fetched_at' => $this->timestamp($observation['fetched_at'] ?? null),
                    'provider' => is_string($observation['provider'] ?? null)
                        ? mb_substr($observation['provider'], 0, 64)
                        : 'unknown',
                ], fn (mixed $value): bool => $value !== null),
                'validation' => [
                    'status' => 'eligible',
                    'identity_verified' => true,
                    'simulation_allowed' => true,
                ],
            ];
            $idempotencyKey = 'paper:financial-position:observe:'.$position->engine_position_id.':'.$sequence.':v1';
            $created = TradingEnginePaperFinancialObservation::query()->create([
                'position_state_id' => $state->getKey(),
                'user_id' => $position->user_id,
                'observation_id' => $observationId,
                'sequence' => $sequence,
                'idempotency_key' => $idempotencyKey,
                'payload_sha256' => $this->canonicalJson->hash($payload),
                'payload' => $payload,
                'status' => 'pending',
            ]);

            $state->forceFill(['next_observation_sequence' => $sequence + 1])->save();
            DB::afterCommit(fn () => SubmitTradingEnginePaperFinancialObservation::dispatch($created->getKey()));

            return $created;
        }, 3);
    }

    private function requiredDecimal(mixed $value): string
    {
        $decimal = $this->decimals->normalize($value);

        if ($decimal === null || $decimal === '0') {
            throw new LogicException('A positive canonical PAPER observation decimal is required.');
        }

        return $decimal;
    }

    private function timestamp(mixed $value): string
    {
        try {
            if (! is_string($value) || trim($value) === '') {
                throw new LogicException('A PAPER observation timestamp is required.');
            }

            return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
        } catch (Throwable $exception) {
            throw new LogicException('The PAPER observation timestamp is invalid.', 0, $exception);
        }
    }
}
