<?php

namespace Database\Factories;

use App\Models\ApplicationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationSetting>
 */
class ApplicationSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => 'system',
            'owner_id' => 0,
            'group' => 'general',
            'key' => 'general.application_name',
            'type' => 'string',
            'value' => fake()->company(),
            'encrypted' => false,
        ];
    }
}
