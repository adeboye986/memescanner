<?php

namespace App\Services\TradingEngine;

use App\Exceptions\TradingEngineProjectionException;
use App\Models\TradingEngineEvent;
use App\Models\TradingEnginePaperExitSettlementProjection;
use App\Models\TradingEnginePaperFinancialObservation;
use App\Models\TradingEnginePaperPositionProjection;
use App\Models\TradingEnginePaperPositionState;
use App\Models\TradingEnginePaperWalletProjection;

class TradingEnginePaperFinancialLifecycleProjector
{
    public function __construct(private TradingEngineCanonicalJson $canonicalJson) {}

    public function project(TradingEngineEvent $event): void
    {
        $payload = $this->object($event->payload);
        $source = $this->object($payload['source'] ?? null);
        $subject = $this->object($payload['subject'] ?? null);
        $network = $this->object($payload['network'] ?? null);
        $asset = $this->object($payload['asset'] ?? null);
        $market = $this->object($payload['market'] ?? null);
        $position = TradingEnginePaperPositionProjection::query()
            ->where('engine_position_id', $event->aggregate_id)
            ->first();

        if (! $position instanceof TradingEnginePaperPositionProjection) {
            $this->reject('PAPER_FINANCIAL_POSITION_NOT_FOUND', true);
        }

        $state = TradingEnginePaperPositionState::query()
            ->where('position_projection_id', $position->getKey())
            ->lockForUpdate()
            ->first();
        $observation = TradingEnginePaperFinancialObservation::query()
            ->where('position_state_id', $state?->getKey())
            ->where('observation_id', $source['observation_id'] ?? null)
            ->where('sequence', $source['sequence'] ?? null)
            ->lockForUpdate()
            ->first();

        if (! $state instanceof TradingEnginePaperPositionState
            || ! $observation instanceof TradingEnginePaperFinancialObservation
            || ! is_array($observation->payload)
            || $observation->user_id !== $position->user_id
            || (string) $position->user_id !== ($subject['control_plane_user_id'] ?? null)
            || $position->network_id !== ($network['id'] ?? null)
            || $position->asset_address !== ($asset['address'] ?? null)
            || $position->engine_position_id !== ($payload['position_id'] ?? null)
            || $event->aggregate_id !== $position->engine_position_id
            || $event->causation_id !== ($payload['operation_id'] ?? null)
            || $event->idempotency_key !== $observation->idempotency_key
            || $observation->payload_sha256 !== $this->canonicalJson->hash($observation->payload)
            || ! $this->canonicalJson->equals($source, $observation->payload['source'] ?? null)
            || ! $this->canonicalJson->equals($subject, $observation->payload['subject'] ?? null)
            || ! $this->canonicalJson->equals($network, $observation->payload['network'] ?? null)
            || ! $this->canonicalJson->equals($asset, $observation->payload['asset'] ?? null)
            || ! $this->canonicalJson->equals($market, $observation->payload['market'] ?? null)) {
            $this->reject('PAPER_FINANCIAL_LIFECYCLE_CORRELATION_INVALID');
        }

        if ($observation->status === 'projected') {
            if ($observation->engine_event_id !== $event->event_id
                || $observation->engine_decision_id !== ($payload['decision_id'] ?? null)
                || $state->last_payload_sha256 !== $event->payload_sha256) {
                $this->reject('PAPER_FINANCIAL_LIFECYCLE_PROJECTION_CONFLICT');
            }

            return;
        }

        if ($state->state !== 'open'
            || $state->lifecycle_version + 1 !== ($payload['lifecycle_version'] ?? null)
            || $event->aggregate_version !== $state->lifecycle_version + 2
            || $state->next_observation_sequence !== $observation->sequence + 1) {
            $this->reject('PAPER_FINANCIAL_LIFECYCLE_VERSION_CONFLICT');
        }

        $decision = $payload['decision'] ?? null;
        if ($event->event_type === 'paper.position.held.v1' && $decision === 'HOLD') {
            $this->projectState($state, $observation, $event, $payload, $market, 'open');

            return;
        }

        if ($event->event_type !== 'paper.exit.settled.v1' || $decision !== 'EXIT') {
            $this->reject('PAPER_FINANCIAL_LIFECYCLE_EVENT_INVALID');
        }

        $walletPayload = $this->object($payload['wallet'] ?? null);
        $settlementPayload = $this->object($payload['settlement'] ?? null);
        $wallet = TradingEnginePaperWalletProjection::query()
            ->whereKey($position->wallet_projection_id)
            ->lockForUpdate()
            ->first();
        $existing = TradingEnginePaperExitSettlementProjection::query()
            ->where('position_projection_id', $position->getKey())
            ->orWhere('engine_settlement_id', $settlementPayload['settlement_id'] ?? null)
            ->first();

        if ($existing instanceof TradingEnginePaperExitSettlementProjection) {
            if ($existing->engine_event_id !== $event->event_id
                || $existing->payload_sha256 !== $event->payload_sha256) {
                $this->reject('PAPER_FINANCIAL_SETTLEMENT_CONFLICT');
            }

            return;
        }

        if (! $wallet instanceof TradingEnginePaperWalletProjection
            || $wallet->user_id !== $position->user_id
            || $wallet->engine_wallet_id !== ($walletPayload['wallet_id'] ?? null)
            || $walletPayload['currency'] !== 'SOL'
            || $settlementPayload['cost_basis_native'] !== $position->cost_basis_native
            || $settlementPayload['exit_price_usd'] !== ($market['price_usd'] ?? null)
            || $settlementPayload['exit_market_cap_usd'] !== ($market['market_cap_usd'] ?? null)
            || $settlementPayload['observed_multiple'] !== ($payload['observed_multiple'] ?? null)
            || $settlementPayload['ledger_transaction_reference'] !== 'paper:exit:'.$position->engine_position_id.':v1') {
            $this->reject('PAPER_FINANCIAL_SETTLEMENT_CORRELATION_INVALID');
        }

        TradingEnginePaperExitSettlementProjection::query()->create([
            'position_projection_id' => $position->getKey(),
            'position_state_id' => $state->getKey(),
            'wallet_projection_id' => $wallet->getKey(),
            'user_id' => $position->user_id,
            'engine_settlement_id' => $settlementPayload['settlement_id'],
            'engine_decision_id' => $payload['decision_id'],
            'engine_order_id' => $settlementPayload['order_id'],
            'engine_fill_id' => $settlementPayload['fill_id'],
            'engine_ledger_transaction_id' => $settlementPayload['ledger_transaction_id'],
            'engine_event_id' => $event->event_id,
            'cost_basis_native' => $settlementPayload['cost_basis_native'],
            'proceeds_native' => $settlementPayload['proceeds_native'],
            'realized_pnl_native' => $settlementPayload['realized_pnl_native'],
            'exit_price_usd' => $settlementPayload['exit_price_usd'],
            'exit_market_cap_usd' => $settlementPayload['exit_market_cap_usd'],
            'observed_multiple' => $settlementPayload['observed_multiple'],
            'fill_model' => $settlementPayload['fill_model'],
            'payload_sha256' => $event->payload_sha256,
            'settled_at' => $settlementPayload['settled_at'],
            'projected_at' => now(),
        ]);
        if ($wallet->last_event_occurred_at->lessThanOrEqualTo($event->occurred_at)) {
            $wallet->forceFill([
                'available_balance_native' => $walletPayload['available_balance_native'],
                'invested_balance_native' => $walletPayload['invested_balance_native'],
                'realized_pnl_native' => $walletPayload['realized_pnl_native'],
                'last_event_id' => $event->event_id,
                'last_event_occurred_at' => $event->occurred_at,
                'last_payload_sha256' => $event->payload_sha256,
                'projected_at' => now(),
            ])->save();
        }
        $this->projectState($state, $observation, $event, $payload, $market, 'closed');
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $market */
    private function projectState(
        TradingEnginePaperPositionState $state,
        TradingEnginePaperFinancialObservation $observation,
        TradingEngineEvent $event,
        array $payload,
        array $market,
        string $stateValue,
    ): void {
        $state->forceFill([
            'lifecycle_version' => $payload['lifecycle_version'],
            'state' => $stateValue,
            'protection_state' => $payload['protection_after'],
            'last_observation_id' => $payload['source']['observation_id'],
            'last_observation_sequence' => $payload['source']['sequence'],
            'last_market_cap_usd' => $market['market_cap_usd'],
            'last_price_usd' => $market['price_usd'],
            'last_liquidity_usd' => $market['liquidity_usd'] ?? null,
            'observed_multiple' => $payload['observed_multiple'],
            'peak_market_cap_usd' => $payload['peak_market_cap_usd'],
            'peak_multiple' => $payload['peak_multiple'],
            'max_drawdown_percent' => $payload['drawdown_percent'],
            'last_event_id' => $event->event_id,
            'last_payload_sha256' => $event->payload_sha256,
            'last_observed_at' => $market['observed_at'],
            'closed_at' => $stateValue === 'closed' ? $event->occurred_at : null,
        ])->save();
        $observation->forceFill([
            'status' => 'projected',
            'engine_operation_id' => $payload['operation_id'],
            'engine_decision_id' => $payload['decision_id'],
            'engine_event_id' => $event->event_id,
            'engine_settlement_id' => $payload['settlement']['settlement_id'] ?? null,
            'engine_decision' => $payload['decision'],
            'last_error_code' => null,
            'projected_at' => now(),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->reject('PAPER_FINANCIAL_LIFECYCLE_EVENT_INVALID');
        }

        return $value;
    }

    private function reject(string $code, bool $retryable = false): never
    {
        throw new TradingEngineProjectionException(
            $code,
            'The engine-owned PAPER lifecycle event failed projection integrity checks.',
            $retryable,
        );
    }
}
