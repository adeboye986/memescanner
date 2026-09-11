<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolanaSwapAttempt extends Model
{
    protected $fillable = [
        'user_id', 'connected_wallet_id', 'request_id', 'output_mint',
        'input_amount_lamports', 'slippage_bps', 'message_hash',
        'prepared_transaction', 'status', 'transaction_signature',
        'provider_error_code', 'provider_error_message', 'expires_at',
        'submitted_at',
    ];

    protected $hidden = ['prepared_transaction'];

    protected function casts(): array
    {
        return [
            'prepared_transaction' => 'encrypted',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connectedWallet(): BelongsTo
    {
        return $this->belongsTo(ConnectedWallet::class);
    }
}
