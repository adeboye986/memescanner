<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingEngineEvent extends Model
{
    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PROJECTED = 'projected';

    public const STATUS_RETRYABLE = 'retryable';

    public const STATUS_STORED = 'stored';

    public const STATUS_UNHANDLED = 'unhandled';

    protected $table = 'trading_engine_event_inbox';

    protected $fillable = [
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
        'payload_sha256',
        'raw_body_sha256',
        'event_envelope',
        'payload',
        'handling_status',
        'handling_attempts',
        'handling_error_code',
        'next_handling_at',
        'received_at',
        'handled_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'aggregate_version' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'event_envelope' => 'array',
            'payload' => 'array',
            'handling_attempts' => 'integer',
            'next_handling_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'handled_at' => 'immutable_datetime',
        ];
    }
}
