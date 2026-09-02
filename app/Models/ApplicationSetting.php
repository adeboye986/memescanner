<?php

namespace App\Models;

use Database\Factories\ApplicationSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    /** @use HasFactory<ApplicationSettingFactory> */
    use HasFactory;

    protected $fillable = ['scope', 'owner_id', 'group', 'key', 'type', 'value', 'encrypted'];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'encrypted' => 'boolean'];
    }
}
