<?php

namespace App\Models;

use Database\Factories\TradingEngineScanRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TradingEngineScanRequest extends Model
{
    /** @use HasFactory<TradingEngineScanRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SUBMITTING = 'submitting';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_ACCEPTANCE_UNKNOWN = 'acceptance_unknown';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PROJECTED = 'projected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'system_activity_id', 'user_id', 'scanner', 'network_id', 'entry_mode',
        'idempotency_key', 'payload_sha256', 'payload', 'status',
        'attempt_count', 'recovery_count', 'engine_operation_id',
        'engine_scan_execution_id', 'last_error_code', 'queued_at',
        'submitting_at', 'last_attempt_at', 'acceptance_unknown_at',
        'definitively_rejected_at', 'last_recovered_at', 'accepted_at', 'projected_at',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Trading engine scan requests cannot be deleted.'));
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
            'accepted_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(SystemActivity::class, 'system_activity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
