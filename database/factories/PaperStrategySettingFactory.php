<?php

namespace Database\Factories;

use App\Models\PaperStrategySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaperStrategySetting>
 */
class PaperStrategySettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'default',
            'stop_loss_percent' => 10,
            'protection_level_1_percent' => 100,
            'protection_level_2_percent' => 200,
        ];
    }
}
