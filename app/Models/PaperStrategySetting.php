<?php

namespace App\Models;

use Database\Factories\PaperStrategySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperStrategySetting extends Model
{
    /** @use HasFactory<PaperStrategySettingFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stop_loss_percent' => 'float',
            'protection_level_1_percent' => 'float',
            'protection_level_2_percent' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
