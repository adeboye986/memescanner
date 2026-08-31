<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperWallet extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starting_balance_sol' => 'float',
        'available_balance_sol' => 'float',
        'invested_balance_sol' => 'float',
        'realized_pnl_sol' => 'float',
    ];
}