<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Jobs\SubmitTradingEnginePaperObservation;
use App\Models\PaperPosition;
use App\Models\TradingEnginePaperObservation;
use App\Models\TradingEnginePaperPositionLink;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TradingEnginePaperObservationRecovery
{
    private const NETWORK_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    private const RECOVERABLE_ERRORS = [
        'UNEXPECTED_RESPONSE',
        'TRANSPORT_TIMEOUT',
        'TRANSPORT_FAILURE',
        'ENGINE_UNAVAILABLE',
        'DEPENDENCY_UNAVAILABLE',
        'OBSERVATION_DISPATCH_FAILED',
        'OBSERVATION_SUBMISSION_EXHAUSTED',
    ];

    public function __construct(
        private Dispatcher $dispatcher,
        private TradingEngineCanonicalJson $canonicalJson,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     error_code: string|null,
     *     observation_row_id: int|null,
     *     paper_position_id: int|null,
     *     observation_id: string|null,
     *     sequence: int|null,
     *     idempotency_key: string|null,
     *     previous_error: string|null
     * }
     */
    public function recover(int $observationId, string $expectedError): array
    {
        if (! $this->enabled()) {
            return $this->blocked('PAPER_OBSERVATION_RECOVERY_DISABLED');
        }

        $prepared = DB::transaction(function () use ($observationId, $expectedError): array {
            $observation = TradingEnginePaperObservation::query()
                ->whereKey($observationId)
                ->lockForUpdate()
                ->first();

            if (! $observation instanceof TradingEnginePaperObservation) {
                return $this->blocked('PAPER_OBSERVATION_NOT_FOUND');
            }

            $context = $this->context($observation);
            $storedRecoveryError = match ($observation->status) {
                'failed' => $observation->error_code,
                'pending', 'submitted', 'evaluated' => $observation->recovery_previous_error_code,
                default => null,
            };

            if (! in_array($observation->status, ['failed', 'pending', 'submitted', 'evaluated'], true)) {
                return $this->blocked('PAPER_OBSERVATION_STATUS_NOT_RECOVERABLE', $context);
            }

            if ($observation->status !== 'failed'
                && ($observation->recovery_requested_at === null
                    || ! is_string($storedRecoveryError)
                    || $storedRecoveryError === '')) {
                return $this->blocked('PAPER_OBSERVATION_RECOVERY_PROVENANCE_INVALID', $context);
            }

            if ($observation->status === 'pending'
                && (! is_string($observation->recovery_token) || $observation->recovery_token === '')) {
                return $this->blocked('PAPER_OBSERVATION_RECOVERY_PROVENANCE_INVALID', $context);
            }

            if ($storedRecoveryError !== $expectedError) {
                return $this->blocked('PAPER_OBSERVATION_EXPECTED_ERROR_MISMATCH', $context);
            }

            if (! in_array($storedRecoveryError, self::RECOVERABLE_ERRORS, true)) {
                return $this->blocked('PAPER_OBSERVATION_ERROR_NOT_RECOVERABLE', $context);
            }

            $alreadyProcessed = in_array($observation->status, ['submitted', 'evaluated'], true);
            $validationError = $this->validate($observation, ! $alreadyProcessed);

            if ($validationError !== null) {
                return $this->blocked($validationError, $context);
            }

            if ($alreadyProcessed) {
                return [
                    ...$context,
                    'status' => 'already_processed',
                    'error_code' => null,
                ];
            }

            $token = Str::uuid()->toString();
            $observation->forceFill([
                'status' => 'pending',
                'error_code' => null,
                'recovery_token' => $token,
                'recovery_previous_error_code' => $expectedError,
                'recovery_requested_at' => now(),
                'recovered_at' => null,
            ])->save();

            return [
                ...$context,
                'status' => 'prepared',
                'error_code' => null,
                'recovery_token' => $token,
                'payload' => $observation->payload,
            ];
        }, 3);

        if ($prepared['status'] !== 'prepared') {
            return $prepared;
        }

        try {
            $this->dispatcher->dispatch(new SubmitTradingEnginePaperObservation(
                $prepared['observation_row_id'],
                $prepared['payload'],
                $prepared['idempotency_key'],
                $prepared['recovery_token'],
            ));
        } catch (Throwable) {
            TradingEnginePaperObservation::query()
                ->whereKey($prepared['observation_row_id'])
                ->where('status', 'pending')
                ->where('recovery_token', $prepared['recovery_token'])
                ->update([
                    'status' => 'failed',
                    'error_code' => 'OBSERVATION_DISPATCH_FAILED',
                    'recovery_token' => null,
                ]);

            $result = [
                ...$prepared,
                'status' => 'failed',
                'error_code' => 'PAPER_OBSERVATION_RECOVERY_DISPATCH_FAILED',
            ];
            unset($result['recovery_token']);
            unset($result['payload']);
            $this->logResult($result);

            return $result;
        }

        $result = [
            ...$prepared,
            'status' => 'dispatched',
            'error_code' => null,
        ];
        unset($result['recovery_token']);
        unset($result['payload']);
        $this->logResult($result);

        return $result;
    }

    private function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true;
    }

    private function validate(TradingEnginePaperObservation $observation, bool $requireRecoverableState): ?string
    {
        $link = TradingEnginePaperPositionLink::query()
            ->whereKey($observation->position_link_id)
            ->lockForUpdate()
            ->first();

        if (! $link instanceof TradingEnginePaperPositionLink) {
            return 'PAPER_OBSERVATION_LINK_NOT_FOUND';
        }

        $position = PaperPosition::query()
            ->whereKey($observation->paper_position_id)
            ->lockForUpdate()
            ->first();

        if (! $position instanceof PaperPosition) {
            return 'PAPER_OBSERVATION_POSITION_NOT_FOUND';
        }

        $payload = $observation->payload;

        if (! is_array($payload)
            || $observation->payload_sha256 !== $this->canonicalJson->hash($payload)) {
            return 'PAPER_OBSERVATION_PAYLOAD_HASH_MISMATCH';
        }

        $sequence = data_get($payload, 'source.sequence');
        $paperPositionId = data_get($payload, 'source.paper_position_id');
        $userId = data_get($payload, 'subject.control_plane_user_id');
        $observationId = data_get($payload, 'source.observation_id');

        if (($requireRecoverableState && $link->ownership_state !== 'registered')
            || (! $requireRecoverableState && ! in_array($link->ownership_state, ['registered', 'terminal'], true))
            || $link->engine_position_id === null
            || data_get($link->ownership_snapshot, 'owner') !== 'trading-engine'
            || data_get($link->ownership_snapshot, 'authoritative') !== true
            || ($requireRecoverableState && $position->status !== 'open')
            || (! $requireRecoverableState && ! in_array($position->status, ['open', 'closed'], true))
            || $position->chain !== Chain::Solana
            || $link->chain !== Chain::Solana->value
            || $link->paper_position_id !== $position->getKey()
            || $link->paper_position_id !== $observation->paper_position_id
            || $link->user_id !== $position->user_id
            || (string) $position->getKey() !== $paperPositionId
            || (string) $position->user_id !== $userId
            || $link->engine_position_id !== data_get($payload, 'position_id')
            || $link->asset_address !== $position->address
            || $position->address !== data_get($payload, 'asset.address')
            || data_get($payload, 'network.id') !== self::NETWORK_ID
            || data_get($payload, 'validation.status') !== 'eligible'
            || data_get($payload, 'validation.identity_verified') !== true
            || data_get($payload, 'validation.simulation_allowed') !== true) {
            return 'PAPER_OBSERVATION_IDENTITY_MISMATCH';
        }

        if (! is_int($sequence)
            || $sequence < 1
            || ! is_string($observationId)
            || $observationId !== $observation->observation_id
            || $observation->observation_sequence !== $sequence
            || $observation->observation_id !== 'paper-position-'.$position->getKey().'-observation-'.$sequence
            || $observation->idempotency_key !== 'paper:position:observe:laravel:'.$position->getKey().':'.$sequence.':v1') {
            return 'PAPER_OBSERVATION_IDENTITY_MISMATCH';
        }

        if (! $requireRecoverableState) {
            if ($observation->engine_decision_id === null
                || ! in_array($observation->engine_decision, ['HOLD', 'EXIT'], true)) {
                return 'PAPER_OBSERVATION_ALREADY_PROCESSED_INVALID';
            }

            return null;
        }

        if ($link->next_observation_sequence !== $sequence + 1) {
            return 'PAPER_OBSERVATION_SEQUENCE_NOT_RECOVERABLE';
        }

        if (TradingEnginePaperObservation::query()
            ->where('position_link_id', $link->getKey())
            ->where('observation_sequence', '>', $sequence)
            ->exists()) {
            return 'PAPER_OBSERVATION_LATER_SEQUENCE_EXISTS';
        }

        if ($observation->engine_decision_id !== null
            || $observation->engine_decision !== null
            || $observation->submitted_at !== null) {
            return 'PAPER_OBSERVATION_ALREADY_ACKNOWLEDGED';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     status: string,
     *     error_code: string,
     *     observation_row_id: int|null,
     *     paper_position_id: int|null,
     *     observation_id: string|null,
     *     sequence: int|null,
     *     idempotency_key: string|null,
     *     previous_error: string|null
     * }
     */
    private function blocked(string $errorCode, array $context = []): array
    {
        return [
            'status' => 'blocked',
            'error_code' => $errorCode,
            'observation_row_id' => $context['observation_row_id'] ?? null,
            'paper_position_id' => $context['paper_position_id'] ?? null,
            'observation_id' => $context['observation_id'] ?? null,
            'sequence' => $context['sequence'] ?? null,
            'idempotency_key' => $context['idempotency_key'] ?? null,
            'previous_error' => $context['previous_error'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function context(TradingEnginePaperObservation $observation): array
    {
        return [
            'observation_row_id' => $observation->getKey(),
            'paper_position_id' => $observation->paper_position_id,
            'observation_id' => $observation->observation_id,
            'sequence' => $observation->observation_sequence,
            'idempotency_key' => $observation->idempotency_key,
            'previous_error' => $observation->error_code ?? $observation->recovery_previous_error_code,
        ];
    }

    /** @param array<string, mixed> $result */
    private function logResult(array $result): void
    {
        Log::notice('Failed PAPER lifecycle observation recovery processed.', [
            'observation_row_id' => $result['observation_row_id'],
            'paper_position_id' => $result['paper_position_id'],
            'observation_id' => $result['observation_id'],
            'sequence' => $result['sequence'],
            'previous_error' => $result['previous_error'],
            'status' => $result['status'],
            'error_code' => $result['error_code'],
        ]);
    }
}
