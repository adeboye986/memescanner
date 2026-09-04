<?php

namespace Database\Factories;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\User;
use App\Models\UserTradingPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserTradingPreference>
 */
class UserTradingPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Signal,
            'trading_enabled' => true,
        ];
    }
}
