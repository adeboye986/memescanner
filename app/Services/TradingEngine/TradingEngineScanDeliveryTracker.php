<?php

namespace App\Services\TradingEngine;

use App\Jobs\SubmitTradingEngineTokenScan;
use App\Models\SystemActivity;
use App\Models\TradingEngineScanRequest;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

class TradingEngineScanDeliveryTracker
{
    public const MAX_DELIVERY_ATTEMPTS = 5;

    public const MAX_RECOVERY_DISPATCHES = 5;

    private const STALE_ATTEMPT_AFTER_SECONDS = 120;

    private const RETRY_RECOVERY_AFTER_SECONDS = 600;

    public function __construct(
        private Dispatcher $bus,
        private TradingEngineCanonicalJson $canonicalJson,
    ) {}

    /** @param array{idempotency_key: string, entry_mode: string, payload: array<string, mixed>} $command */
    public function queue(SystemActivity $activity, array $command): TradingEngineScanRequest
    {
        $this->assertDatabaseQueueIsAtomic();

        if (! DB::connection()->transactionLevel()) {
            throw new LogicException('Scan activity, delivery record, and queue job must be created in one transaction.');
        }

        $existing = TradingEngineScanRequest::query()
            ->where('system_activity_id', $activity->getKey())
            ->lockForUpdate()
            ->first();

        if ($existing instanceof TradingEngineScanRequest) {
            return $existing;
        }

        $request = TradingEngineScanRequest::query()->create([
            'system_activity_id' => $activity->getKey(),
            'user_id' => $activity->user_id,
            'scanner' => data_get($command, 'payload.scanner'),
            'network_id' => data_get($command, 'payload.network.id'),
            'entry_mode' => $command['entry_mode'],
            'idempotency_key' => $command['idempotency_key'],
            'payload_sha256' => $this->canonicalJson->hash($command['payload']),
            'payload' => $command['payload'],
            'status' => TradingEngineScanRequest::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
        $this->dispatch($request);

        return $request;
    }

    public function beginAttempt(TradingEngineScanRequest $request): ?int
    {
        return DB::transaction(function () use ($request): ?int {
            $locked = TradingEngineScanRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (in_array($locked->status, [
                TradingEngineScanRequest::STATUS_ACCEPTED,
                TradingEngineScanRequest::STATUS_PROJECTED,
                TradingEngineScanRequest::STATUS_FAILED,
            ], true) || $locked->attempt_count >= self::MAX_DELIVERY_ATTEMPTS) {
                return null;
            }

            if ($locked->status === TradingEngineScanRequest::STATUS_SUBMITTING
                && $locked->submitting_at?->isAfter(now()->subSeconds(self::STALE_ATTEMPT_AFTER_SECONDS))) {
                return null;
            }

            if (! in_array($locked->status, [
                TradingEngineScanRequest::STATUS_PENDING,
                TradingEngineScanRequest::STATUS_QUEUED,
                TradingEngineScanRequest::STATUS_SUBMITTING,
                TradingEngineScanRequest::STATUS_RETRYING,
                TradingEngineScanRequest::STATUS_ACCEPTANCE_UNKNOWN,
            ], true)) {
                throw new LogicException('The scan request cannot begin an attempt from its current state.');
            }

            $attempt = $locked->attempt_count + 1;
            $locked->forceFill([
                'status' => TradingEngineScanRequest::STATUS_SUBMITTING,
                'attempt_count' => $attempt,
                'last_error_code' => null,
                'submitting_at' => now(),
                'last_attempt_at' => now(),
            ])->save();

            return $attempt;
        }, 3);
    }

    /** @param array{operationId: string, scanExecutionId: string, status: string, duplicate: bool} $response */
    public function acknowledged(TradingEngineScanRequest $request, array $response, int $attempt): void
    {
        $this->attemptTransition($request, $attempt, function (TradingEngineScanRequest $locked) use ($response): void {
            if (($locked->engine_operation_id !== null && $locked->engine_operation_id !== $response['operationId'])
                || ($locked->engine_scan_execution_id !== null && $locked->engine_scan_execution_id !== $response['scanExecutionId'])) {
                throw new LogicException('The scan acknowledgement conflicts with persisted engine identity.');
            }

            $locked->forceFill([
                'engine_operation_id' => $response['operationId'],
                'engine_scan_execution_id' => $response['scanExecutionId'],
                'status' => TradingEngineScanRequest::STATUS_ACCEPTED,
                'last_error_code' => null,
                'acceptance_unknown_at' => null,
                'accepted_at' => now(),
            ])->save();
            $locked->activity()->where('status', 'pending')->update([
                'status' => 'running',
                'started_at' => now(),
                'result_summary' => [
                    'kind' => 'scanner_execution',
                    'owner' => 'trading-engine',
                    'scanner' => $locked->scanner,
                    'network_id' => $locked->network_id,
                    'scan_execution_id' => $response['scanExecutionId'],
                ],
            ]);
        });
    }

    public function retrying(TradingEngineScanRequest $request, string $code, int $attempt): void
    {
        $this->attemptTransition($request, $attempt, function (TradingEngineScanRequest $locked) use ($code): void {
            $locked->forceFill([
                'status' => TradingEngineScanRequest::STATUS_RETRYING,
                'last_error_code' => $this->safeCode($code),
            ])->save();
        });
    }

    public function rejected(TradingEngineScanRequest $request, string $code, int $attempt): void
    {
        $this->attemptTransition($request, $attempt, function (TradingEngineScanRequest $locked) use ($code): void {
            $locked->forceFill([
                'status' => TradingEngineScanRequest::STATUS_FAILED,
                'last_error_code' => $this->safeCode($code),
                'definitively_rejected_at' => now(),
            ])->save();
            $this->failActivity($locked, $code);
        });
    }

    public function acceptanceUnknown(TradingEngineScanRequest $request, string $code, ?int $attempt): void
    {
        DB::transaction(function () use ($request, $code, $attempt): void {
            $locked = TradingEngineScanRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (in_array($locked->status, [
                TradingEngineScanRequest::STATUS_ACCEPTED,
                TradingEngineScanRequest::STATUS_PROJECTED,
                TradingEngineScanRequest::STATUS_FAILED,
            ], true)) {
                return;
            }

            if ($attempt !== null && ($locked->attempt_count !== $attempt
                || ! in_array($locked->status, [
                    TradingEngineScanRequest::STATUS_SUBMITTING,
                    TradingEngineScanRequest::STATUS_RETRYING,
                ], true))) {
                return;
            }

            $locked->forceFill([
                'status' => TradingEngineScanRequest::STATUS_ACCEPTANCE_UNKNOWN,
                'last_error_code' => $this->safeCode($code),
                'acceptance_unknown_at' => now(),
            ])->save();
        }, 3);
    }

    public function recover(int $limit = 100): int
    {
        $this->assertDatabaseQueueIsAtomic();
        $boundedLimit = max(1, min($limit, 500));

        return DB::transaction(function () use ($boundedLimit): int {
            $staleAttempt = now()->subSeconds(self::STALE_ATTEMPT_AFTER_SECONDS);
            $staleRetry = now()->subSeconds(self::RETRY_RECOVERY_AFTER_SECONDS);
            $requests = TradingEngineScanRequest::query()
                ->where('attempt_count', '<', self::MAX_DELIVERY_ATTEMPTS)
                ->where('recovery_count', '<', self::MAX_RECOVERY_DISPATCHES)
                ->where(function (Builder $query) use ($staleAttempt, $staleRetry): void {
                    $query->where(function (Builder $candidate) use ($staleAttempt): void {
                        $candidate->whereIn('status', ['pending', 'queued', 'acceptance_unknown'])
                            ->where('updated_at', '<=', $staleAttempt);
                    })->orWhere(function (Builder $candidate) use ($staleAttempt): void {
                        $candidate->where('status', 'submitting')->where('submitting_at', '<=', $staleAttempt);
                    })->orWhere(function (Builder $candidate) use ($staleRetry): void {
                        $candidate->where('status', 'retrying')->where('updated_at', '<=', $staleRetry);
                    });
                })
                ->where(function (Builder $query) use ($staleAttempt): void {
                    $query->whereNull('last_recovered_at')->orWhere('last_recovered_at', '<=', $staleAttempt);
                })
                ->orderBy('id')->limit($boundedLimit)->lockForUpdate()->get();

            foreach ($requests as $request) {
                $request->forceFill([
                    'recovery_count' => $request->recovery_count + 1,
                    'last_recovered_at' => now(),
                ])->save();
                $this->dispatch($request);
            }

            return $requests->count();
        }, 3);
    }

    /** @param Closure(TradingEngineScanRequest): void $transition */
    private function attemptTransition(TradingEngineScanRequest $request, int $attempt, Closure $transition): void
    {
        DB::transaction(function () use ($request, $attempt, $transition): void {
            $locked = TradingEngineScanRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status !== TradingEngineScanRequest::STATUS_SUBMITTING
                || $locked->attempt_count !== $attempt) {
                return;
            }

            $transition($locked);
        }, 3);
    }

    private function dispatch(TradingEngineScanRequest $request): mixed
    {
        return $this->bus->dispatch(
            (new SubmitTradingEngineTokenScan($request->getKey()))->onConnection('database'),
        );
    }

    private function assertDatabaseQueueIsAtomic(): void
    {
        if (config('queue.connections.database.driver') !== 'database') {
            throw new LogicException('Token scan delivery requires the database queue driver.');
        }

        $queueConnection = config('queue.connections.database.connection');

        if (is_string($queueConnection) && $queueConnection !== ''
            && $queueConnection !== DB::getDefaultConnection()) {
            throw new LogicException('Token scan state and queue jobs must use the same database connection.');
        }
    }

    private function failActivity(TradingEngineScanRequest $request, string $code): void
    {
        $request->activity()->whereIn('status', ['pending', 'running'])->update([
            'status' => 'failed',
            'finished_at' => now(),
            'exit_code' => 1,
            'error_message' => 'The TypeScript scanner rejected the request.',
            'result_summary' => [
                'kind' => 'scanner_execution',
                'owner' => 'trading-engine',
                'scanner' => $request->scanner,
                'network_id' => $request->network_id,
                'error_code' => $this->safeCode($code),
            ],
        ]);
    }

    private function safeCode(string $code): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $code) === 1 ? $code : 'UNEXPECTED_FAILURE';
    }
}
