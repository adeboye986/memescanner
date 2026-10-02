<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingEnginePaperObservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(TradingEnginePaperPositionLink::class, 'position_link_id');
    }
}
