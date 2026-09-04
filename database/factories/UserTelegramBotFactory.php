<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserTelegramBot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserTelegramBot>
 */
class UserTelegramBotFactory extends Factory
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
            'public_id' => fake()->unique()->regexify('[A-Za-z0-9]{32}'),
            'bot_token' => '123456:test-token-value',
            'bot_username' => fake()->unique()->userName().'_bot',
            'webhook_secret' => fake()->regexify('[A-Za-z0-9_-]{48}'),
            'telegram_bot_id' => (string) fake()->unique()->numberBetween(100000, 99999999),
            'display_name' => fake()->name(),
            'enabled' => true,
            'webhook_configured_at' => now(),
            'last_verified_at' => now(),
        ];
    }
}
