<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\User;
use App\Services\RemoteEthereumSignatureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EthereumWalletConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_prove_ethereum_wallet_ownership(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $address = '0x1111111111111111111111111111111111111111';
        $signature = '0x'.str_repeat('ab', 65);
        $challengeResponse = $this->actingAs($user)->postJson(route('wallets.ethereum.challenge'), [
            'address' => '0x'.strtoupper(substr($address, 2)),
            'provider' => 'phantom',
        ])->assertOk();
        $challenge = $user->walletConnectionChallenges()->findOrFail($challengeResponse->json('challenge_id'));

        $this->assertStringContainsString('Chain: ethereum', $challenge->message);
        $this->assertStringContainsString('It does not authorize a transaction.', $challenge->message);
        $this->mock(RemoteEthereumSignatureValidator::class, fn ($mock) => $mock->shouldReceive('verify')
            ->once()->with($challenge->message, $signature, $address)->andReturn($address));

        $this->actingAs($user)->postJson(route('wallets.ethereum.verify'), [
            'challenge_id' => $challenge->id,
            'signature' => $signature,
        ])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('wallet.address', $address);

        $this->assertDatabaseHas('connected_wallets', [
            'user_id' => $user->id, 'chain' => Chain::Ethereum->value, 'address' => $address, 'provider' => 'phantom',
        ]);
        $this->assertNotNull($challenge->fresh()->used_at);
    }

    public function test_forged_ethereum_signature_is_rejected_without_connecting_wallet(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $challenge = $user->walletConnectionChallenges()->create([
            'chain' => Chain::Ethereum,
            'address' => '0x1111111111111111111111111111111111111111',
            'provider' => 'metamask',
            'nonce' => str_repeat('a', 64),
            'message' => 'exact message',
            'expires_at' => now()->addMinute(),
        ]);
        $this->mock(RemoteEthereumSignatureValidator::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andThrow(new \RuntimeException));

        $this->actingAs($user)->postJson(route('wallets.ethereum.verify'), [
            'challenge_id' => $challenge->id,
            'signature' => '0x'.str_repeat('ab', 65),
        ])->assertUnprocessable()->assertJsonValidationErrors('signature');
        $this->assertDatabaseCount('connected_wallets', 0);
        $this->assertNull($challenge->fresh()->used_at);
    }
}
