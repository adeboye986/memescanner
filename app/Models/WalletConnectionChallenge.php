<?php

namespace App\Models;

use App\Chain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletConnectionChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'chain',
        'address',
        'provider',
        'nonce',
        'message',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'chain' => Chain::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
