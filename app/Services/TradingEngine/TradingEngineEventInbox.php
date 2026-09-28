<?php

namespace App\Services\TradingEngine;

use App\Models\TradingEngineEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

class TradingEngineEventInbox
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return array{duplicate: bool, conflict: bool, handling_status: string}
     */
    public function store(
        array $envelope,
        string $rawBody,
        string $rawBodySha256,
        string $handlingStatus,
    ): array {
        return DB::transaction(function () use ($envelope, $rawBody, $rawBodySha256, $handlingStatus): array {
            $now = now();
            $inserted = DB::table('trading_engine_event_inbox')->insertOrIgnore([
                'event_id' => $envelope['event_id'],
                'event_type' => $envelope['event_type'],
                'schema_version' => $envelope['schema_version'],
                'occurred_at' => $envelope['occurred_at'],
                'producer' => $envelope['producer'],
                'aggregate_type' => $envelope['aggregate_type'],
                'aggregate_id' => $envelope['aggregate_id'],
                'aggregate_version' => $envelope['aggregate_version'],
                'correlation_id' => $envelope['correlation_id'],
                'causation_id' => $envelope['causation_id'],
                'idempotency_key' => $envelope['idempotency_key'],
                'traceparent' => $envelope['traceparent'],
                'payload_sha256' => $envelope['payload_sha256'],
                'raw_body_sha256' => $rawBodySha256,
                'event_envelope' => $rawBody,
                'payload' => json_encode((object) $envelope['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'handling_status' => $handlingStatus,
                'received_at' => $now,
                'handled_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return [
                    'duplicate' => false,
                    'conflict' => false,
                    'handling_status' => $handlingStatus,
                ];
            }

            $existing = TradingEngineEvent::query()->where('event_id', $envelope['event_id'])->first();

            if (! $existing) {
                throw new LogicException('The event inbox rejected a row without an event ID collision.');
            }

            if (! hash_equals($existing->raw_body_sha256, $rawBodySha256)) {
                return [
                    'duplicate' => false,
                    'conflict' => true,
                    'handling_status' => $existing->handling_status,
                ];
            }

            return [
                'duplicate' => true,
                'conflict' => false,
                'handling_status' => $existing->handling_status,
            ];
        }, 1);
    }
}
