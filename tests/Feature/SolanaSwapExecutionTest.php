<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\SolanaSwapAttempt;
use App\Models\User;
use App\Services\JupiterSwapExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolanaSwapExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_swap_is_claimed_once_before_external_submission(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = $this->wallet($user);
        $attempt = $this->attempt($user, $wallet);

        $this->mock(JupiterSwapExecutionService::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn([
                'status' => 'submitted',
                'signature' => 'chain-signature',
                'error_code' => null,
                'error_message' => null,
            ]);
        });

        $payload = [
            'attempt_id' => $attempt->id,
            'signed_transaction' => base64_encode('signed'),
        ];

        $this->actingAs($user)->postJson(route('wallets.solana.execute'), $payload)
            ->assertOk()
            ->assertJsonPath('swap.status', 'submitted')
            ->assertJsonPath('swap.signature', 'chain-signature');

        $this->actingAs($user)->postJson(route('wallets.solana.execute'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This swap order has expired or was already submitted.');

        $this->assertDatabaseHas('solana_swap_attempts', [
            'id' => $attempt->id,
            'status' => 'submitted',
            'transaction_signature' => 'chain-signature',
        ]);
    }

    public function test_user_cannot_submit_another_users_swap_attempt(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $attacker = User::factory()->create(['email_verified_at' => now()]);
        $attempt = $this->attempt($owner, $this->wallet($owner));

        $this->mock(JupiterSwapExecutionService::class, fn ($mock) => $mock->shouldNotReceive('execute'));

        $this->actingAs($attacker)->postJson(route('wallets.solana.execute'), [
            'attempt_id' => $attempt->id,
            'signed_transaction' => base64_encode('signed'),
        ])->assertNotFound();

        $this->assertSame('prepared', $attempt->fresh()->status);
    }

    private function wallet(User $user): ConnectedWallet
    {
        return ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Solana,
            'address' => '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP',
            'address_hash' => ConnectedWallet::addressHash(Chain::Solana, '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP'),
            'provider' => 'phantom',
            'verified_at' => now(),
            'last_connected_at' => now(),
        ]);
    }

    private function attempt(User $user, ConnectedWallet $wallet): SolanaSwapAttempt
    {
        return SolanaSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'request_id' => 'request-'.$user->id,
            'output_mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
            'input_amount_lamports' => 1_000_000,
            'slippage_bps' => 100,
            'message_hash' => str_repeat('a', 64),
            'prepared_transaction' => base64_encode('prepared'),
            'expires_at' => now()->addMinute(),
        ]);
    }
}
