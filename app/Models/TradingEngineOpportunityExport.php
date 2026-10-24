<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingEngineOpportunityExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SUBMITTING = 'submitting';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_ACCEPTANCE_UNKNOWN = 'acceptance_unknown';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_PERMANENTLY_FAILED = 'permanently_failed';

    protected $fillable = [
        'trade_opportunity_id',
        'idempotency_key',
        'status',
        'attempt_count',
        'last_error_code',
        'engine_operation_id',
        'engine_opportunity_id',
        'engine_event_id',
        'duplicate',
        'queued_at',
        'submitting_at',
        'last_attempt_at',
        'acknowledged_at',
        'skipped_at',
        'permanently_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'duplicate' => 'boolean',
            'queued_at' => 'datetime',
            'submitting_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'skipped_at' => 'datetime',
            'permanently_failed_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class, 'trade_opportunity_id');
    }
}
