<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Exceptions\TradingEngineProjectionException;
use App\Models\PaperPosition;
use App\Models\PaperPositionSnapshot;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEnginePaperExitSettlement;
use App\Models\TradingEnginePaperLifecycleDecision;
use App\Models\TradingEnginePaperObservation;
use App\Models\TradingEnginePaperPositionLink;
use App\Services\PaperWalletService;

class TradingEnginePaperLifecycleProjector
{
    private const NETWORK_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public function __construct(
        private TradingEngineCanonicalJson $canonicalJson,
        private PaperWalletService $wallets,
    ) {}

    public function project(TradingEngineEvent $event): void
    {
        match ($event->event_type) {
            'paper.position.recorded.v1' => $this->projectRecorded($event),
            'paper.position.evaluated.v1' => $this->projectEvaluated($event),
            'paper.exit.requested.v1' => $this->projectExit($event),
            default => null,
        };
    }

    private function projectRecorded(TradingEngineEvent $event): void
    {
        $payload = $this->payload($event);
        $source = $payload['source'];
        $position = PaperPosition::query()->lockForUpdate()->find($source['paper_position_id']);
        $opportunity = TradeOpportunity::query()->find($source['trade_opportunity_id']);
        $link = TradingEnginePaperPositionLink::query()
            ->where('paper_position_id', $source['paper_position_id'])
            ->lockForUpdate()
            ->first();

        if (! $position instanceof PaperPosition
            || ! $opportunity instanceof TradeOpportunity
            || ! $link instanceof TradingEnginePaperPositionLink
            || $position->user_id === null
            || (string) $position->user_id !== $payload['subject']['control_plane_user_id']
            || $opportunity->user_id !== $position->user_id
            || $link->trade_opportunity_id !== $opportunity->getKey()
            || $link->user_id !== $position->user_id
            || $link->engine_opportunity_id !== $source['engine_opportunity_id']
            || $link->chain !== Chain::Solana->value
            || $position->chain !== Chain::Solana
            || $link->asset_address !== $payload['asset']['address']
            || $position->address !== $payload['asset']['address']
            || $event->aggregate_id !== $payload['position_id']
            || $event->aggregate_version !== 1
            || $event->idempotency_key !== $link->registration_idempotency_key
            || ! $this->registrationMatches($link, $payload)) {
            $this->reject('PAPER_POSITION_LINK_IDENTITY_MISMATCH');
        }

        if ($link->engine_position_id !== null && $link->engine_position_id !== $payload['position_id']) {
            $this->reject('PAPER_POSITION_LINK_CONFLICT');
        }

        $link->forceFill([
            'engine_position_id' => $payload['position_id'],
            'ownership_state' => 'registered',
            'registration_error_code' => null,
            'registered_at' => $event->occurred_at,
        ])->save();
    }

