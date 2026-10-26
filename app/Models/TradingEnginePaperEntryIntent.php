<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TradingEnginePaperEntryIntent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SUBMITTING = 'submitting';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_ACCEPTANCE_UNKNOWN = 'acceptance_unknown';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PROJECTED = 'projected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'trade_opportunity_id',
        'opportunity_link_id',
        'opportunity_evaluation_id',
        'user_id',
        'idempotency_key',
        'payload_sha256',
        'payload',
        'status',
        'attempt_count',
        'recovery_count',
        'engine_operation_id',
        'engine_wallet_id',
        'engine_intent_id',
        'engine_order_id',
        'engine_fill_id',
        'engine_position_id',
        'engine_event_id',
        'last_error_code',
        'queued_at',
        'submitting_at',
        'last_attempt_at',
        'acceptance_unknown_at',
        'definitively_rejected_at',
        'last_recovered_at',
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
            'attempt_count' => 'integer',
            'recovery_count' => 'integer',
            'queued_at' => 'immutable_datetime',
            'submitting_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'acceptance_unknown_at' => 'immutable_datetime',
            'definitively_rejected_at' => 'immutable_datetime',
            'last_recovered_at' => 'immutable_datetime',
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
