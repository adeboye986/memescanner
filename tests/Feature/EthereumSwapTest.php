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
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertNull($attempt->trade_opportunity_id);
        $this->assertNull($attempt->wallet_address);
        $this->assertSame('prepared', $attempt->status);
        $this->assertSame($this->quote()['transaction'], $attempt->transaction_payload);
        $this->assertStringNotContainsString('0x1234', $attempt->getRawOriginal('transaction_payload'));
    }

    public function test_user_cannot_report_another_users_transaction_and_same_hash_is_idempotent(): void
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
        $this->fakeTransaction();

        $this->actingAs($attacker)->postJson(route('wallets.ethereum.submitted'), $payload)->assertNotFound();
        $this->actingAs($owner)->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertOk()->assertJsonPath('swap.status', 'submitted');
        $this->actingAs($owner)->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertOk()->assertJsonPath('swap.status', 'submitted');
        Http::assertSentCount(1);
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

    public function test_expired_prepared_attempt_can_be_recorded_as_submitted_when_broadcast_matches(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = $this->wallet($user);

        $attempt = EthereumSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'buy_token' => self::TOKEN,
            'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => $this->quote()['transaction'],
            'status' => 'prepared',
            'expires_at' => now()->subSecond(),
        ]);

        $hash = '0x'.str_repeat('cd', 32);

        $prepared = $attempt->transaction_payload;

        $this->partialMock(EthereumService::class, function ($mock) use ($hash, $wallet, $prepared): void {
            $mock->shouldReceive('getTransactionByHash')
                ->once()
                ->with($hash)
                ->andReturn([
                    'chain_id' => '1',
                    'hash' => $hash,
                    'from' => strtolower($wallet->address),
                    'to' => strtolower($prepared['to']),
                    'value' => (string) $prepared['value'],
                    'input' => strtolower($prepared['data']),
                ]);
        });

        $this->actingAs($user)
            ->postJson(route('wallets.ethereum.submitted'), [
                'attempt_id' => $attempt->id,
                'transaction_hash' => $hash,
            ])
            ->assertOk()
            ->assertJsonPath('swap.status', 'submitted')
            ->assertJsonPath('swap.transaction_hash', $hash);

        $attempt->refresh();

        $this->assertSame('submitted', $attempt->status);
        $this->assertSame($hash, $attempt->transaction_hash);
        $this->assertNotNull($attempt->submitted_at);
    }

    public function test_expired_prepared_attempt_rejects_unverifiable_broadcast(): void
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

        $hash = '0x'.str_repeat('ef', 32);

        $this->mock(EthereumService::class, fn ($mock) => $mock
            ->shouldReceive('getTransactionByHash')
            ->once()
            ->with($hash)
            ->andThrow(new RuntimeException('Ethereum transaction could not be verified.')));

        $this->actingAs($user)
            ->postJson(route('wallets.ethereum.submitted'), [
                'attempt_id' => $attempt->id,
                'transaction_hash' => $hash,
            ])
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'The broadcast Ethereum transaction could not be verified. Retry reporting the same transaction hash; do not send another transaction.'
            );

        $attempt->refresh();

        $this->assertSame('prepared', $attempt->status);
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

    public function test_scheduler_expired_attempt_can_be_recorded_as_submitted_when_broadcast_matches(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = $this->wallet($user);

        $attempt = EthereumSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'buy_token' => self::TOKEN,
            'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => $this->quote()['transaction'],
            'status' => 'expired',
            'expires_at' => now()->subMinute(),
        ]);

        $hash = '0x'.str_repeat('ab', 32);
        $prepared = $attempt->transaction_payload;

        $this->partialMock(EthereumService::class, function ($mock) use ($hash, $wallet, $prepared): void {
            $mock->shouldReceive('getTransactionByHash')
                ->once()
                ->with($hash)
                ->andReturn([
                    'chain_id' => '1',
                    'hash' => $hash,
                    'from' => strtolower($wallet->address),
                    'to' => strtolower($prepared['to']),
                    'value' => (string) $prepared['value'],
                    'input' => strtolower($prepared['data']),
                ]);
        });

        $this->actingAs($user)
            ->postJson(route('wallets.ethereum.submitted'), [
                'attempt_id' => $attempt->id,
                'transaction_hash' => $hash,
            ])
            ->assertOk()
            ->assertJsonPath('swap.status', 'submitted')
            ->assertJsonPath('swap.transaction_hash', $hash);

        $attempt->refresh();

        $this->assertSame('submitted', $attempt->status);
        $this->assertSame($hash, $attempt->transaction_hash);
        $this->assertNotNull($attempt->submitted_at);
    }

    #[DataProvider('transactionMismatches')]
    public function test_submission_rejects_transaction_mismatch_without_attaching_hash(array $changes): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $attempt = $this->preparedAttempt($user);
        $this->fakeTransaction($changes);

        $this->actingAs($user)->postJson(route('wallets.ethereum.submitted'), [
            'attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('ab', 32),
        ])->assertUnprocessable()->assertJsonPath('message', 'The broadcast Ethereum transaction does not match the prepared swap.');

        $this->assertDatabaseHas('ethereum_swap_attempts', [
            'id' => $attempt->id, 'status' => 'prepared', 'transaction_hash' => null, 'submitted_at' => null,
        ]);
        Http::assertSentCount(1);
    }

    public static function transactionMismatches(): array
    {
        return [
            'unrelated hash' => [['hash' => '0x'.str_repeat('cd', 32)]],
            'sender' => [['from' => '0x'.str_repeat('3', 40)]],
            'destination' => [['to' => '0x'.str_repeat('3', 40)]],
            'value' => [['value' => '0x1']],
            'calldata' => [['input' => '0x123400']],
            'network' => [['chainId' => '0x89']],
        ];
    }

    public function test_submission_normalizes_address_case_and_equivalent_quantities(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $attempt = $this->preparedAttempt($user);
        $address = '0x'.str_repeat('aB', 20);
        $attempt->connectedWallet->update(['address' => $address]);
        $attempt->update(['transaction_payload' => array_replace($this->quote()['transaction'], [
            'from' => $address,
            'to' => '0x'.str_repeat('Cd', 20),
            'value' => '0x00038D7EA4C68000',
            'data' => '0xABcd',
            'chainId' => '0x0001',
        ])]);
        $this->fakeTransaction([
            'from' => strtolower($address), 'to' => '0x'.str_repeat('cD', 20),
            'value' => '0x000038d7ea4c68000', 'input' => '0xabCD', 'chainId' => '0x0001',
        ]);

        $this->actingAs($user)->postJson(route('wallets.ethereum.submitted'), [
            'attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('AB', 32),
        ])->assertOk()->assertJsonPath('swap.transaction_hash', '0x'.str_repeat('ab', 32));

        $this->assertSame('submitted', $attempt->fresh()->status);
        Http::assertSentCount(1);
    }

    #[DataProvider('ambiguousRpcResponses')]
    public function test_ambiguous_rpc_preserves_attempt_and_allows_same_hash_retry(string $failure): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $attempt = $this->preparedAttempt($user);
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake(['https://ethereum.test' => function () use ($failure, &$calls) {
            if (++$calls > 1) {
                return Http::response(['result' => $this->rpcTransaction()]);
            }

            return match ($failure) {
                'missing' => Http::response(['result' => null]),
                'error' => Http::response(['error' => ['code' => -32000, 'message' => 'Unavailable']]),
                'connection' => Http::failedConnection(),
            };
        }]);
        $payload = ['attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('ab', 32)];

        $this->actingAs($user)->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertStatus(503)->assertJsonPath('retryable', true)
            ->assertJsonPath('transaction_hash', $payload['transaction_hash']);

        $this->assertDatabaseHas('ethereum_swap_attempts', [
            'id' => $attempt->id, 'status' => 'prepared', 'transaction_hash' => null, 'submitted_at' => null,
        ]);
        $this->postJson(route('wallets.ethereum.submitted'), $payload)->assertOk();
        $this->assertSame($payload['transaction_hash'], $attempt->fresh()->transaction_hash);
    }

    public static function ambiguousRpcResponses(): array
    {
        return [['missing'], ['error'], ['connection']];
    }

    public function test_accepted_hash_cannot_be_replaced_and_terminal_retry_preserves_state(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $attempt = $this->preparedAttempt($user);
        $this->fakeTransaction();
        $payload = ['attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('ab', 32)];
        $this->actingAs($user)->postJson(route('wallets.ethereum.submitted'), $payload)->assertOk();
        $submittedAt = $attempt->fresh()->submitted_at;
        $attempt->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        $this->postJson(route('wallets.ethereum.submitted'), $payload)
            ->assertOk()->assertJsonPath('swap.status', 'confirmed');
        $this->postJson(route('wallets.ethereum.submitted'), array_replace($payload, [
            'transaction_hash' => '0x'.str_repeat('cd', 32),
        ]))->assertUnprocessable();

        $attempt->refresh();
        $this->assertSame($payload['transaction_hash'], $attempt->transaction_hash);
        $this->assertSame('confirmed', $attempt->status);
        $this->assertTrue($submittedAt->equalTo($attempt->submitted_at));
        Http::assertSentCount(1);
    }

    public function test_expiry_during_rpc_lookup_does_not_prevent_verified_submission(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $attempt = $this->preparedAttempt($user);
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);
        Http::preventStrayRequests();
        Http::fake(['https://ethereum.test' => function () use ($attempt) {
            $attempt->update(['status' => 'expired', 'expires_at' => now()->subSecond()]);

            return Http::response(['result' => $this->rpcTransaction()]);
        }]);

        $this->actingAs($user)->postJson(route('wallets.ethereum.submitted'), [
            'attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('ab', 32),
        ])->assertOk()->assertJsonPath('swap.status', 'submitted');

        $this->assertSame('submitted', $attempt->fresh()->status);
    }

    #[DataProvider('submissionRaces')]
    public function test_submission_rechecks_locked_state_after_rpc(string $status, ?string $hash, int $expectedStatus): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $attempt = $this->preparedAttempt($user);
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);
        Http::preventStrayRequests();
        Http::fake(['https://ethereum.test' => function () use ($attempt, $status, $hash) {
            $attempt->update(['status' => $status, 'transaction_hash' => $hash]);

            return Http::response(['result' => $this->rpcTransaction()]);
        }]);

        $this->actingAs($user)->postJson(route('wallets.ethereum.submitted'), [
            'attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('ab', 32),
        ])->assertStatus($expectedStatus);

        $attempt->refresh();
        $this->assertSame($status, $attempt->status);
        $this->assertSame($hash, $attempt->transaction_hash);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string, ?string, int}> */
    public static function submissionRaces(): array
    {
        return [
            'cancelled' => ['cancelled', null, 422],
            'different transaction accepted' => ['submitted', '0x'.str_repeat('cd', 32), 422],
            'same transaction accepted' => ['submitted', '0x'.str_repeat('ab', 32), 200],
        ];
    }

    private function preparedAttempt(User $user): EthereumSwapAttempt
    {
        return EthereumSwapAttempt::query()->create([
            'user_id' => $user->id, 'connected_wallet_id' => $this->wallet($user)->id,
            'buy_token' => self::TOKEN, 'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100, 'transaction_payload' => $this->quote()['transaction'],
            'status' => 'prepared', 'expires_at' => now()->addMinute(),
        ]);
    }

    /** @param array<string, string> $changes */
    private function fakeTransaction(array $changes = []): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);
        Http::preventStrayRequests();
        Http::fake(['https://ethereum.test' => Http::response(['result' => array_replace($this->rpcTransaction(), $changes)])]);
    }

    /** @return array<string, string> */
    private function rpcTransaction(): array
    {
        return [
            'hash' => '0x'.str_repeat('ab', 32), 'from' => self::WALLET,
            'to' => self::TOKEN, 'value' => '0x38d7ea4c68000', 'input' => '0x1234', 'chainId' => '0x1',
        ];
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
