<?php

namespace App\Services;

use App\Models\PaperPosition;
use App\Models\PaperStrategySetting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaperStrategyService
{
    /** @var array{stop_loss_percent: float, protection_level_1_percent: float, protection_level_2_percent: float} */
    public const DEFAULTS = [
        'stop_loss_percent' => 10.0,
        'protection_level_1_percent' => 100.0,
        'protection_level_2_percent' => 200.0,
    ];

    public function global(): PaperStrategySetting
    {
        return PaperStrategySetting::query()->firstOrCreate(
            ['name' => 'default'],
            self::DEFAULTS,
        );
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array<string, float|string>
     */
    public function forNewPosition(?array $override = null): array
    {
        $global = $this->global();
        $values = [
            'stop_loss_percent' => (float) $global->stop_loss_percent,
            'protection_level_1_percent' => (float) $global->protection_level_1_percent,
            'protection_level_2_percent' => (float) $global->protection_level_2_percent,
        ];

        if ($override !== null) {
            $values = array_replace($values, array_intersect_key($override, self::DEFAULTS));
        }

        $this->validate($values);

        return $this->withMultiples($values, $override === null ? 'global' : 'position_override');
    }

    /** @return array<string, float|string> */
    public function forPosition(PaperPosition $position): array
    {
        if (is_array($position->strategy_snapshot) && $position->strategy_snapshot !== []) {
            $values = array_replace(self::DEFAULTS, array_intersect_key($position->strategy_snapshot, self::DEFAULTS));

            try {
                $this->validate($values);

                return $this->withMultiples($values, (string) ($position->strategy_snapshot['source'] ?? 'position_snapshot'));
            } catch (InvalidArgumentException) {
                report(new InvalidArgumentException("Invalid strategy snapshot on paper position {$position->id}; application defaults were used."));
            }
        }

        return $this->withMultiples(self::DEFAULTS, 'legacy_default');
    }

    /** @param array{stop_loss_percent: float|int|string, protection_level_1_percent: float|int|string, protection_level_2_percent: float|int|string} $values */
    public function updateGlobal(array $values): PaperStrategySetting
    {
        $this->validate($values);

        return DB::transaction(function () use ($values): PaperStrategySetting {
            $setting = PaperStrategySetting::query()
                ->where('name', 'default')
                ->lockForUpdate()
                ->first() ?? new PaperStrategySetting(['name' => 'default']);
            $setting->fill([
                'stop_loss_percent' => (float) $values['stop_loss_percent'],
                'protection_level_1_percent' => (float) $values['protection_level_1_percent'],
                'protection_level_2_percent' => (float) $values['protection_level_2_percent'],
            ])->save();

            return $setting->fresh();
        });
    }

    /** @param array<string, mixed> $values */
    private function validate(array $values): void
    {
        $stop = (float) ($values['stop_loss_percent'] ?? 0);
        $levelOne = (float) ($values['protection_level_1_percent'] ?? 0);
        $levelTwo = (float) ($values['protection_level_2_percent'] ?? 0);

        if ($stop <= 0 || $stop >= 100 || $levelOne <= 0 || $levelTwo <= $levelOne) {
            throw new InvalidArgumentException('Invalid paper strategy values.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, float|string>
     */
    private function withMultiples(array $values, string $source): array
    {
        $stop = (float) $values['stop_loss_percent'];
        $levelOne = (float) $values['protection_level_1_percent'];
        $levelTwo = (float) $values['protection_level_2_percent'];

        return [
            'stop_loss_percent' => $stop,
            'protection_level_1_percent' => $levelOne,
            'protection_level_2_percent' => $levelTwo,
            'stop_loss_multiple' => 1 - ($stop / 100),
            'protection_level_1_multiple' => 1 + ($levelOne / 100),
            'protection_level_2_multiple' => 1 + ($levelTwo / 100),
            'source' => $source,
        ];
    }
}
