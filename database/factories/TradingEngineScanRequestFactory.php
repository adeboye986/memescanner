<?php

namespace Database\Factories;

use App\Models\SystemActivity;
use App\Models\TradingEngineScanRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TradingEngineScanRequest> */
class TradingEngineScanRequestFactory extends Factory
{
    public function definition(): array
    {
        $payload = [
            'schema_version' => 1,
            'source' => ['system' => 'meme-scanner-laravel', 'activity_id' => '1'],
            'subject' => ['control_plane_user_id' => '1'],
            'scanner' => 'new-token',
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'policy' => ['max_chase_percent' => '35', 'momentum_birdeye_budget' => 1],
        ];

        return [
            'system_activity_id' => SystemActivity::factory(),
            'user_id' => User::factory(),
            'scanner' => 'new-token',
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'entry_mode' => 'signal',
            'idempotency_key' => 'token-scan:laravel:'.fake()->unique()->numberBetween(1, 999999).':v1',
            'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'payload' => $payload,
            'status' => TradingEngineScanRequest::STATUS_PENDING,
        ];
    }
}