    private function projectEvaluated(TradingEngineEvent $event): void
    {
        $payload = $this->payload($event);
        [$link, $position, $observation] = $this->correlatedState($payload, true);
        $existing = TradingEnginePaperLifecycleDecision::query()
            ->where('event_id', $event->event_id)
            ->orWhere('decision_id', $payload['decision_id'])
            ->orWhere(function ($query) use ($link, $payload): void {
                $query->where('position_link_id', $link->getKey())
                    ->where('lifecycle_version', $payload['lifecycle_version']);
            })
            ->first();

        if ($existing instanceof TradingEnginePaperLifecycleDecision) {
            if ($existing->event_id !== $event->event_id
                || $existing->decision_id !== $payload['decision_id']
                || $existing->payload_sha256 !== $event->payload_sha256) {
                $this->reject('PAPER_LIFECYCLE_DECISION_CONFLICT');
            }

            return;
        }

        $expectedVersion = ((int) TradingEnginePaperLifecycleDecision::query()
            ->where('position_link_id', $link->getKey())
            ->max('lifecycle_version')) + 1;

        if ($payload['lifecycle_version'] !== $expectedVersion
            || $payload['source']['sequence'] !== $expectedVersion
            || $event->aggregate_id !== $payload['position_id']
            || $event->aggregate_version !== $expectedVersion
            || $event->idempotency_key !== $observation->idempotency_key) {
            $this->reject('PAPER_LIFECYCLE_VERSION_MISMATCH');
        }

        $decision = TradingEnginePaperLifecycleDecision::query()->create([
            'position_link_id' => $link->getKey(),
            'observation_id' => $observation->getKey(),
            'event_id' => $event->event_id,
            'decision_id' => $payload['decision_id'],
            'lifecycle_version' => $payload['lifecycle_version'],
            'decision' => $payload['decision'],
            'exit_type' => $payload['exit_type'],
            'observed_multiple' => $payload['observed_multiple'],
            'trigger_multiple' => $payload['trigger_multiple'],
            'observed_market_cap' => $payload['market']['market_cap_usd'],
            'observed_price' => $payload['market']['price_usd'] ?? null,
            'observed_liquidity' => $payload['market']['liquidity_usd'] ?? null,
            'peak_market_cap' => $payload['peak_market_cap_usd'],
            'peak_multiple' => $payload['peak_multiple'],
            'drawdown_percent' => $payload['drawdown_percent'],
            'protection_before' => $payload['protection_before'],
            'protection_after' => $payload['protection_after'],
            'transitions' => $payload['transitions'],
            'payload' => $payload,
            'payload_sha256' => $event->payload_sha256,
            'occurred_at' => $event->occurred_at,
            'projected_at' => now(),
        ]);

        $observation->forceFill([
            'status' => 'evaluated',
            'engine_decision_id' => $decision->decision_id,
            'engine_decision' => $decision->decision,
            'error_code' => null,
            'recovery_token' => null,
            'recovered_at' => $observation->recovery_previous_error_code !== null
                ? ($observation->recovered_at ?? now())
                : null,
        ])->save();

        $position->forceFill([
            'meta' => array_replace($position->meta ?? [], [
                'trading_engine_lifecycle' => [
                    'owner' => 'trading-engine',
                    'engine_position_id' => $link->engine_position_id,
                    'lifecycle_version' => $decision->lifecycle_version,
                    'last_decision' => $decision->decision,
                    'last_event_id' => $event->event_id,
                ],
            ]),
            'last_market_cap' => $decision->observed_market_cap,
            'last_price' => $decision->observed_price,
            'last_checked_at' => $event->occurred_at,
            'peak_market_cap' => $decision->peak_market_cap,
            'peak_multiple' => $decision->peak_multiple,
            'max_drawdown_percent' => min(
                (float) ($position->max_drawdown_percent ?? 0),
                (float) $decision->drawdown_percent,
            ),
            'tp_50_hit' => in_array($decision->protection_after, ['level_1', 'level_2'], true),
            'tp_2x_hit' => $decision->protection_after === 'level_2',
        ])->save();

        PaperPositionSnapshot::query()->create([
            'paper_position_id' => $position->getKey(),
            'snapshot_type' => $decision->decision === 'EXIT' ? 'engine_exit_requested' : 'engine_lifecycle',
            'market_cap' => $decision->observed_market_cap,
            'price' => $decision->observed_price,
            'liquidity' => $decision->observed_liquidity,
            'return_percent' => ((float) $decision->observed_multiple - 1) * 100,
            'multiple' => $decision->observed_multiple,
            'drawdown_from_peak_percent' => $decision->drawdown_percent,
            'raw_data' => ['trading_engine_lifecycle' => $payload],
            'recorded_at' => $event->occurred_at,
        ]);
    }

