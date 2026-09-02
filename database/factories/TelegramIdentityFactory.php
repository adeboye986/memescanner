<?php

namespace Database\Factories;

use App\Models\TelegramIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramIdentity>
 */
class TelegramIdentityFactory extends Factory
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
            'telegram_user_id' => (string) fake()->unique()->numberBetween(100000, 999999999),
            'telegram_chat_id' => (string) fake()->numberBetween(100000, 999999999),
            'telegram_username' => fake()->userName(),
            'display_name' => fake()->name(),
            'status' => 'active',
            'linked_at' => now(),
        ];
    }
}
