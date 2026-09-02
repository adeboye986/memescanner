<?php

namespace App\Models;

use App\Chain;
use Illuminate\Database\Eloquent\Model;

class PaperWallet extends Model
{
    protected $guarded = [];

    protected $casts = [
        'chain' => Chain::class,
        'starting_balance_sol' => 'float',
        'available_balance_sol' => 'float',
        'invested_balance_sol' => 'float',
        'realized_pnl_sol' => 'float',
    ];

    public function currencyCode(): string
    {
        return $this->currency;
    }
}
