<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use JsonException;
use Throwable;

class TradingEngineEvaluationConsumptionPolicy
{
    public const APPROVED_ALGORITHM_KEY = 'threshold-matrix';

    public const APPROVED_ALGORITHM_VERSION = 1;

    public const APPROVED_POLICY_KEY = 'migration-opportunity-snapshot';

    public const APPROVED_POLICY_SHA256 = '7274b84fda5959c257a24fa585d5f4a6e00ad48c047a5ba44b9d109b12ace6dc';

    public const APPROVED_POLICY_VERSION = 1;

    private const ETHEREUM_MAINNET_ID = 'eip155:1';

    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public function __construct(private TradingEngineProjectionTimestamp $timestamps) {}

    public function assess(
        TradeOpportunity $opportunity,
        ?TradingEngineOpportunityLink $link,
        ?TradingEngineOpportunityEvaluation $evaluation,
    ): TradingEngineEvaluationConsumptionDecision {
        if (config('services.trading_engine.enabled', false) !== true) {
            return $this->ineligible('ENGINE_INTEGRATION_DISABLED');
        }

        if (config('services.trading_engine.opportunity_projection_enabled', false) !== true) {
            return $this->ineligible('OPPORTUNITY_PROJECTION_DISABLED');
        }

        if (config('services.trading_engine.evaluation_consumption_enabled', false) !== true) {
            return $this->ineligible('EVALUATION_CONSUMPTION_DISABLED');
        }

        if ($link === null) {
            return $this->ineligible('OPPORTUNITY_LINK_MISSING');
        }

        if ($evaluation === null) {
            return $this->ineligible('EVALUATION_PROJECTION_MISSING');
        }

        try {
            return $this->assessIntegrity($opportunity, $link, $evaluation);
        } catch (Throwable) {
            return $this->ineligible('EVALUATION_INTEGRITY_UNVERIFIABLE');
        }
    }

