<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingEnginePaperFinancialObservation extends Model
{
    protected $fillable = [
        'position_state_id',
        'user_id',
        'observation_id',
        'sequence',
        'idempotency_key',
        'payload_sha256',
        'payload',
        'status',
        'engine_operation_id',
        'engine_decision_id',
        'engine_event_id',
        'engine_settlement_id',
        'engine_decision',
        'last_error_code',
        'submitted_at',
        'projected_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'payload' => 'array',
            'submitted_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(TradingEnginePaperPositionState::class, 'position_state_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
