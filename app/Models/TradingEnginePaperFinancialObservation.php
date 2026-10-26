<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingEnginePaperFinancialObservation extends Model
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
        'position_state_id',
        'user_id',
        'observation_id',
        'sequence',
        'idempotency_key',
        'payload_sha256',
        'payload',
        'status',
        'attempt_count',
        'recovery_count',
        'engine_operation_id',
        'engine_decision_id',
        'engine_event_id',
        'engine_settlement_id',
        'engine_decision',
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

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
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

    public function state(): BelongsTo
    {
        return $this->belongsTo(TradingEnginePaperPositionState::class, 'position_state_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
