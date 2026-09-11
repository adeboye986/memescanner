<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\User;
use App\Services\EthereumService;
use App\Services\ZeroXSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EthereumSwapTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    private const TOKEN = '0x2222222222222222222222222222222222222222';

    public function test_firm_order_is_bound_to_verified_wallet_and_stored_encrypted(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = $this->wallet($user);
        $this->mock(EthereumService::class, fn ($mock) => $mock->shouldReceive('getBalanceWei')->once()->with(self::WALLET)->andReturn('1000000000000000000'));
        $this->mock(ZeroXSwapService::class, fn ($mock) => $mock->shouldReceive('quote')->once()
            ->with(self::WALLET, self::TOKEN, '1000000000000000', 100)->andReturn($this->quote()));

        $response = $this->actingAs($user)->postJson(route('wallets.ethereum.order'), $this->payload())
            ->assertOk()->assertJsonPath('order.transaction.from', self::WALLET)
            ->assertJsonPath('order.transaction.value', '1000000000000000');

        $attempt = EthereumSwapAttempt::findOrFail($response->json('order.attempt_id'));
        $this->assertSame($wallet->id, $attempt->connected_wallet_id);
        $this->assertSame('authorized', $attempt->status);
        $this->assertSame($this->quote()['transaction'], $attempt->transaction_payload);
        $this->assertStringNotContainsString('0x1234', $attempt->getRawOriginal('transaction_payload'));
    }

    public function test_user_cannot_report_another_users_transaction_and_attempt_is_single_use(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $attacker = User::factory()->create(['email_verified_at' => now()]);
        $attempt = EthereumSwapAttempt::query()->create([
            'user_id' => $owner->id,
            'connected_wallet_id' => $this->wallet($owner)->id,
            'buy_token' => self::TOKEN,
            'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => $this->quote()['transaction'],
            'status' => 'authorized',
            'expires_at' => now()->addMinute(),
        ]);
        $payload = ['attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('ab', 32)];

        $this->actingAs($attacker)->postJson(route('wallets.ethereum.submitted'), $payload)->assertNotFound();
        $this->actingAs($owner)->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertOk()->assertJsonPath('swap.status', 'reported');
        $this->actingAs($owner)->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertUnprocessable();
    }

    private function wallet(User $user): ConnectedWallet
    {
        return ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Ethereum,
            'address' => self::WALLET,
            'address_hash' => ConnectedWallet::addressHash(Chain::Ethereum, self::WALLET),
            'provider' => 'phantom',
            'verified_at' => now(),
            'last_connected_at' => now(),
        ]);
    }

    private function payload(): array
    {
        return ['buy_token' => self::TOKEN, 'sell_amount_wei' => '1000000000000000', 'slippage_bps' => 100];
    }

    private function quote(): array
    {
        return ['quote_id' => 'quote-1', 'network_fee_wei' => '21000000000000', 'transaction' => [
            'from' => self::WALLET,
            'to' => self::TOKEN,
            'data' => '0x1234',
            'value' => '1000000000000000',
            'gas' => '21000',
            'gasPrice' => '1000000000',
            'chainId' => '1',
        ]];
    }
}
