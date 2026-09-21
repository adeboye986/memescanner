<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\SolanaSwapAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpirePreparedSolanaSwapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_expires_only_stale_prepared_solana_swap_attempts(): void
    {
        $user = User::factory()->create();

        $address = '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP';

        $wallet = ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Solana,
            'address' => $address,
            'address_hash' => ConnectedWallet::addressHash(
                Chain::Solana,
                $address
            ),
            'provider' => 'test',
            'verified_at' => now(),
        ]);

        $makeAttempt = function (
            string $status,
            $expiresAt
        ) use ($user, $wallet): SolanaSwapAttempt {
            return SolanaSwapAttempt::query()->create([
                'user_id' => $user->id,
                'connected_wallet_id' => $wallet->id,
                'request_id' => 'test-request-'.uniqid(),
                'output_mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                'input_amount_lamports' => 1_000_000,
                'slippage_bps' => 100,
                'message_hash' => str_repeat('a', 64),
                'recent_blockhash' => '11111111111111111111111111111111',
                'prepared_transaction' => base64_encode('prepared'),
                'status' => $status,
                'expires_at' => $expiresAt,
            ]);
        };

        $expiredPrepared = $makeAttempt(
            'prepared',
            now()->subMinute()
        );

        $freshPrepared = $makeAttempt(
            'prepared',
            now()->addMinute()
        );

        $submitting = $makeAttempt(
            'submitting',
            now()->subMinute()
        );

        $submitted = $makeAttempt(
            'submitted',
            now()->subMinute()
        );

        $this->artisan('solana:expire-prepared-swaps')
            ->expectsOutput(
                'Expired 1 prepared Solana swap attempt(s).'
            )
            ->assertSuccessful();

        $this->assertSame(
            'expired',
            $expiredPrepared->fresh()->status
        );

        $this->assertSame(
            'prepared',
            $freshPrepared->fresh()->status
        );

        $this->assertSame(
            'submitting',
            $submitting->fresh()->status
        );

        $this->assertSame(
            'submitted',
            $submitted->fresh()->status
        );
    }
}
