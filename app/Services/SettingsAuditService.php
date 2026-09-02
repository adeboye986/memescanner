<?php

namespace App\Services;

use App\Models\SettingAudit;
use App\Models\User;

class SettingsAuditService
{
    public function record(string $key, mixed $oldValue, mixed $newValue, ?User $actor = null): void
    {
        SettingAudit::query()->create([
            'user_id' => $actor?->id,
            'setting_key' => $key,
            'action' => 'updated',
            'old_value' => (string) $oldValue,
            'new_value' => (string) $newValue,
            'metadata' => ['secret' => false],
        ]);
    }
}
