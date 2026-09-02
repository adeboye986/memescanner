<?php

namespace App\Models;

use Database\Factories\TradeOpportunityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeOpportunityEvent extends Model
{
    /** @use HasFactory<TradeOpportunityEventFactory> */
    use HasFactory;

    protected $fillable = [
        'trade_opportunity_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class, 'trade_opportunity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
