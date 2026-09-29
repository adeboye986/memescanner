<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TradingEngineOpportunityEvaluation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'opportunity_link_id',
        'trade_opportunity_id',
        'evaluation_id',
        'engine_opportunity_id',
        'evaluation_event_id',
        'recorded_event_id',
        'policy_key',
        'policy_version',
        'algorithm_key',
        'algorithm_version',
        'policy_definition_sha256',
        'source_request_sha256',
        'evaluation_input_sha256',
        'result_sha256',
        'outcome',
        'reason_codes',
        'advisory_codes',
        'evidence',
        'correlation_id',
        'traceparent',
        'evaluated_at',
        'event_received_at',
        'projected_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Trading engine opportunity evaluations are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Trading engine opportunity evaluations are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'policy_version' => 'integer',
            'algorithm_version' => 'integer',
            'reason_codes' => 'array',
            'advisory_codes' => 'array',
            'evidence' => 'array',
            'evaluated_at' => 'immutable_datetime',
            'event_received_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(TradingEngineOpportunityLink::class, 'opportunity_link_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class, 'trade_opportunity_id');
    }

    public function evaluationEvent(): BelongsTo
    {
        return $this->belongsTo(TradingEngineEvent::class, 'evaluation_event_id', 'event_id');
    }

    public function recordedEvent(): BelongsTo
    {
        return $this->belongsTo(TradingEngineEvent::class, 'recorded_event_id', 'event_id');
    }
}
