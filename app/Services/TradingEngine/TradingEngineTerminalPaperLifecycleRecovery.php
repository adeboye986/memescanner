<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Jobs\ProjectTradingEngineEvent;
use App\Models\PaperPosition;
use App\Models\TradingEngineEvent;
use App\Models\TradingEnginePaperObservation;
use App\Models\TradingEnginePaperPositionLink;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TradingEngineTerminalPaperLifecycleRecovery
{
    private const NETWORK_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    private const RECOVERABLE_ERRORS = [
        'paper.position.recorded.v1' => 'PAPER_POSITION_LINK_IDENTITY_MISMATCH',
        'paper.position.evaluated.v1' => 'PAPER_LIFECYCLE_CORRELATION_MISMATCH',
    ];

    public function __construct(
        private Dispatcher $dispatcher,
        private TradingEngineOpportunityProjector $projector,
        private TradingEngineProjectionEligibility $eligibility,
        private TradingEngineCanonicalJson $canonicalJson,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     error_code: string|null,
     *     inbox_id: int|null,
     *     event_type: string|null,
     *     paper_position_id: int|null,
     *     user_id: int|null,
     *     previous_error: string|null
     * }
     */
    public function recover(string $eventId, string $expectedError): array
    {
        if (! $this->enabled()) {
            return $this->blocked('TERMINAL_PAPER_RECOVERY_DISABLED');
        }

        $prepared = DB::transaction(function () use ($eventId, $expectedError): array {
            $event = TradingEngineEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if (! $event instanceof TradingEngineEvent) {
                return $this->blocked('TERMINAL_PAPER_EVENT_NOT_FOUND');
            }

            $context = $this->context($event);

            if ($event->handling_status !== TradingEngineEvent::STATUS_FAILED) {
                return $this->blocked('TERMINAL_PAPER_STATUS_NOT_FAILED', $context);
            }

            $recoverableError = self::RECOVERABLE_ERRORS[$event->event_type] ?? null;

            if ($recoverableError === null) {
                return $this->blocked('TERMINAL_PAPER_EVENT_TYPE_UNSUPPORTED', $context);
            }

            if ($event->handling_error_code !== $expectedError) {
                return $this->blocked('TERMINAL_PAPER_EXPECTED_ERROR_MISMATCH', $context);
            }

            if ($event->handling_error_code !== $recoverableError) {
                return $this->blocked('TERMINAL_PAPER_ERROR_UNSUPPORTED', $context);
            }

            $validationError = $this->validateLifecycleState($event);

            if ($validationError !== null) {
                return $this->blocked($validationError, $context);
            }

            $now = now();
            $event->forceFill([
                'handling_status' => TradingEngineEvent::STATUS_RETRYABLE,
                'next_handling_at' => $this->eligibility->leaseUntil($now),
                'handled_at' => null,
                'updated_at' => $now,
            ])->save();

            return [
                ...$context,
                'status' => 'prepared',
                'error_code' => null,
            ];
        }, 3);

        if ($prepared['status'] !== 'prepared') {
            return $prepared;
        }

        try {
            $this->dispatcher->dispatch(new ProjectTradingEngineEvent($eventId));
        } catch (Throwable) {
            $this->projector->markDispatchFailure($eventId);
            $result = [
                ...$prepared,
                'status' => 'failed',
                'error_code' => 'TERMINAL_PAPER_DISPATCH_FAILED',
            ];
            $this->logResult($result);

            return $result;
        }

        $result = [
            ...$prepared,
            'status' => 'dispatched',
            'error_code' => null,
        ];
        $this->logResult($result);

        return $result;
    }

    private function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true;
    }

    private function validateLifecycleState(TradingEngineEvent $event): ?string
    {
        $payload = $event->payload;

        if (! is_array($payload)
            || ! is_array($payload['source'] ?? null)
            || ! is_array($payload['subject'] ?? null)
            || ! is_array($payload['network'] ?? null)
            || ! is_array($payload['asset'] ?? null)) {
            return 'TERMINAL_PAPER_PAYLOAD_INVALID';
        }

        if ($event->payload_sha256 !== $this->canonicalJson->hash($payload)) {
            return 'TERMINAL_PAPER_PAYLOAD_HASH_MISMATCH';
        }

        $paperPositionId = $this->positiveInteger($payload['source']['paper_position_id'] ?? null);
        $userId = $this->positiveInteger($payload['subject']['control_plane_user_id'] ?? null);

        if ($paperPositionId === null || $userId === null) {
            return 'TERMINAL_PAPER_PAYLOAD_INVALID';
        }

        if (! in_array((string) $userId, $this->canaryUserIds(), true)) {
            return 'TERMINAL_PAPER_USER_NOT_CANARY';
        }

        $position = PaperPosition::query()->whereKey($paperPositionId)->lockForUpdate()->first();

        if (! $position instanceof PaperPosition) {
            return 'TERMINAL_PAPER_POSITION_NOT_FOUND';
        }

        $link = TradingEnginePaperPositionLink::query()
            ->where('paper_position_id', $paperPositionId)
            ->lockForUpdate()
            ->first();

        if (! $link instanceof TradingEnginePaperPositionLink) {
            return 'TERMINAL_PAPER_LINK_NOT_FOUND';
        }

        if ($position->user_id === null
            || $position->user_id !== $userId
            || $link->user_id !== $userId
            || $link->paper_position_id !== $paperPositionId
            || $position->chain !== Chain::Solana
            || $link->chain !== Chain::Solana->value
            || $payload['network']['id'] !== self::NETWORK_ID
            || $position->address !== ($payload['asset']['address'] ?? null)
            || $link->asset_address !== ($payload['asset']['address'] ?? null)
            || $link->engine_position_id === null
            || $link->engine_position_id !== ($payload['position_id'] ?? null)
            || $event->aggregate_id !== $payload['position_id']) {
            return 'TERMINAL_PAPER_IDENTITY_MISMATCH';
        }

        return match ($event->event_type) {
            'paper.position.recorded.v1' => $this->validateRegistration($event, $link, $payload),
            'paper.position.evaluated.v1' => $this->validateObservation($event, $link, $payload),
            default => 'TERMINAL_PAPER_EVENT_TYPE_UNSUPPORTED',
        };
    }

    /** @param array<string, mixed> $payload */
    private function validateRegistration(
        TradingEngineEvent $event,
        TradingEnginePaperPositionLink $link,
        array $payload,
    ): ?string {
        $registration = $link->registration_payload;
        $tradeOpportunityId = $this->positiveInteger($payload['source']['trade_opportunity_id'] ?? null);

        if (! is_array($registration)
            || $link->registration_payload_sha256 !== $this->canonicalJson->hash($registration)) {
            return 'TERMINAL_PAPER_REGISTRATION_SNAPSHOT_INVALID';
        }

        if ($tradeOpportunityId === null
            || $link->trade_opportunity_id !== $tradeOpportunityId
            || $link->engine_opportunity_id !== ($payload['source']['engine_opportunity_id'] ?? null)
            || $event->aggregate_version !== 1
            || $event->idempotency_key !== $link->registration_idempotency_key) {
            return 'TERMINAL_PAPER_IDENTITY_MISMATCH';
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function validateObservation(
        TradingEngineEvent $event,
        TradingEnginePaperPositionLink $link,
        array $payload,
    ): ?string {
        $sequence = $payload['source']['sequence'] ?? null;
        $observationId = $payload['source']['observation_id'] ?? null;

        if (! is_int($sequence) || $sequence < 1 || ! is_string($observationId) || $observationId === '') {
            return 'TERMINAL_PAPER_PAYLOAD_INVALID';
        }

        $observation = TradingEnginePaperObservation::query()
            ->where('position_link_id', $link->getKey())
            ->where('observation_id', $observationId)
            ->where('observation_sequence', $sequence)
            ->lockForUpdate()
            ->first();

        if (! $observation instanceof TradingEnginePaperObservation
            || $observation->paper_position_id !== $link->paper_position_id) {
            return 'TERMINAL_PAPER_OBSERVATION_NOT_FOUND';
        }

        if (! is_array($observation->payload)
            || $observation->payload_sha256 !== $this->canonicalJson->hash($observation->payload)) {
            return 'TERMINAL_PAPER_OBSERVATION_SNAPSHOT_INVALID';
        }

        if ($event->aggregate_version !== $sequence
            || $event->idempotency_key !== $observation->idempotency_key) {
            return 'TERMINAL_PAPER_IDENTITY_MISMATCH';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     status: string,
     *     error_code: string,
     *     inbox_id: int|null,
     *     event_type: string|null,
     *     paper_position_id: int|null,
     *     user_id: int|null,
     *     previous_error: string|null
     * }
     */
    private function blocked(string $errorCode, array $context = []): array
    {
        return [
            'status' => 'blocked',
            'error_code' => $errorCode,
            'inbox_id' => $context['inbox_id'] ?? null,
            'event_type' => $context['event_type'] ?? null,
            'paper_position_id' => $context['paper_position_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'previous_error' => $context['previous_error'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function context(TradingEngineEvent $event): array
    {
        return [
            'inbox_id' => $event->getKey(),
            'event_type' => $event->event_type,
            'paper_position_id' => $this->positiveInteger(data_get($event->payload, 'source.paper_position_id')),
            'user_id' => $this->positiveInteger(data_get($event->payload, 'subject.control_plane_user_id')),
            'previous_error' => $event->handling_error_code,
        ];
    }

    /** @return array<int, string> */
    private function canaryUserIds(): array
    {
        $configured = config('services.trading_engine.paper_lifecycle_canary_user_ids', '');

        if (! is_string($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $configured)),
            fn (string $value): bool => preg_match('/^[1-9][0-9]*$/D', $value) === 1,
        )));
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            ? (int) $value
            : null;
    }

    /** @param array<string, mixed> $result */
    private function logResult(array $result): void
    {
        Log::notice('Terminal PAPER lifecycle projection recovery processed.', [
            'inbox_id' => $result['inbox_id'],
            'event_type' => $result['event_type'],
            'paper_position_id' => $result['paper_position_id'],
            'user_id' => $result['user_id'],
            'previous_error' => $result['previous_error'],
            'status' => $result['status'],
            'error_code' => $result['error_code'],
        ]);
    }
}
