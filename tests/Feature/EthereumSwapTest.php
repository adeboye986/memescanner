<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\User;
use App\Services\CryptoPriceService;
use App\Services\EthereumService;
use App\Services\ZeroXSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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
        $this->assertSame('prepared', $attempt->status);
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
            'status' => 'prepared',
            'expires_at' => now()->addMinute(),
        ]);
        $payload = ['attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('ab', 32)];

        $this->actingAs($attacker)->postJson(route('wallets.ethereum.submitted'), $payload)->assertNotFound();
        $this->actingAs($owner)->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertOk()->assertJsonPath('swap.status', 'submitted');
        $this->actingAs($owner)->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertUnprocessable();
    }

    public function test_user_can_cancel_own_prepared_attempt_but_not_another_users_attempt(): void
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
            'status' => 'prepared',
            'expires_at' => now()->addMinute(),
        ]);

        $payload = ['attempt_id' => $attempt->id];

        $this->actingAs($attacker)
            ->postJson(route('wallets.ethereum.cancelled'), $payload)
            ->assertNotFound();

        $this->actingAs($owner)
            ->postJson(route('wallets.ethereum.cancelled'), $payload)
            ->assertOk()
            ->assertJsonPath('swap.status', 'cancelled');

        $this->assertDatabaseHas('ethereum_swap_attempts', [
            'id' => $attempt->id,
            'status' => 'cancelled',
            'transaction_hash' => null,
            'submitted_at' => null,
        ]);

        $this->actingAs($owner)
            ->postJson(route('wallets.ethereum.cancelled'), $payload)
            ->assertUnprocessable();
    }

    public function test_expired_prepared_attempt_is_persisted_as_expired_when_submission_is_reported(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $attempt = EthereumSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $this->wallet($user)->id,
            'buy_token' => self::TOKEN,
            'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => $this->quote()['transaction'],
            'status' => 'prepared',
            'expires_at' => now()->subSecond(),
        ]);

        $payload = [
            'attempt_id' => $attempt->id,
            'transaction_hash' => '0x'.str_repeat('cd', 32),
        ];

        $this->actingAs($user)
            ->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This Ethereum swap order has expired.');

        $attempt->refresh();

        $this->assertSame('expired', $attempt->status);
        $this->assertNull($attempt->transaction_hash);
        $this->assertNull($attempt->submitted_at);
    }

    public function test_expired_prepared_attempt_is_not_recorded_as_cancelled(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $attempt = EthereumSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $this->wallet($user)->id,
            'buy_token' => self::TOKEN,
            'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => $this->quote()['transaction'],
            'status' => 'prepared',
            'expires_at' => now()->subSecond(),
        ]);

        $this->actingAs($user)
            ->postJson(route('wallets.ethereum.cancelled'), ['attempt_id' => $attempt->id])
            ->assertOk()
            ->assertJsonPath('swap.status', 'expired');

        $this->assertDatabaseHas('ethereum_swap_attempts', [
            'id' => $attempt->id,
            'status' => 'expired',
        ]);
    }

    public function test_price_returns_formatted_network_fee_with_usd_estimate(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->wallet($user);

        $this->mock(EthereumService::class, fn ($mock) => $mock
            ->shouldReceive('getBalanceWei')
            ->once()
            ->with(self::WALLET)
            ->andReturn('1000000000000000000'));

        $this->mock(ZeroXSwapService::class, fn ($mock) => $mock
            ->shouldReceive('price')
            ->once()
            ->with(self::WALLET, self::TOKEN, '1000000000000000', 100)
            ->andReturn([
                'sell_amount' => '1000000000000000',
                'buy_amount' => '5000000',
                'minimum_buy_amount' => '4950000',
                'network_fee_wei' => '21000000000000',
                'sources' => ['Uniswap_V3'],
                'output' => [
                    'address' => self::TOKEN,
                    'amount' => '5000000',
                    'amount_formatted' => '5',
                    'minimum_amount' => '4950000',
                    'minimum_amount_formatted' => '4.95',
                    'symbol' => 'USDC',
                    'decimals' => 6,
                ],
            ]));

        $this->mock(CryptoPriceService::class, function ($mock): void {
            $mock->shouldReceive('ethUsdPrice')
                ->once()
                ->andReturn('2500');

            $mock->shouldReceive('usdForWei')
                ->once()
                ->with('21000000000000', '2500')
                ->andReturn('0.05');
        });

        $this->actingAs($user)
            ->postJson(route('wallets.ethereum.price'), $this->payload())
            ->assertOk()
            ->assertJsonPath('price.output.amount_formatted', '5')
            ->assertJsonPath('price.output.minimum_amount_formatted', '4.95')
            ->assertJsonPath('price.output.symbol', 'USDC')
            ->assertJsonPath('price.network_fee.wei', '21000000000000')
            ->assertJsonPath('price.network_fee.eth', '0.000021')
            ->assertJsonPath('price.network_fee.usd', '0.05');
    }

    public function test_price_remains_available_when_eth_usd_price_is_unavailable(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->wallet($user);

        $this->mock(EthereumService::class, fn ($mock) => $mock
            ->shouldReceive('getBalanceWei')
            ->once()
            ->andReturn('1000000000000000000'));

        $this->mock(ZeroXSwapService::class, fn ($mock) => $mock
            ->shouldReceive('price')
            ->once()
            ->andReturn([
                'sell_amount' => '1000000000000000',
                'buy_amount' => '5000000',
                'minimum_buy_amount' => '4950000',
                'network_fee_wei' => '21000000000000',
                'sources' => ['Uniswap_V3'],
                'output' => [
                    'address' => self::TOKEN,
                    'amount' => '5000000',
                    'amount_formatted' => '5',
                    'minimum_amount' => '4950000',
                    'minimum_amount_formatted' => '4.95',
                    'symbol' => 'USDC',
                    'decimals' => 6,
                ],
            ]));

        $this->mock(CryptoPriceService::class, function ($mock): void {
            $mock->shouldReceive('ethUsdPrice')
                ->once()
                ->andThrow(new RuntimeException('ETH price unavailable'));

            $mock->shouldNotReceive('usdForWei');
        });

        $this->actingAs($user)
            ->postJson(route('wallets.ethereum.price'), $this->payload())
            ->assertOk()
            ->assertJsonPath('price.output.amount_formatted', '5')
            ->assertJsonPath('price.network_fee.eth', '0.000021')
            ->assertJsonPath('price.network_fee.usd', null);
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
