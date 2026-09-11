<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EthereumSwapAttempt extends Model
{
    protected $fillable = [
        'user_id', 'connected_wallet_id', 'buy_token', 'sell_amount_wei',
        'slippage_bps', 'quote_id', 'transaction_payload', 'status',
        'transaction_hash', 'expires_at', 'submitted_at',
    ];

    protected $hidden = ['transaction_payload'];

    protected function casts(): array
    {
        return ['transaction_payload' => 'encrypted:array', 'expires_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function connectedWallet(): BelongsTo
    {
        return $this->belongsTo(ConnectedWallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
