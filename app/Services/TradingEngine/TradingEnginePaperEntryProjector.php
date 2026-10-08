<?php

namespace App\Services\TradingEngine;

use App\Enums\TradeOpportunityStatus;
use App\Exceptions\TradingEngineProjectionException;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEnginePaperEntryIntent;
use App\Models\TradingEnginePaperPositionProjection;
use App\Models\TradingEnginePaperPositionState;
use App\Models\TradingEnginePaperWalletProjection;
use Illuminate\Support\Facades\DB;

class TradingEnginePaperEntryProjector
{
    public function __construct(
        private TradingEngineCanonicalJson $canonicalJson,
        private CanonicalDecimal $decimals,
        private TradingEnginePaperEntryNotifier $notifier,
    ) {}

    public function project(TradingEngineEvent $event): void
    {
        $payload = $this->object($event->payload);
        $source = $this->object($payload['source'] ?? null);
        $subject = $this->object($payload['subject'] ?? null);
        $network = $this->object($payload['network'] ?? null);
        $asset = $this->object($payload['asset'] ?? null);
        $walletPayload = $this->object($payload['wallet'] ?? null);
        $intentPayload = $this->object($payload['intent'] ?? null);
        $orderPayload = $this->object($payload['order'] ?? null);
        $fillPayload = $this->object($payload['fill'] ?? null);
        $positionPayload = $this->object($payload['position'] ?? null);
        $authority = $this->object($payload['authority'] ?? null);
        $ledger = $this->object($payload['ledger'] ?? null);
        $tradeOpportunityId = $source['trade_opportunity_id'] ?? null;

        if (! is_string($tradeOpportunityId) || preg_match('/^[1-9][0-9]*$/D', $tradeOpportunityId) !== 1) {
            $this->reject('PAPER_ENTRY_EVENT_CORRELATION_INVALID');
        }

        $intent = TradingEnginePaperEntryIntent::query()
            ->where('trade_opportunity_id', $tradeOpportunityId)
            ->lockForUpdate()
            ->first();
        $opportunity = TradeOpportunity::query()->lockForUpdate()->find($tradeOpportunityId);
        $link = $opportunity instanceof TradeOpportunity
            ? TradingEngineOpportunityLink::query()->where('trade_opportunity_id', $opportunity->getKey())->first()
            : null;
        $evaluation = $intent instanceof TradingEnginePaperEntryIntent
            ? TradingEngineOpportunityEvaluation::query()->find($intent->opportunity_evaluation_id)
            : null;
        $command = $intent instanceof TradingEnginePaperEntryIntent && is_array($intent->payload)
            ? $intent->payload
            : null;

        if (! $intent instanceof TradingEnginePaperEntryIntent
            || ! $opportunity instanceof TradeOpportunity
            || ! $link instanceof TradingEngineOpportunityLink
            || ! $evaluation instanceof TradingEngineOpportunityEvaluation
            || ! is_array($command)
            || $intent->user_id !== $opportunity->user_id
            || $intent->opportunity_link_id !== $link->getKey()
            || $evaluation->getKey() !== $intent->opportunity_evaluation_id
            || $event->idempotency_key !== $intent->idempotency_key
            || $event->aggregate_id !== ($positionPayload['position_id'] ?? null)
            || $event->aggregate_version !== 1
            || $event->causation_id !== ($payload['operation_id'] ?? null)
            || (string) $opportunity->user_id !== ($subject['control_plane_user_id'] ?? null)
            || $link->engine_opportunity_id !== ($source['engine_opportunity_id'] ?? null)
            || $evaluation->evaluation_id !== ($source['evaluation_id'] ?? null)
            || $evaluation->result_sha256 !== ($source['evaluation_result_sha256'] ?? null)
            || ! $this->canonicalJson->equals($source, $command['source'] ?? null)
            || ! $this->canonicalJson->equals($subject, $command['subject'] ?? null)
            || ! $this->canonicalJson->equals($network, $command['network'] ?? null)
            || ! $this->canonicalJson->equals($asset, $command['asset'] ?? null)
            || ! $this->canonicalJson->equals($authority, $command['authority'] ?? null)
            || $this->canonicalJson->hash($command) !== ($intentPayload['intent_sha256'] ?? null)
            || $this->canonicalJson->hash($authority) !== ($intentPayload['authority_sha256'] ?? null)
            || ($intentPayload['notional_native'] ?? null) !== data_get($command, 'entry.requested_notional_native')
            || ($fillPayload['notional_native'] ?? null) !== data_get($command, 'entry.requested_notional_native')
            || ($fillPayload['fill_price_usd'] ?? null) !== data_get($command, 'entry.price_usd')
            || ($positionPayload['cost_basis_native'] ?? null) !== data_get($command, 'entry.requested_notional_native')
            || ($positionPayload['entry_price_usd'] ?? null) !== data_get($command, 'entry.price_usd')
            || ($positionPayload['entry_market_cap_usd'] ?? null) !== data_get($command, 'entry.market_cap_usd')
            || ($positionPayload['entry_liquidity_usd'] ?? null) !== data_get($command, 'entry.liquidity_usd')
            || ($ledger['opening_transaction_reference'] ?? null) !== 'paper:wallet:opening:'.$subject['control_plane_user_id'].':'.$network['id'].':SOL:v1'
            || ($ledger['entry_transaction_reference'] ?? null) !== 'paper:entry:'.$source['engine_opportunity_id'].':v1') {
            $this->reject('PAPER_ENTRY_EVENT_CORRELATION_INVALID');
        }

        $identifiers = [
            'engine_operation_id' => $payload['operation_id'],
            'engine_wallet_id' => $walletPayload['wallet_id'] ?? null,
            'engine_intent_id' => $intentPayload['intent_id'] ?? null,
            'engine_order_id' => $orderPayload['order_id'] ?? null,
            'engine_fill_id' => $fillPayload['fill_id'] ?? null,
            'engine_position_id' => $positionPayload['position_id'] ?? null,
            'engine_event_id' => $event->event_id,
        ];

        foreach ($identifiers as $column => $value) {
            if ($intent->{$column} !== null && $intent->{$column} !== $value) {
                $this->reject('PAPER_ENTRY_EVENT_IDENTITY_CONFLICT');
            }
        }

        $existingPosition = TradingEnginePaperPositionProjection::query()
            ->where('engine_position_id', $positionPayload['position_id'])
            ->orWhere('entry_event_id', $event->event_id)
            ->first();

        if ($existingPosition instanceof TradingEnginePaperPositionProjection) {
            if ($existingPosition->paper_entry_intent_id !== $intent->getKey()
                || $existingPosition->entry_payload_sha256 !== $event->payload_sha256) {
                $this->reject('PAPER_ENTRY_PROJECTION_CONFLICT');
            }

            return;
        }

        $wallet = TradingEnginePaperWalletProjection::query()
            ->where('engine_wallet_id', $walletPayload['wallet_id'])
            ->lockForUpdate()
            ->first();

        if (! $wallet instanceof TradingEnginePaperWalletProjection) {
            $wallet = TradingEnginePaperWalletProjection::query()->create([
                'engine_wallet_id' => $walletPayload['wallet_id'],
                'user_id' => $opportunity->user_id,
                'network_id' => $network['id'],
                'currency' => $walletPayload['currency'],
                'opening_balance_native' => $walletPayload['opening_balance_native'],
                'available_balance_native' => $walletPayload['available_balance_native'],
                'invested_balance_native' => $walletPayload['invested_balance_native'],
                'realized_pnl_native' => '0',
                'last_event_id' => $event->event_id,
                'last_event_occurred_at' => $event->occurred_at,
                'last_payload_sha256' => $event->payload_sha256,
                'projected_at' => now(),
            ]);
        } elseif ($wallet->user_id !== $opportunity->user_id
            || $wallet->network_id !== $network['id']
            || $wallet->currency !== $walletPayload['currency']
            || $this->decimals->normalize($wallet->opening_balance_native) !== $walletPayload['opening_balance_native']) {
            $this->reject('PAPER_WALLET_PROJECTION_CONFLICT');
        } elseif ($wallet->last_event_occurred_at->lessThanOrEqualTo($event->occurred_at)) {
            $wallet->forceFill([
                'available_balance_native' => $walletPayload['available_balance_native'],
                'invested_balance_native' => $walletPayload['invested_balance_native'],
                'last_event_id' => $event->event_id,
                'last_event_occurred_at' => $event->occurred_at,
                'last_payload_sha256' => $event->payload_sha256,
                'projected_at' => now(),
            ])->save();
        }

        $position = TradingEnginePaperPositionProjection::query()->create([
            'engine_position_id' => $positionPayload['position_id'],
            'wallet_projection_id' => $wallet->getKey(),
            'paper_entry_intent_id' => $intent->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'opportunity_evaluation_id' => $evaluation->getKey(),
            'user_id' => $opportunity->user_id,
            'engine_intent_id' => $intentPayload['intent_id'],
            'engine_order_id' => $orderPayload['order_id'],
            'engine_fill_id' => $fillPayload['fill_id'],
            'engine_opportunity_id' => $link->engine_opportunity_id,
            'network_id' => $network['id'],
            'asset_address' => $asset['address'],
            'symbol' => $asset['symbol'] ?? null,
            'state' => $positionPayload['state'],
            'quantity' => $fillPayload['quantity'],
            'quantity_unit' => $fillPayload['quantity_unit'],
            'cost_basis_native' => $positionPayload['cost_basis_native'],
            'entry_price_usd' => $positionPayload['entry_price_usd'],
            'entry_market_cap_usd' => $positionPayload['entry_market_cap_usd'],
            'entry_liquidity_usd' => $positionPayload['entry_liquidity_usd'] ?? null,
            'strategy_snapshot' => $authority['strategy'],
            'authority_snapshot' => $authority,
            'entry_event_id' => $event->event_id,
            'entry_payload_sha256' => $event->payload_sha256,
            'opened_at' => $fillPayload['executed_at'],
            'projected_at' => now(),
        ]);

        TradingEnginePaperPositionState::query()->create([
            'position_projection_id' => $position->getKey(),
            'lifecycle_version' => 0,
            'next_observation_sequence' => 1,
            'state' => 'open',
            'protection_state' => 'none',
            'peak_market_cap_usd' => $positionPayload['entry_market_cap_usd'],
            'peak_multiple' => '1',
            'max_drawdown_percent' => '0',
        ]);

        $intent->forceFill([
            ...$identifiers,
            'status' => 'projected',
            'last_error_code' => null,
            'projected_at' => now(),
        ])->save();
        $opportunity->forceFill([
            'status' => TradeOpportunityStatus::Executed,
            'paper_position_id' => null,
            'execution_data' => [
                'executor' => 'trading-engine-paper',
                'engine_position_id' => $positionPayload['position_id'],
            ],
            'executed_at' => $fillPayload['executed_at'],
        ])->save();

        DB::afterCommit(fn () => $this->notifier->send($position));
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->reject('PAPER_ENTRY_EVENT_PAYLOAD_INVALID');
        }

        return $value;
    }

    private function reject(string $errorCode): never
    {
        throw new TradingEngineProjectionException(
            $errorCode,
            'The engine PAPER entry event failed projection integrity checks.',
        );
    }
}
