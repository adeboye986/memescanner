<?php

namespace App\Services\TradingEngine;

use App\Exceptions\TradingEngineWebhookException;
use App\Models\TradingEngineEvent;
use DateTimeImmutable;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Throwable;

class TradingEngineEventEnvelopeValidator
{
    public function __construct(
        private TradingEngineOpportunityPayloadValidator $opportunityPayloads,
        private TradingEngineOpportunityEvaluationPayloadValidator $opportunityEvaluations,
    ) {}

    private const REQUIRED_KEYS = [
        'event_id',
        'event_type',
        'schema_version',
        'occurred_at',
        'producer',
        'aggregate_type',
        'aggregate_id',
        'aggregate_version',
        'correlation_id',
        'causation_id',
        'idempotency_key',
        'traceparent',
        'payload',
        'payload_sha256',
    ];

    /** @return array{envelope: array<string, mixed>, handling_status: string} */
    public function validate(string $rawBody, Request $request): array
    {
        try {
            $object = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
            $envelope = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TradingEngineWebhookException(
                'MALFORMED_JSON',
                'The trading engine webhook body is not valid JSON.',
                400,
            );
        }

        if (! $object instanceof stdClass || ! is_array($envelope)) {
            $this->rejectEnvelope();
        }

        $keys = array_keys($envelope);
        sort($keys);
        $requiredKeys = self::REQUIRED_KEYS;
        sort($requiredKeys);

        if ($keys !== $requiredKeys
            || ! $this->hasStringLength($envelope['event_id'] ?? null, 26, 26)
            || ! $this->matches($envelope['event_type'] ?? null, '/^[a-z][a-z0-9_.-]+\.v[1-9][0-9]*$/D')
            || ! $this->isPositiveInteger($envelope['schema_version'] ?? null)
            || ! $this->isDateTime($envelope['occurred_at'] ?? null)
            || ($envelope['producer'] ?? null) !== 'trading-engine'
            || ! $this->hasStringLength($envelope['aggregate_type'] ?? null, 1, 64)
            || ! $this->hasStringLength($envelope['aggregate_id'] ?? null, 26, 26)
            || ! $this->isPositiveInteger($envelope['aggregate_version'] ?? null)
            || ! $this->hasStringLength($envelope['correlation_id'] ?? null, 1, 128)
            || ! $this->hasStringLength($envelope['causation_id'] ?? null, 1, 128)
            || ! $this->hasStringLength($envelope['idempotency_key'] ?? null, 1, 128)
            || ! $this->matches($envelope['traceparent'] ?? null, '/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/D')
            || ! $object->payload instanceof stdClass
            || ! $this->matches($envelope['payload_sha256'] ?? null, '/^[0-9a-f]{64}$/D')) {
            $this->rejectEnvelope();
        }

        if (! hash_equals($envelope['event_id'], (string) $request->header('X-Engine-Event-Id', ''))
            || ! hash_equals($envelope['correlation_id'], (string) $request->header('X-Correlation-Id', ''))
            || ! hash_equals($envelope['traceparent'], (string) $request->header('traceparent', ''))) {
            throw new TradingEngineWebhookException(
                'EVENT_HEADER_MISMATCH',
                'The trading engine webhook headers do not match the event envelope.',
                422,
            );
        }

        $handlingStatus = TradingEngineEvent::STATUS_UNHANDLED;

        if ($envelope['event_type'] === 'foundation.noop_accepted.v1') {
            $this->validateNoopAccepted($envelope);
            $handlingStatus = TradingEngineEvent::STATUS_STORED;
        }

        if ($envelope['event_type'] === 'opportunity.recorded.v1') {
            $this->validateOpportunityRecorded($envelope);
            $handlingStatus = TradingEngineEvent::STATUS_STORED;
        }

        if ($envelope['event_type'] === 'opportunity.evaluated.v1') {
            $this->validateOpportunityEvaluated($envelope);
            $handlingStatus = TradingEngineEvent::STATUS_STORED;
        }

        return [
            'envelope' => $envelope,
            'handling_status' => $handlingStatus,
        ];
    }

    /** @param array<string, mixed> $envelope */
    private function validateNoopAccepted(array $envelope): void
    {
        $payload = $envelope['payload'];
        $payloadKeys = is_array($payload) ? array_keys($payload) : [];
        sort($payloadKeys);

        if ($payloadKeys !== ['accepted_by', 'message', 'operation_id']
            || ! $this->hasStringLength($payload['operation_id'] ?? null, 26, 26)
            || ! $this->hasStringLength($payload['accepted_by'] ?? null, 1, 255)
            || (! is_null($payload['message'] ?? null) && ! $this->hasStringLength($payload['message'], 0, 256))
            || $envelope['schema_version'] !== 1
            || $envelope['aggregate_type'] !== 'foundation_command'
            || $envelope['aggregate_version'] !== 1
            || $payload['operation_id'] !== $envelope['aggregate_id']
            || $envelope['causation_id'] !== $envelope['aggregate_id']) {
            $this->rejectEnvelope();
        }
    }

    /** @param array<string, mixed> $envelope */
    private function validateOpportunityRecorded(array $envelope): void
    {
        $payload = $envelope['payload'];

        if (! is_array($payload)
            || ! $this->opportunityPayloads->isValid($payload)
            || $envelope['schema_version'] !== 1
            || $envelope['aggregate_type'] !== 'opportunity'
            || $envelope['aggregate_version'] !== 1
            || $payload['opportunity_id'] !== $envelope['aggregate_id']
            || $payload['operation_id'] !== $envelope['causation_id']) {
            $this->rejectEnvelope();
        }
    }

    /** @param array<string, mixed> $envelope */
    private function validateOpportunityEvaluated(array $envelope): void
    {
        $payload = $envelope['payload'];

        if (! is_array($payload)
            || ! $this->opportunityEvaluations->isValid($payload)
            || $envelope['schema_version'] !== 1
            || $envelope['aggregate_type'] !== 'opportunity_evaluation'
            || $envelope['aggregate_version'] !== 1
            || $payload['evaluation_id'] !== $envelope['aggregate_id']) {
            $this->rejectEnvelope();
        }
    }

    private function hasStringLength(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && mb_strlen($value) >= $minimum && mb_strlen($value) <= $maximum;
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value >= 1;
    }

    private function isDateTime(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            $errors = DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (Throwable) {
            return false;
        }
    }

    private function rejectEnvelope(): never
    {
        throw new TradingEngineWebhookException(
            'EVENT_ENVELOPE_INVALID',
            'The trading engine event envelope is invalid.',
            422,
        );
    }
}
