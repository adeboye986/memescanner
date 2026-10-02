<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TradingEnginePaperLifecycleDecision extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Trading engine PAPER lifecycle decisions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Trading engine PAPER lifecycle decisions are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'transitions' => 'array',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }
}
