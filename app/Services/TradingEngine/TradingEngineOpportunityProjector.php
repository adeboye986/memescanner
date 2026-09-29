<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Exceptions\TradingEngineProjectionException;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use Illuminate\Support\Facades\DB;
use Throwable;

class TradingEngineOpportunityProjector
{
    private const ETHEREUM_MAINNET_ID = 'eip155:1';

    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    private const PROJECTABLE_EVENT_TYPES = [
        'opportunity.recorded.v1',
        'opportunity.evaluated.v1',
    ];

    /**
     * @return array{status: string, dependent_event_ids: array<int, string>}
     */
    public function project(string $eventId): array
    {
        try {
            return DB::transaction(function () use ($eventId): array {
                $event = TradingEngineEvent::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if (! $event || ! in_array($event->event_type, self::PROJECTABLE_EVENT_TYPES, true)) {
                    return ['status' => 'ignored', 'dependent_event_ids' => []];
                }

                if ($event->handling_status === TradingEngineEvent::STATUS_PROJECTED) {
                    return [
                        'status' => TradingEngineEvent::STATUS_PROJECTED,
                        'dependent_event_ids' => $this->dependentEvaluationEventIds($event),
                    ];
                }

                if (in_array($event->handling_status, [
                    TradingEngineEvent::STATUS_FAILED,
                    TradingEngineEvent::STATUS_UNHANDLED,
                ], true)) {
                    return ['status' => $event->handling_status, 'dependent_event_ids' => []];
                }

                $event->handling_attempts++;

                try {
                    if ($event->event_type === 'opportunity.recorded.v1') {
                        $this->projectRecordedEvent($event);
                        $this->markProjected($event);

                        return [
                            'status' => TradingEngineEvent::STATUS_PROJECTED,
                            'dependent_event_ids' => $this->dependentEvaluationEventIds($event),
                        ];
                    }

                    $status = $this->projectEvaluatedEvent($event);

                    return ['status' => $status, 'dependent_event_ids' => []];
                } catch (TradingEngineProjectionException $exception) {
                    if ($exception->retryable) {
                        throw $exception;
                    }

                    $this->markFailed($event, $exception->errorCode);

                    return ['status' => TradingEngineEvent::STATUS_FAILED, 'dependent_event_ids' => []];
                }
            }, 3);
        } catch (TradingEngineProjectionException $exception) {
            $this->markRetryableFailure($eventId, $exception->errorCode);

            throw $exception;
        } catch (Throwable) {
            $this->markRetryableFailure($eventId, 'PROJECTION_TEMPORARY_FAILURE');

            throw new TradingEngineProjectionException(
                'PROJECTION_TEMPORARY_FAILURE',
                'The trading engine event projection temporarily failed.',
                true,
            );
        }
    }

