<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettingAudit extends Model
{
    protected $fillable = ['user_id', 'setting_key', 'action', 'old_value', 'new_value', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