    private function assessIntegrity(
        TradeOpportunity $opportunity,
        TradingEngineOpportunityLink $link,
        TradingEngineOpportunityEvaluation $evaluation,
    ): TradingEngineEvaluationConsumptionDecision {
        if (! $opportunity->exists || $opportunity->getKey() === null) {
            return $this->ineligible('LOCAL_OPPORTUNITY_NOT_PERSISTED');
        }

        if (! $link->exists || $link->trade_opportunity_id !== $opportunity->getKey()) {
            return $this->ineligible('OPPORTUNITY_LINK_OPPORTUNITY_MISMATCH');
        }

        if ($opportunity->user_id === null || $link->user_id !== $opportunity->user_id) {
            return $this->ineligible('OPPORTUNITY_LINK_USER_MISMATCH');
        }

        $expectedNetwork = match ($opportunity->chain) {
            Chain::Solana => self::SOLANA_MAINNET_ID,
            Chain::Ethereum => self::ETHEREUM_MAINNET_ID,
        };

        if ($link->network_id !== $expectedNetwork) {
            return $this->ineligible('OPPORTUNITY_LINK_NETWORK_MISMATCH');
        }

        if ($link->asset_address !== $opportunity->address) {
            return $this->ineligible('OPPORTUNITY_LINK_ASSET_MISMATCH');
        }

        if ($link->discovery_key !== $opportunity->discovery_key) {
            return $this->ineligible('OPPORTUNITY_LINK_DISCOVERY_MISMATCH');
        }

        if ($link->scanner !== $opportunity->scanner) {
            return $this->ineligible('OPPORTUNITY_LINK_SCANNER_MISMATCH');
        }

        if (! $evaluation->exists
            || $evaluation->trade_opportunity_id !== $opportunity->getKey()
            || $evaluation->opportunity_link_id !== $link->getKey()) {
            return $this->ineligible('EVALUATION_OPPORTUNITY_MISMATCH');
        }

        if ($evaluation->engine_opportunity_id !== $link->engine_opportunity_id) {
            return $this->ineligible('ENGINE_OPPORTUNITY_MISMATCH');
        }

        if (! $this->approvedPolicy($evaluation)) {
            return $this->ineligible('EVALUATION_POLICY_UNAPPROVED');
        }

        $recordedEvent = TradingEngineEvent::query()
            ->where('event_id', $link->recorded_event_id)
            ->first();

        if (! $recordedEvent instanceof TradingEngineEvent) {
            return $this->ineligible('RECORDED_EVENT_MISSING');
        }

        $evaluationEvent = TradingEngineEvent::query()
            ->where('event_id', $evaluation->evaluation_event_id)
            ->first();

        if (! $evaluationEvent instanceof TradingEngineEvent) {
            return $this->ineligible('EVALUATION_EVENT_MISSING');
        }

        if ($recordedEvent->handling_status !== TradingEngineEvent::STATUS_PROJECTED
            || $evaluationEvent->handling_status !== TradingEngineEvent::STATUS_PROJECTED) {
            return $this->ineligible('PROJECTION_EVENT_NOT_PROJECTED');
        }

        $recordedPayload = $this->arrayValue($recordedEvent->payload);
        $evaluationPayload = $this->arrayValue($evaluationEvent->payload);

        if ($recordedPayload === null || ! $this->recordedEventMatches(
            $opportunity,
            $link,
            $recordedEvent,
            $recordedPayload,
        )) {
            return $this->ineligible('RECORDED_EVENT_INTEGRITY_MISMATCH');
        }

        if ($evaluationPayload === null || ! $this->evaluationEventMatches(
            $link,
            $evaluation,
            $recordedEvent,
            $evaluationEvent,
            $evaluationPayload,
        )) {
            return $this->ineligible('EVALUATION_EVENT_INTEGRITY_MISMATCH');
        }

        if ($recordedEvent->correlation_id !== $evaluationEvent->correlation_id
            || $recordedEvent->traceparent !== $evaluationEvent->traceparent
            || $evaluation->correlation_id !== $evaluationEvent->correlation_id
            || $evaluation->traceparent !== $evaluationEvent->traceparent) {
            return $this->ineligible('EVALUATION_CORRELATION_MISMATCH');
        }

        if (! $this->timestamps->representsSameStoredInstant($link->recorded_at, $recordedEvent->occurred_at)
            || ! $this->timestamps->representsSameStoredInstant($evaluation->evaluated_at, $evaluationEvent->occurred_at)
            || ! $this->timestamps->representsSameStoredInstant($evaluation->event_received_at, $evaluationEvent->received_at)) {
            return $this->ineligible('PROJECTION_TIMESTAMP_MISMATCH');
        }

        $acceptedRequest = $recordedPayload;
        unset($acceptedRequest['operation_id'], $acceptedRequest['opportunity_id']);
        $sourceRequestSha256 = $this->canonicalHash($acceptedRequest);

        if (! hash_equals($sourceRequestSha256, $evaluation->source_request_sha256)) {
            return $this->ineligible('SOURCE_REQUEST_HASH_MISMATCH');
        }

        $evaluationInputSha256 = $this->canonicalHash([
            'policy_definition_sha256' => self::APPROVED_POLICY_SHA256,
            'source_request_sha256' => $sourceRequestSha256,
        ]);

        if (! hash_equals($evaluationInputSha256, $evaluation->evaluation_input_sha256)) {
            return $this->ineligible('EVALUATION_INPUT_HASH_MISMATCH');
        }

        $resultSha256 = $this->canonicalHash([
            'policy_key' => $evaluation->policy_key,
            'policy_version' => $evaluation->policy_version,
            'algorithm_key' => $evaluation->algorithm_key,
            'algorithm_version' => $evaluation->algorithm_version,
            'policy_definition_sha256' => $evaluation->policy_definition_sha256,
            'source_request_sha256' => $evaluation->source_request_sha256,
            'evaluation_input_sha256' => $evaluation->evaluation_input_sha256,
            'outcome' => $evaluation->outcome,
            'reason_codes' => $evaluation->reason_codes,
            'advisory_codes' => $evaluation->advisory_codes,
            'evidence' => $evaluation->evidence,
        ]);

        if (! hash_equals($resultSha256, $evaluation->result_sha256)) {
            return $this->ineligible('EVALUATION_RESULT_HASH_MISMATCH');
        }

        return match ($evaluation->outcome) {
            'passed' => TradingEngineEvaluationConsumptionDecision::eligible(
                $evaluation->evaluation_id,
                $evaluation->engine_opportunity_id,
            ),
            'failed' => $this->ineligible('EVALUATION_OUTCOME_FAILED'),
            'indeterminate' => $this->ineligible('EVALUATION_OUTCOME_INDETERMINATE'),
            default => $this->ineligible('EVALUATION_OUTCOME_UNSUPPORTED'),
        };
    }

    private function approvedPolicy(TradingEngineOpportunityEvaluation $evaluation): bool
    {
        return $evaluation->policy_key === self::APPROVED_POLICY_KEY
            && $evaluation->policy_version === self::APPROVED_POLICY_VERSION
            && $evaluation->algorithm_key === self::APPROVED_ALGORITHM_KEY
            && $evaluation->algorithm_version === self::APPROVED_ALGORITHM_VERSION
            && hash_equals(self::APPROVED_POLICY_SHA256, $evaluation->policy_definition_sha256);
    }