    private function projectExit(TradingEngineEvent $event): void
    {
        $payload = $this->payload($event);
        [$link, $position] = $this->correlatedState($payload, false);
        $decision = TradingEnginePaperLifecycleDecision::query()
            ->where('position_link_id', $link->getKey())
            ->where('decision_id', $payload['decision_id'])
            ->where('event_id', $payload['evaluated_event_id'])
            ->first();

        if (! $decision instanceof TradingEnginePaperLifecycleDecision) {
            throw new TradingEngineProjectionException(
                'PAPER_LIFECYCLE_DECISION_PENDING',
                'The causal PAPER lifecycle decision has not been projected.',
                true,
            );
        }

        $evaluatedPayload = $payload;
        unset($evaluatedPayload['evaluated_event_id'], $evaluatedPayload['result_sha256']);

        if ($decision->decision !== 'EXIT'
            || $event->causation_id !== $decision->event_id
            || $event->aggregate_id !== $payload['position_id']
            || $event->aggregate_version !== $decision->lifecycle_version
            || $event->idempotency_key !== 'paper:exit:'.$link->engine_position_id.':'.$decision->lifecycle_version
            || $decision->payload_sha256 !== $payload['result_sha256']
            || $this->canonicalJson->hash($evaluatedPayload) !== $payload['result_sha256']) {
            $this->reject('PAPER_EXIT_DECISION_MISMATCH');
        }

        $existing = TradingEnginePaperExitSettlement::query()
            ->where('exit_event_id', $event->event_id)
            ->orWhere('lifecycle_decision_id', $decision->getKey())
            ->orWhere('paper_position_id', $position->getKey())
            ->first();

        if ($existing instanceof TradingEnginePaperExitSettlement) {
            if ($existing->exit_event_id !== $event->event_id
                || $existing->lifecycle_decision_id !== $decision->getKey()
                || $existing->paper_position_id !== $position->getKey()) {
                $this->reject('PAPER_EXIT_SETTLEMENT_CONFLICT');
            }

            return;
        }

        $position = PaperPosition::query()->whereKey($position->getKey())->lockForUpdate()->firstOrFail();

        if ($position->status !== 'open' || (float) $position->remaining_fraction <= 0) {
            $this->reject('PAPER_POSITION_NOT_SETTLEABLE');
        }

        $user = $position->user;

        if ($user === null) {
            $this->reject('PAPER_WALLET_NOT_FOUND');
        }

        $wallet = $this->wallets->lockedForUser($user, $position->chain);
        $fillMultiple = (float) $decision->observed_multiple;
        $remainingFraction = (float) $position->remaining_fraction;
        $costBasis = (float) ($position->remaining_investment_sol ?? 0);

        if ($costBasis <= 0) {
            $costBasis = (float) $position->initial_investment_sol * $remainingFraction;
        }

        $proceeds = $costBasis * $fillMultiple;
        $pnl = $proceeds - $costBasis;
        $realizedValue = (float) ($position->realized_value_multiple ?? 0)
            + ($remainingFraction * $fillMultiple);
        $exitEvents = $position->exit_events ?? [];
        $exitEvents[] = [
            'type' => $decision->exit_type,
            'source' => 'trading-engine',
            'engine_exit_event_id' => $event->event_id,
            'engine_decision_id' => $decision->decision_id,
            'sold_fraction' => $remainingFraction,
            'trigger_multiple' => $decision->trigger_multiple !== null ? (float) $decision->trigger_multiple : null,
            'fill_multiple' => $fillMultiple,
            'observed_multiple' => $fillMultiple,
            'observed_market_cap' => (float) $decision->observed_market_cap,
            'cost_basis_sol' => round($costBasis, 8),
            'sol_returned' => round($proceeds, 8),
            'realized_pnl_sol' => round($pnl, 8),
            'wallet_applied' => true,
            'execution_verified' => false,
            'fill_model' => 'observed_mark_without_slippage_or_depth',
            'triggered_at' => $event->occurred_at->toIso8601String(),
        ];

        $wallet->forceFill([
            'available_balance_sol' => (float) $wallet->available_balance_sol + $proceeds,
            'invested_balance_sol' => max(0, (float) $wallet->invested_balance_sol - $costBasis),
            'realized_pnl_sol' => (float) $wallet->realized_pnl_sol + $pnl,
        ])->save();

        $position->forceFill([
            'remaining_fraction' => 0,
            'remaining_investment_sol' => 0,
            'realized_value_multiple' => $realizedValue,
            'strategy_value_multiple' => $realizedValue,
            'strategy_return_percent' => ($realizedValue - 1) * 100,
            'stop_loss_hit' => $decision->exit_type === 'stop_loss',
            'trailing_stop_hit' => $decision->exit_type === 'protected_floor_exit',
            'exit_events' => $exitEvents,
            'realized_sol' => (float) $position->realized_sol + $proceeds,
            'trade_pnl_sol' => (float) $position->trade_pnl_sol + $pnl,
            'status' => 'closed',
            'closed_at' => $event->occurred_at,
        ])->save();

        TradingEnginePaperExitSettlement::query()->create([
            'position_link_id' => $link->getKey(),
            'lifecycle_decision_id' => $decision->getKey(),
            'paper_position_id' => $position->getKey(),
            'paper_wallet_id' => $wallet->getKey(),
            'exit_event_id' => $event->event_id,
            'fill_multiple' => $decision->observed_multiple,
            'cost_basis_native' => $costBasis,
            'proceeds_native' => $proceeds,
            'realized_pnl_native' => $pnl,
            'settled_at' => now(),
        ]);

        $link->forceFill(['ownership_state' => 'terminal'])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{TradingEnginePaperPositionLink, PaperPosition, TradingEnginePaperObservation|null}
     */
    private function correlatedState(array $payload, bool $requireObservation): array
    {
        $link = TradingEnginePaperPositionLink::query()
            ->where('engine_position_id', $payload['position_id'])
            ->lockForUpdate()
            ->first();
        $position = $link instanceof TradingEnginePaperPositionLink
            ? PaperPosition::query()->whereKey($link->paper_position_id)->lockForUpdate()->first()
            : null;
        $observation = $link instanceof TradingEnginePaperPositionLink
            ? TradingEnginePaperObservation::query()
                ->where('position_link_id', $link->getKey())
                ->where('observation_id', $payload['source']['observation_id'])
                ->where('observation_sequence', $payload['source']['sequence'])
                ->first()
            : null;

        if (! $link instanceof TradingEnginePaperPositionLink
            || ! $position instanceof PaperPosition
            || ($requireObservation && ! $observation instanceof TradingEnginePaperObservation)
            || (string) $link->user_id !== $payload['subject']['control_plane_user_id']
            || (string) $position->user_id !== $payload['subject']['control_plane_user_id']
            || (string) $link->paper_position_id !== $payload['source']['paper_position_id']
            || $payload['network']['id'] !== self::NETWORK_ID
            || $link->chain !== Chain::Solana->value
            || $position->chain !== Chain::Solana
            || $link->asset_address !== $payload['asset']['address']
            || $position->address !== $payload['asset']['address']
            || ($requireObservation && ! $this->observationMatches($observation, $payload))) {
            $this->reject('PAPER_LIFECYCLE_CORRELATION_MISMATCH');
        }

        return [$link, $position, $observation];
    }

    /** @param array<string, mixed> $payload */
    private function registrationMatches(TradingEnginePaperPositionLink $link, array $payload): bool
    {
        $registration = $link->registration_payload;

        if (! is_array($registration)
            || $link->registration_payload_sha256 !== $this->canonicalJson->hash($registration)) {
            return false;
        }

        foreach (['source', 'subject', 'network', 'asset', 'entry', 'strategy'] as $key) {
            if (! $this->canonicalJson->equals($payload[$key] ?? null, $registration[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function observationMatches(?TradingEnginePaperObservation $observation, array $payload): bool
    {
        if (! $observation instanceof TradingEnginePaperObservation
            || ! is_array($observation->payload)
            || $observation->payload_sha256 !== $this->canonicalJson->hash($observation->payload)) {
            return false;
        }

        foreach (['position_id', 'source', 'subject', 'network', 'asset', 'market'] as $key) {
            if (! $this->canonicalJson->equals($payload[$key] ?? null, $observation->payload[$key] ?? null)) {
                return false;
            }
        }

        return data_get($observation->payload, 'validation.status') === 'eligible'
            && data_get($observation->payload, 'validation.identity_verified') === true
            && data_get($observation->payload, 'validation.simulation_allowed') === true;
    }

    /** @return array<string, mixed> */
    private function payload(TradingEngineEvent $event): array
    {
        if (! is_array($event->payload)) {
            $this->reject('PAPER_LIFECYCLE_PAYLOAD_INVALID');
        }

        if ($this->canonicalJson->hash($event->payload) !== $event->payload_sha256) {
            $this->reject('PAPER_LIFECYCLE_PAYLOAD_HASH_MISMATCH');
        }

        return $event->payload;
    }

    private function reject(string $code): never
    {
        throw new TradingEngineProjectionException(
            $code,
            'The trading engine PAPER lifecycle event failed integrity checks.',
        );
    }
}
