<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingEnginePaperWalletProjection extends Model
{
    protected $fillable = [
        'engine_wallet_id',
        'user_id',
        'network_id',
        'currency',
        'opening_balance_native',
        'available_balance_native',
        'invested_balance_native',
        'last_event_id',
        'last_event_occurred_at',
        'last_payload_sha256',
        'projected_at',
    ];

    protected function casts(): array
    {
        return [
            'last_event_occurred_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
