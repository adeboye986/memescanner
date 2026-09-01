<?php

namespace App\Models;

use Database\Factories\SystemActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemActivity extends Model
{
    /** @use HasFactory<SystemActivityFactory> */
    use HasFactory;

    protected $fillable = [
        'action',
        'command',
        'label',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'exit_code',
        'output',
        'error_message',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
            'exit_code' => 'integer',
        ];
    }
}
