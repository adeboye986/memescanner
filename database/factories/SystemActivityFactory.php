<?php

namespace Database\Factories;

use App\Models\SystemActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemActivity>
 */
class SystemActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => 'paper-track',
            'command' => 'tokens:paper-track',
            'label' => 'Track Positions Now',
            'status' => 'completed',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'duration_seconds' => 1,
            'exit_code' => 0,
            'output' => 'No open paper positions.',
            'triggered_by' => 'manual',
        ];
    }
}
