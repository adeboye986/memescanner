<?php

namespace App\Models;

use App\Chain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectedWallet extends Model
{
    protected $fillable = [
        'user_id',
        'chain',
        'address',
        'address_hash',
        'provider',
        'verified_at',
        'last_connected_at',
        'disconnected_at',
    ];

    protected function casts(): array
    {
        return [
            'chain' => Chain::class,
            'verified_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function solanaSwapAttempts(): HasMany
    {
        return $this->hasMany(SolanaSwapAttempt::class);
    }

    public function ethereumSwapAttempts(): HasMany
    {
        return $this->hasMany(EthereumSwapAttempt::class);
    }

    public static function addressHash(Chain|string $chain, string $address): string
    {
        $resolvedChain = $chain instanceof Chain
            ? $chain
            : Chain::fromInput($chain);

        return hash(
            'sha256',
            $resolvedChain->value.':'.$address
        );
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null
            && $this->disconnected_at === null;
    }
}