    public function markDispatchFailure(string $eventId): void
    {
        try {
            TradingEngineEvent::query()
                ->where('event_id', $eventId)
                ->where('handling_status', TradingEngineEvent::STATUS_STORED)
                ->update([
                    'handling_status' => TradingEngineEvent::STATUS_RETRYABLE,
                    'handling_error_code' => 'PROJECTION_DISPATCH_FAILED',
                    'next_handling_at' => now()->addMinute(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // The authenticated inbox row remains the durable recovery source.
        }
    }

    private function projectRecordedEvent(TradingEngineEvent $event): void
    {
        $payload = $this->arrayValue($event->payload, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $source = $this->arrayValue($payload['source'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $subject = $this->arrayValue($payload['subject'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $network = $this->arrayValue($payload['network'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $asset = $this->arrayValue($payload['asset'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $localOpportunityId = $this->stringValue($source['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');

        if (preg_match('/^[1-9][0-9]*$/D', $localOpportunityId) !== 1) {
            $this->reject('RECORDED_EVENT_PAYLOAD_INVALID');
        }

        $opportunity = TradeOpportunity::query()->find($localOpportunityId);

        if (! $opportunity) {
            $this->reject('LOCAL_OPPORTUNITY_NOT_FOUND');
        }

        $engineOpportunityId = $this->stringValue($payload['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $userId = $this->stringValue($subject['control_plane_user_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $discoveryKey = $this->stringValue($source['discovery_key'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $scanner = $this->stringValue($source['scanner'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $networkId = $this->stringValue($network['id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $assetAddress = $this->stringValue($asset['address'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');

        $this->assertOpportunityIdentity(
            $opportunity,
            $localOpportunityId,
            $userId,
            $discoveryKey,
            $scanner,
            $networkId,
            $assetAddress,
        );

        if ($event->aggregate_id !== $engineOpportunityId
            || $event->idempotency_key !== 'opportunity:record:laravel:'.$localOpportunityId.':v1') {
            $this->reject('RECORDED_EVENT_IDEMPOTENCY_MISMATCH');
        }

        $existing = TradingEngineOpportunityLink::query()
            ->where('trade_opportunity_id', $opportunity->getKey())
            ->orWhere('engine_opportunity_id', $engineOpportunityId)
            ->orWhere('recorded_event_id', $event->event_id)
            ->first();

        if ($existing) {
            if (! $this->linkMatches(
                $existing,
                $opportunity,
                $event,
                $engineOpportunityId,
                $userId,
                $discoveryKey,
                $scanner,
                $networkId,
                $assetAddress,
            )) {
                $this->reject('OPPORTUNITY_LINK_CONFLICT');
            }

            return;
        }

        TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => $engineOpportunityId,
            'recorded_event_id' => $event->event_id,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $discoveryKey,
            'scanner' => $scanner,
            'network_id' => $networkId,
            'asset_address' => $assetAddress,
            'recorded_at' => $event->occurred_at,
            'linked_at' => now(),
        ]);
    }

    private function projectEvaluatedEvent(TradingEngineEvent $event): string
    {
        $causalEvent = TradingEngineEvent::query()
            ->where('event_id', $event->causation_id)
            ->first();

        if (! $causalEvent) {
            $this->markDeferred($event, 'CAUSAL_RECORDED_EVENT_PENDING');

            return TradingEngineEvent::STATUS_DEFERRED;
        }

        if ($causalEvent->event_type !== 'opportunity.recorded.v1') {
            $this->reject('CAUSAL_EVENT_TYPE_MISMATCH');
        }

        $link = TradingEngineOpportunityLink::query()
            ->where('recorded_event_id', $causalEvent->event_id)
            ->first();

        if (! $link) {
            if ($causalEvent->handling_status === TradingEngineEvent::STATUS_FAILED) {
                $this->reject('CAUSAL_RECORDED_EVENT_FAILED');
            }

            $this->markDeferred($event, 'CAUSAL_RECORDED_EVENT_PENDING');

            return TradingEngineEvent::STATUS_DEFERRED;
        }

        $opportunity = TradeOpportunity::query()->find($link->trade_opportunity_id);

        if (! $opportunity) {
            $this->reject('LOCAL_OPPORTUNITY_NOT_FOUND');
        }

        $this->assertLinkAndRecordedEventIdentity($link, $opportunity, $causalEvent);

        $payload = $this->arrayValue($event->payload, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $policy = $this->arrayValue($payload['policy'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $source = $this->arrayValue($payload['source'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $engineOpportunityId = $this->stringValue($payload['opportunity_id'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $evaluationId = $this->stringValue($payload['evaluation_id'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $policyKey = $this->stringValue($policy['key'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $policyVersion = $this->integerValue($policy['version'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');

        if ($engineOpportunityId !== $link->engine_opportunity_id
            || $causalEvent->aggregate_id !== $link->engine_opportunity_id) {
            $this->reject('ENGINE_OPPORTUNITY_MISMATCH');
        }

        if ($event->correlation_id !== $causalEvent->correlation_id
            || $event->traceparent !== $causalEvent->traceparent) {
            $this->reject('EVALUATION_CORRELATION_MISMATCH');
        }

        if ($event->aggregate_id !== $evaluationId
            || $event->idempotency_key !== 'evaluation:'.$engineOpportunityId.':'.$policyKey.':'.$policyVersion) {
            $this->reject('EVALUATION_IDEMPOTENCY_MISMATCH');
        }

        $existing = TradingEngineOpportunityEvaluation::query()
            ->where('evaluation_id', $evaluationId)
            ->orWhere('evaluation_event_id', $event->event_id)
            ->orWhere(function ($query) use ($engineOpportunityId, $policyKey, $policyVersion): void {
                $query->where('engine_opportunity_id', $engineOpportunityId)
                    ->where('policy_key', $policyKey)
                    ->where('policy_version', $policyVersion);
            })
            ->first();

        if ($existing) {
            if ($existing->evaluation_id !== $evaluationId
                || $existing->evaluation_event_id !== $event->event_id
                || $existing->engine_opportunity_id !== $engineOpportunityId
                || $existing->opportunity_link_id !== $link->getKey()
                || $existing->trade_opportunity_id !== $opportunity->getKey()
                || $existing->recorded_event_id !== $causalEvent->event_id
                || $existing->policy_key !== $policyKey
                || $existing->policy_version !== $policyVersion
                || $existing->result_sha256 !== ($payload['result_sha256'] ?? null)) {
                $this->reject('EVALUATION_PROJECTION_CONFLICT');
            }

            $this->markProjected($event);

            return TradingEngineEvent::STATUS_PROJECTED;
        }

        $reasonCodes = $this->listValue($payload['reason_codes'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $advisoryCodes = $this->listValue($payload['advisory_codes'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $evidence = $this->arrayValue($payload['evidence'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $outcome = $this->stringValue($payload['outcome'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');

        if (! in_array($outcome, ['passed', 'failed', 'indeterminate'], true)) {
            $this->reject('EVALUATION_EVENT_PAYLOAD_INVALID');
        }

        TradingEngineOpportunityEvaluation::query()->create([
            'opportunity_link_id' => $link->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'evaluation_id' => $evaluationId,
            'engine_opportunity_id' => $engineOpportunityId,
            'evaluation_event_id' => $event->event_id,
            'recorded_event_id' => $causalEvent->event_id,
            'policy_key' => $policyKey,
            'policy_version' => $policyVersion,
            'algorithm_key' => $this->stringValue($policy['algorithm_key'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'algorithm_version' => $this->integerValue($policy['algorithm_version'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'policy_definition_sha256' => $this->stringValue($policy['definition_sha256'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'source_request_sha256' => $this->stringValue($source['request_sha256'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'evaluation_input_sha256' => $this->stringValue($source['evaluation_input_sha256'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'result_sha256' => $this->stringValue($payload['result_sha256'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'outcome' => $outcome,
            'reason_codes' => $reasonCodes,
            'advisory_codes' => $advisoryCodes,
            'evidence' => $evidence,
            'correlation_id' => $event->correlation_id,
            'traceparent' => $event->traceparent,
            'evaluated_at' => $event->occurred_at,
            'event_received_at' => $event->received_at,
            'projected_at' => now(),
        ]);

        $this->markProjected($event);

        return TradingEngineEvent::STATUS_PROJECTED;
    }

    private function assertLinkAndRecordedEventIdentity(
        TradingEngineOpportunityLink $link,
        TradeOpportunity $opportunity,
        TradingEngineEvent $recordedEvent,
    ): void {
        $payload = $this->arrayValue($recordedEvent->payload, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $source = $this->arrayValue($payload['source'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $subject = $this->arrayValue($payload['subject'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $network = $this->arrayValue($payload['network'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $asset = $this->arrayValue($payload['asset'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');

        $localOpportunityId = $this->stringValue($source['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $userId = $this->stringValue($subject['control_plane_user_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $discoveryKey = $this->stringValue($source['discovery_key'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $scanner = $this->stringValue($source['scanner'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $networkId = $this->stringValue($network['id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $assetAddress = $this->stringValue($asset['address'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $engineOpportunityId = $this->stringValue($payload['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');

        $this->assertOpportunityIdentity(
            $opportunity,
            $localOpportunityId,
            $userId,
            $discoveryKey,
            $scanner,
            $networkId,
            $assetAddress,
        );

        if (! $this->linkMatches(
            $link,
            $opportunity,
            $recordedEvent,
            $engineOpportunityId,
            $userId,
            $discoveryKey,
            $scanner,
            $networkId,
            $assetAddress,
        )) {
            $this->reject('OPPORTUNITY_LINK_CONFLICT');
        }
    }

    private function assertOpportunityIdentity(
        TradeOpportunity $opportunity,
        string $localOpportunityId,
        string $userId,
        string $discoveryKey,
        string $scanner,
        string $networkId,
        string $assetAddress,
    ): void {
        $expectedNetworkId = match ($opportunity->chain) {
            Chain::Solana => self::SOLANA_MAINNET_ID,
            Chain::Ethereum => self::ETHEREUM_MAINNET_ID,
        };

        if ((string) $opportunity->getKey() !== $localOpportunityId
            || $opportunity->user_id === null
            || (string) $opportunity->user_id !== $userId
            || $opportunity->discovery_key !== $discoveryKey
            || $opportunity->scanner !== $scanner
            || $expectedNetworkId !== $networkId
            || $opportunity->address !== $assetAddress) {
            $this->reject('LOCAL_OPPORTUNITY_IDENTITY_MISMATCH');
        }
    }

    private function linkMatches(
        TradingEngineOpportunityLink $link,
        TradeOpportunity $opportunity,
        TradingEngineEvent $event,
        string $engineOpportunityId,
        string $userId,
        string $discoveryKey,
        string $scanner,
        string $networkId,
        string $assetAddress,
    ): bool {
        return $link->trade_opportunity_id === $opportunity->getKey()
            && $link->engine_opportunity_id === $engineOpportunityId
            && $link->recorded_event_id === $event->event_id
            && (string) $link->user_id === $userId
            && $link->discovery_key === $discoveryKey
            && $link->scanner === $scanner
            && $link->network_id === $networkId
            && $link->asset_address === $assetAddress;
    }

    /** @return array<int, string> */
    private function dependentEvaluationEventIds(TradingEngineEvent $event): array
    {
        if ($event->event_type !== 'opportunity.recorded.v1') {
            return [];
        }

        return TradingEngineEvent::query()
            ->where('event_type', 'opportunity.evaluated.v1')
            ->where('causation_id', $event->event_id)
            ->whereIn('handling_status', [
                TradingEngineEvent::STATUS_STORED,
                TradingEngineEvent::STATUS_DEFERRED,
                TradingEngineEvent::STATUS_RETRYABLE,
            ])
            ->orderBy('id')
            ->pluck('event_id')
            ->all();
    }

    private function markProjected(TradingEngineEvent $event): void
    {
        $event->forceFill([
            'handling_status' => TradingEngineEvent::STATUS_PROJECTED,
            'handling_error_code' => null,
            'next_handling_at' => null,
            'handled_at' => now(),
        ])->save();
    }

    private function markDeferred(TradingEngineEvent $event, string $errorCode): void
    {
        $event->forceFill([
            'handling_status' => TradingEngineEvent::STATUS_DEFERRED,
            'handling_error_code' => $errorCode,
            'next_handling_at' => now()->addMinute(),
            'handled_at' => null,
        ])->save();
    }

    private function markFailed(TradingEngineEvent $event, string $errorCode): void
    {
        $event->forceFill([
            'handling_status' => TradingEngineEvent::STATUS_FAILED,
            'handling_error_code' => $errorCode,
            'next_handling_at' => null,
            'handled_at' => now(),
        ])->save();
    }

    private function markRetryableFailure(string $eventId, string $errorCode): void
    {
        try {
            TradingEngineEvent::query()
                ->where('event_id', $eventId)
                ->whereNotIn('handling_status', [
                    TradingEngineEvent::STATUS_PROJECTED,
                    TradingEngineEvent::STATUS_FAILED,
                    TradingEngineEvent::STATUS_UNHANDLED,
                ])
                ->update([
                    'handling_status' => TradingEngineEvent::STATUS_RETRYABLE,
                    'handling_attempts' => DB::raw('handling_attempts + 1'),
                    'handling_error_code' => $errorCode,
                    'next_handling_at' => now()->addMinute(),
                    'handled_at' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // The authenticated inbox row remains the durable recovery source.
        }
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value, string $errorCode): array
    {
        if (! is_array($value)) {
            $this->reject($errorCode);
        }

        return $value;
    }

    /** @return array<int, mixed> */
    private function listValue(mixed $value, string $errorCode): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->reject($errorCode);
        }

        return $value;
    }

    private function stringValue(mixed $value, string $errorCode): string
    {
        if (! is_string($value) || $value === '') {
            $this->reject($errorCode);
        }

        return $value;
    }

    private function integerValue(mixed $value, string $errorCode): int
    {
        if (! is_int($value) || $value < 1) {
            $this->reject($errorCode);
        }

        return $value;
    }

    private function reject(string $errorCode): never
    {
        throw new TradingEngineProjectionException(
            $errorCode,
            'The trading engine event failed projection integrity checks.',
        );
    }
}
