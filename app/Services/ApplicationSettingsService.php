<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\SettingAudit;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ApplicationSettingsService
{
    private const CACHE_KEY = 'application-settings.system.0';

    public function __construct(private SettingDefinitionRegistry $definitions) {}

    public function get(string $key): mixed
    {
        $definition = $this->definitions->get($key);
        $stored = $this->stored()[$key] ?? null;

        if ($stored !== null) {
            $value = $stored['encrypted'] ? Crypt::decryptString($stored['value']) : $stored['value'];

            return $this->cast($value, $definition['type']);
        }

        $fallback = isset($definition['fallback']) ? config($definition['fallback']) : null;

        return $fallback !== null ? $this->cast($fallback, $definition['type']) : $definition['default'];
    }

    public function getSecret(string $key): ?string
    {
        $definition = $this->definitions->get($key);

        if (! ($definition['secret'] ?? false)) {
            throw new \InvalidArgumentException("{$key} is not a secret setting.");
        }

        $value = $this->get($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values, ?User $actor = null): void
    {
        DB::transaction(function () use ($values, $actor): void {
            foreach ($values as $key => $value) {
                $definition = $this->definitions->get($key);
                $secret = (bool) ($definition['secret'] ?? false);

                if ($secret && ($value === null || $value === '')) {
                    continue;
                }

                $existing = ApplicationSetting::query()->where([
                    'scope' => 'system', 'owner_id' => 0,
                    'group' => $definition['group'], 'key' => $key,
                ])->first();
                $oldAudit = $secret ? ($existing ? '[configured]' : '[not configured]') : ($existing?->value ?? (string) $this->get($key));
                $serialized = $this->serialize($value, $definition['type']);
                $storedValue = $secret ? Crypt::encryptString($serialized) : $serialized;

                ApplicationSetting::query()->updateOrCreate(
                    ['scope' => 'system', 'owner_id' => 0, 'group' => $definition['group'], 'key' => $key],
                    ['type' => $definition['type'], 'value' => $storedValue, 'encrypted' => $secret],
                );

                SettingAudit::query()->create([
                    'user_id' => $actor?->id,
                    'setting_key' => $key,
                    'action' => $existing ? 'updated' : 'configured',
                    'old_value' => $oldAudit,
                    'new_value' => $secret ? '[replaced]' : $serialized,
                    'metadata' => ['secret' => $secret],
                ]);
            }
        });

        $this->cache()->forget(self::CACHE_KEY);
    }

    /** @return array<string, array<string, mixed>> */
    public function presentation(): array
    {
        $items = [];

        foreach ($this->definitions->all() as $key => $definition) {
            $value = $this->get($key);
            $items[$key] = [
                ...$definition,
                'key' => $key,
                'value' => ($definition['secret'] ?? false) ? null : $value,
                'configured' => $value !== null && $value !== '',
                'masked' => ($definition['secret'] ?? false) && $value ? $this->mask((string) $value) : null,
            ];
        }

        return $items;
    }

    private function stored(): array
    {
        return $this->cache()->rememberForever(self::CACHE_KEY, fn (): array => ApplicationSetting::query()
            ->where('scope', 'system')->where('owner_id', 0)->get()
            ->mapWithKeys(fn (ApplicationSetting $setting): array => [$setting->key => [
                'value' => $setting->value, 'encrypted' => $setting->encrypted,
            ]])->all());
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('services.trading.paper_tracker_cache_store', 'file'));
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => is_array($value) ? $value : json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR),
            default => $value === null ? null : (string) $value,
        };
    }

    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    private function mask(string $value): string
    {
        return str_repeat('•', 16).mb_substr($value, -4);
    }
}