    /** @param array<string, mixed> $payload */
    private function recordedEventMatches(
        TradeOpportunity $opportunity,
        TradingEngineOpportunityLink $link,
        TradingEngineEvent $event,
        array $payload,
    ): bool {
        $source = $this->arrayValue($payload['source'] ?? null);
        $subject = $this->arrayValue($payload['subject'] ?? null);
        $network = $this->arrayValue($payload['network'] ?? null);
        $asset = $this->arrayValue($payload['asset'] ?? null);

        return $source !== null
            && $subject !== null
            && $network !== null
            && $asset !== null
            && $event->event_type === 'opportunity.recorded.v1'
            && $event->schema_version === 1
            && $event->producer === 'trading-engine'
            && $event->aggregate_type === 'opportunity'
            && $event->aggregate_version === 1
            && $event->aggregate_id === $link->engine_opportunity_id
            && $event->event_id === $link->recorded_event_id
            && $event->causation_id === ($payload['operation_id'] ?? null)
            && $event->idempotency_key === 'opportunity:record:laravel:'.$opportunity->getKey().':v1'
            && hash_equals($event->payload_sha256, $this->canonicalHash($payload))
            && ($payload['opportunity_id'] ?? null) === $link->engine_opportunity_id
            && ($source['opportunity_id'] ?? null) === (string) $opportunity->getKey()
            && ($source['discovery_key'] ?? null) === $link->discovery_key
            && ($source['scanner'] ?? null) === $link->scanner
            && ($subject['control_plane_user_id'] ?? null) === (string) $link->user_id
            && ($network['id'] ?? null) === $link->network_id
            && ($asset['address'] ?? null) === $link->asset_address;
    }

    /** @param array<string, mixed> $payload */
    private function evaluationEventMatches(
        TradingEngineOpportunityLink $link,
        TradingEngineOpportunityEvaluation $evaluation,
        TradingEngineEvent $recordedEvent,
        TradingEngineEvent $event,
        array $payload,
    ): bool {
        $policy = $this->arrayValue($payload['policy'] ?? null);
        $source = $this->arrayValue($payload['source'] ?? null);

        return $policy !== null
            && $source !== null
            && $event->event_type === 'opportunity.evaluated.v1'
            && $event->schema_version === 1
            && $event->producer === 'trading-engine'
            && $event->aggregate_type === 'opportunity_evaluation'
            && $event->aggregate_version === 1
            && $event->aggregate_id === $evaluation->evaluation_id
            && $event->event_id === $evaluation->evaluation_event_id
            && $event->causation_id === $recordedEvent->event_id
            && $evaluation->recorded_event_id === $recordedEvent->event_id
            && $event->idempotency_key === 'evaluation:'.$link->engine_opportunity_id.':'.self::APPROVED_POLICY_KEY.':'.self::APPROVED_POLICY_VERSION
            && hash_equals($event->payload_sha256, $this->canonicalHash($payload))
            && ($payload['evaluation_id'] ?? null) === $evaluation->evaluation_id
            && ($payload['opportunity_id'] ?? null) === $evaluation->engine_opportunity_id
            && ($policy['key'] ?? null) === $evaluation->policy_key
            && ($policy['version'] ?? null) === $evaluation->policy_version
            && ($policy['algorithm_key'] ?? null) === $evaluation->algorithm_key
            && ($policy['algorithm_version'] ?? null) === $evaluation->algorithm_version
            && ($policy['definition_sha256'] ?? null) === $evaluation->policy_definition_sha256
            && ($source['request_sha256'] ?? null) === $evaluation->source_request_sha256
            && ($source['evaluation_input_sha256'] ?? null) === $evaluation->evaluation_input_sha256
            && ($payload['outcome'] ?? null) === $evaluation->outcome
            && ($payload['reason_codes'] ?? null) === $evaluation->reason_codes
            && ($payload['advisory_codes'] ?? null) === $evaluation->advisory_codes
            && ($payload['evidence'] ?? null) === $evaluation->evidence
            && ($payload['result_sha256'] ?? null) === $evaluation->result_sha256;
    }

    /** @return array<string, mixed>|null */
    private function arrayValue(mixed $value): ?array
    {
        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    /** @throws JsonException */
    private function canonicalHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalValue($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalValue(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }

    private function ineligible(string $reasonCode): TradingEngineEvaluationConsumptionDecision
    {
        return TradingEngineEvaluationConsumptionDecision::ineligible($reasonCode);
    }
}
