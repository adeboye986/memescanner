<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineScanRequest;
use Illuminate\Support\Facades\DB;

class TradingEngineScanProjector
{
    public function __construct(
        private TradingEngineCanonicalJson $canonicalJson,
        private TradingEngineScanEventPayloadValidator $payloads,
    ) {}

    public function project(string $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = TradingEngineEvent::query()->where('event_id', $eventId)->lockForUpdate()->first();

            if (! $event || ! in_array($event->event_type, [
                'token.scan.completed.v1',
                'token.scan.failed.v1',
            ], true)) {
                return false;
            }

            if ($event->handling_status === TradingEngineEvent::STATUS_PROJECTED) {
                return true;
            }

            $payload = $event->payload;

            if (! is_array($payload) || ! $this->payloads->isValid($payload)) {
                $this->failEvent($event, 'SCAN_EVENT_PAYLOAD_INVALID');

                return true;
            }

            $request = TradingEngineScanRequest::query()
                ->where('system_activity_id', (int) data_get($payload, 'source.activity_id'))
                ->lockForUpdate()
                ->first();

            if (! $request instanceof TradingEngineScanRequest
                || $request->user_id !== (int) data_get($payload, 'subject.control_plane_user_id')
                || $request->scanner !== $payload['scanner']
                || $request->network_id !== data_get($payload, 'network.id')
                || ! is_array($request->payload)
                || $this->canonicalJson->hash($request->payload) !== $request->payload_sha256
                || data_get($request->payload, 'source.activity_id') !== (string) $request->system_activity_id
                || data_get($request->payload, 'subject.control_plane_user_id') !== (string) $request->user_id
                || data_get($request->payload, 'scanner') !== $request->scanner
                || data_get($request->payload, 'network.id') !== $request->network_id
                || $event->aggregate_id !== $payload['scan_execution_id']
                || $event->causation_id !== $payload['scan_execution_id']
                || $event->idempotency_key !== 'token-scan:'.$payload['scan_execution_id'].':terminal:v1') {
                $this->failEvent($event, 'SCAN_EVENT_IDENTITY_MISMATCH');

                return true;
            }

            if ($request->definitively_rejected_at !== null) {
                $this->failEvent($event, 'SCAN_ACCEPTANCE_CONFLICT');

                return true;
            }

            if ($request->engine_scan_execution_id !== null
                && $request->engine_scan_execution_id !== $payload['scan_execution_id']) {
                $this->failEvent($event, 'SCAN_EXECUTION_CONFLICT');

                return true;
            }

            $activity = $request->activity()->lockForUpdate()->first();
            $expectedAction = $request->scanner === 'new-token' ? 'token-scan' : 'momentum-scan';
            $expectedChain = match ($request->network_id) {
                'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' => Chain::Solana,
                'eip155:1' => Chain::Ethereum,
                default => null,
            };

            if (! $activity
                || $activity->user_id !== $request->user_id
                || $activity->action !== $expectedAction
                || $activity->chain !== $expectedChain
                || ! in_array($activity->status, ['pending', 'running', 'completed', 'failed'], true)) {
                $this->failEvent($event, 'SCAN_ACTIVITY_INVALID');

                return true;
            }

            $failed = $payload['outcome'] === 'failed';
            $completedAt = $event->occurred_at;
            $startedAt = $activity->started_at ?? $activity->created_at ?? $completedAt;
            $providerFailures = collect($payload['provider_failures'])
                ->countBy()
                ->map(fn (int $count, string $code): array => [
                    'code' => $code,
                    'count' => $count,
                ])
                ->values()
                ->all();
            $summary = [
                'version' => 1,
                'schema_version' => 1,
                'kind' => 'scanner_execution',
                'owner' => 'trading-engine',
                'scanner' => $request->scanner,
                'chain' => $expectedChain->value,
                'network_id' => $request->network_id,
                'scan_execution_id' => $payload['scan_execution_id'],
                'outcome' => $payload['outcome'],
                'zero_result' => $payload['counts']['qualified'] === 0
                    && $payload['counts']['opportunities_created'] === 0
                    && $payload['counts']['opportunities_existing'] === 0,
                'counts' => $payload['counts'],
                'provider_failures' => $providerFailures,
                'error_code' => $payload['error_code'],
            ];

            $request->forceFill([
                'engine_scan_execution_id' => $payload['scan_execution_id'],
                'status' => TradingEngineScanRequest::STATUS_PROJECTED,
                'last_error_code' => $payload['error_code'],
                'acceptance_unknown_at' => null,
                'accepted_at' => $request->accepted_at ?? $event->occurred_at,
                'projected_at' => now(),
            ])->save();
            $activity->forceFill([
                'status' => $failed ? 'failed' : 'completed',
                'started_at' => $activity->started_at ?? $startedAt,
                'finished_at' => $completedAt,
                'duration_seconds' => max(0, $startedAt->diffInSeconds($completedAt)),
                'exit_code' => $failed ? 1 : 0,
                'output' => null,
                'error_message' => $failed ? 'The TypeScript scanner did not complete successfully.' : null,
                'result_summary' => $summary,
                'last_progress_at' => $completedAt,
            ])->save();
            $event->forceFill([
                'handling_status' => TradingEngineEvent::STATUS_PROJECTED,
                'handling_attempts' => $event->handling_attempts + 1,
                'handling_error_code' => null,
                'next_handling_at' => null,
                'handled_at' => now(),
            ])->save();

            return true;
        }, 3);
    }

    private function failEvent(TradingEngineEvent $event, string $code): void
    {
        $event->forceFill([
            'handling_status' => TradingEngineEvent::STATUS_FAILED,
            'handling_attempts' => $event->handling_attempts + 1,
            'handling_error_code' => $code,
            'next_handling_at' => null,
            'handled_at' => now(),
        ])->save();
    }
}
