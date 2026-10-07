<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TradingEnginePaperEntryIntent extends Model
{
    protected $fillable = [
        'trade_opportunity_id',
        'opportunity_link_id',
        'opportunity_evaluation_id',
        'user_id',
        'idempotency_key',
        'payload_sha256',
        'payload',
        'status',
        'engine_operation_id',
        'engine_wallet_id',
        'engine_intent_id',
        'engine_order_id',
        'engine_fill_id',
        'engine_position_id',
        'engine_event_id',
        'last_error_code',
        'submitted_at',
        'projected_at',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Trading engine PAPER entry intents cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'submitted_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class, 'trade_opportunity_id');
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(TradingEngineOpportunityEvaluation::class, 'opportunity_evaluation_id');
    }
}
