<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TradingEnginePaperExitSettlement extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Trading engine PAPER exit settlements are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Trading engine PAPER exit settlements are append-only.'));
    }

    protected function casts(): array
    {
        return ['settled_at' => 'immutable_datetime'];
    }
}
