<?php

namespace Tests\Feature;

use App\Enums\TradeOpportunityStatus;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\Chains\EthereumChainAdapter;
use App\Services\DexScreenerService;
use App\Services\EthereumOpportunityReservationService;
use App\Services\EthereumService;
use App\Services\UserTradingPreferenceService;
use App\Services\ZeroXSwapService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EthereumOpportunityPreparationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0x2222222222222222222222222222222222222222';

    private const WALLET = '0x1111111111111111111111111111111111111111';

    public function test_preparation_updates_same_row_and_returns_only_signing_fields(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->providers();
        $response = $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity), [
            'buy_token' => '0x'.str_repeat('9', 40), 'sell_amount_wei' => '9', 'slippage_bps' => 500,
            'connected_wallet_id' => 999, 'user_id' => 999,
        ])->assertOk()->assertJsonPath('order.attempt_id', $attempt->id)
            ->assertJsonPath('order.transaction.from', self::WALLET)
            ->assertJsonPath('order.transaction.value', '1000000000000000');
        $this->assertSame(['order'], array_keys($response->json()));
        $this->assertSame(['attempt_id', 'transaction', 'expires_at'], array_keys($response->json('order')));
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertSame('prepared', $attempt->fresh()->status);
        $this->assertSame(self::TOKEN, $attempt->fresh()->buy_token);
        $this->assertSame('1000000000000000', $attempt->fresh()->sell_amount_wei);
        $this->assertEquals(100, $attempt->fresh()->slippage_bps);
        $this->assertTrue($attempt->fresh()->revalidation_data['passed']);
        $this->assertNull($attempt->fresh()->preparation_token);
        $this->assertSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        $this->assertNull($opportunity->fresh()->executed_at);
        $this->assertDatabaseCount('paper_positions', 0);
        Http::assertNothingSent();
    }

    public function test_duplicate_prepare_returns_same_payload_without_second_provider_call(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->providers();
        $first = $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertOk()->json();
        $second = $this->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertOk()->json();
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public function test_wrong_user_and_guest_cannot_prepare(): void
    {
        [, $opportunity] = $this->reservation();
        $this->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertNotFound();
        Http::assertNothingSent();
    }

    #[DataProvider('contextChanges')]
    public function test_invalid_context_prevents_provider_calls(string $change): void
    {
        [$user, $opportunity, $attempt, $wallet] = $this->reservation();
        $this->changeContext($change, $user, $opportunity, $wallet);
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertUnprocessable();
        if ($attempt->fresh()) {
            $this->assertNotSame('prepared', $attempt->fresh()->status);
        }
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        if ($change !== 'chain') {
            $this->assertNotSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        }
        Http::assertNothingSent();
    }

    public static function contextChanges(): array
    {
        return array_map(fn ($change) => [$change], ['chain', 'wallet changed', 'wallet unverified', 'wallet disconnected', 'wallet deleted', 'kill switch', 'disabled', 'paper', 'auto', 'signal', 'risk amount', 'risk slippage', 'token', 'ignored']);
    }

    #[DataProvider('marketFailures')]
    public function test_failed_fresh_market_validation_is_terminal(array $changes): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->market(array_replace_recursive($this->validMarket(), $changes));
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertUnprocessable();
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::Failed, $opportunity->fresh()->status);
        $this->assertFalse($attempt->fresh()->revalidation_data['passed']);
        $this->assertNull($attempt->fresh()->transaction_payload);
        Http::assertNothingSent();
    }

    public static function marketFailures(): array
    {
        return [[['available' => false]], [['liquidity_usd' => 499]], [['market_cap' => 20001]], [['market_cap' => 1999]],
            [['requested_token_is_base' => false]], [['base_token_address' => 'wrong']], [['raw' => ['chainId' => 'solana']]]];
    }

    public function test_unavailable_market_provider_fails_closed_without_leaving_preparing(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->mock(EthereumChainAdapter::class)->shouldReceive('liveMarketData')->once()->andThrow(new RuntimeException('private provider detail'));
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(503)->assertDontSee('private provider detail');
        $this->assertSame('released', $attempt->fresh()->status);
        $this->assertSame('revalidation_unavailable', $attempt->fresh()->revalidation_data['reason']);
    }

    #[DataProvider('securityFailures')]
    public function test_failed_or_wrong_security_data_blocks_preparation(array $security): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->market($this->validMarket(), $security);
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(503);
        $this->assertSame('released', $attempt->fresh()->status);
        $this->assertSame('security_revalidation_failed', $attempt->fresh()->revalidation_data['reason']);
        Http::assertNothingSent();
    }

    public static function securityFailures(): array
    {
        return [[[]], [['available' => false]], [['available' => true, 'passed' => false]],
            [['available' => true, 'passed' => true, 'chain' => 'solana', 'address' => self::TOKEN]],
            [['available' => true, 'passed' => true, 'chain' => 'ethereum', 'address' => 'wrong']]];
    }

    #[DataProvider('goPlusFailures')]
    public function test_real_goplus_failure_releases_without_quote_or_second_attempt(string $failure): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->mock(DexScreenerService::class)->shouldReceive('analyzeToken')->once()->with(self::TOKEN, 'ethereum', true, false)->andReturn($this->validMarket());
        $this->mock(EthereumService::class)->shouldNotReceive('getBalanceWei');
        $this->mock(ZeroXSwapService::class)->shouldNotReceive('quote');
        Http::fake(['api.gopluslabs.io/*' => match ($failure) {
            'timeout' => Http::failedConnection(),
            'error' => Http::response([], 500),
            'malformed' => Http::response('not json'),
            'missing token' => Http::response(['code' => 1, 'result' => []]),
            'honeypot' => Http::response(['code' => 1, 'result' => [self::TOKEN => array_replace($this->goPlusSafe(), ['is_honeypot' => '1'])]]),
        }]);
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus($failure === 'honeypot' ? 422 : 503);
        $this->assertSame($failure === 'honeypot' ? 'failed' : 'released', $attempt->fresh()->status);
        $this->assertSame($failure === 'honeypot' ? TradeOpportunityStatus::Failed : TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_payload);
        $this->assertNull($attempt->fresh()->transaction_hash);
        $this->assertNull($opportunity->fresh()->executed_at);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame($failure === 'honeypot' ? TradeOpportunityStatus::Failed : TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
    }

    public static function goPlusFailures(): array
    {
        return [['timeout'], ['error'], ['malformed'], ['missing token'], ['honeypot']];
    }

    public function test_real_ethereum_goplus_adapter_can_prepare_with_all_providers_mocked(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->providers(null, false);
        $level = DB::transactionLevel();
        $this->mock(DexScreenerService::class)->shouldReceive('analyzeToken')->once()->andReturnUsing(function () use ($level): array {
            $this->assertSame($level, DB::transactionLevel());

            return $this->validMarket();
        });
        Http::fake(['api.gopluslabs.io/*' => function ($request) use ($level) {
            $this->assertSame($level, DB::transactionLevel());
            $this->assertStringContainsString('/token_security/1?', $request->url());
            $this->assertSame(self::TOKEN, $request['contract_addresses']);

            return Http::response(['code' => 1, 'result' => [self::TOKEN => $this->goPlusSafe()]]);
        }]);
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertOk();
        $this->assertSame('prepared', $attempt->fresh()->status);
        $this->assertSame($this->goPlusSafe(), $attempt->fresh()->revalidation_data['security']['checks']);
        $this->assertNull($attempt->fresh()->transaction_hash);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        Http::assertSentCount(1);
    }

    private function goPlusSafe(): array
    {
        return ['is_open_source' => '1', 'is_mintable' => '0', 'owner_change_balance' => '0',
            'transfer_pausable' => '0', 'is_honeypot' => '0', 'cannot_buy' => '0', 'cannot_sell_all' => '0'];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_releases_for_explicit_reapproval(string $failure): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->market($this->validMarket(), $this->validSecurity());
        $balance = $this->mock(EthereumService::class);
        if ($failure === 'rpc') {
            $balance->shouldReceive('getBalanceWei')->once()->andThrow(new RuntimeException('secret rpc detail'));
        } else {
            $balance->shouldReceive('getBalanceWei')->once()->andReturn($failure === 'balance' ? '1' : '1000000000000000000');
            if ($failure === 'quote') {
                $this->mock(ZeroXSwapService::class)->shouldReceive('quote')->once()->andThrow(new RuntimeException('secret 0x detail'));
            }
        }
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))
            ->assertStatus($failure === 'balance' ? 422 : 503)->assertDontSee('secret');
        $this->assertSame('released', $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_payload);
        $this->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(409);
        $this->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('success');
        $this->assertSame('reserved', $attempt->fresh()->status);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public static function providerFailures(): array
    {
        return [['rpc'], ['quote'], ['balance']];
    }

    public function test_malformed_real_zero_x_response_releases_without_payload(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->market($this->validMarket(), $this->validSecurity());
        $this->mock(EthereumService::class)->shouldReceive('getBalanceWei')->once()->andReturn('1000000000000000000');
        config(['services.zero_x.base_url' => 'https://api.0x.org', 'services.zero_x.api_key' => 'test']);
        Http::fake(['https://api.0x.org/*' => Http::response(['transaction' => ['to' => 'unsafe']])]);
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(503);
        $this->assertSame('released', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_payload);
        Http::assertSentCount(1);
    }

    public function test_cleanup_releases_reserved_and_crashed_preparing_without_repeating_events(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        config(['services.ethereum.opportunity_max_age_seconds' => 900]);
        $this->travel(6)->minutes();
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame('released', $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('success');
        $attempt->refresh()->update(['status' => 'preparing', 'preparation_token' => 'crashed', 'preparation_expires_at' => now()->subSecond()]);
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame('released', $attempt->fresh()->status);
        $events = $opportunity->events()->count();
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame($events, $opportunity->events()->count());
        Http::assertNothingSent();
    }

    public function test_expired_published_payload_is_retained_and_cannot_be_reapproved(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->providers();
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertOk();
        $before = $attempt->fresh()->transaction_payload;
        $this->travel(2)->minutes();
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame('expired', $attempt->fresh()->status);
        $this->assertSame($before, $attempt->fresh()->transaction_payload);
        $this->assertSame(TradeOpportunityStatus::Expired, $opportunity->fresh()->status);
        $this->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('error');
    }

    #[DataProvider('submittedStatuses')]
    public function test_cleanup_never_reverts_submitted_or_confirmed_attempt(string $status): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $attempt->update(['status' => $status, 'transaction_hash' => '0x'.str_repeat('a', 64), 'expires_at' => now()->subMinute(), 'preparation_expires_at' => now()->subMinute()]);
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame($status, $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        Http::assertNothingSent();
    }

    public static function submittedStatuses(): array
    {
        return [['submitted'], ['confirmed']];
    }

    public function test_two_logical_prepares_and_ignore_during_provider_call_cannot_start_another_preparation(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->actingAs($user);
        $this->providers(function () use ($opportunity): void {
            $this->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(409);
            $this->post(route('opportunities.ignore', $opportunity))->assertSessionHas('error');
        });
        $this->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertOk();
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    #[DataProvider('contextChanges')]
    public function test_context_change_during_provider_call_prevents_publication(string $change): void
    {
        [$user, $opportunity, $attempt, $wallet] = $this->reservation();
        $this->providers(fn () => $this->changeContext($change, $user, $opportunity, $wallet));
        $response = $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity));
        $this->assertContains($response->status(), [409, 422]);
        if ($attempt->fresh()) {
            $this->assertNull($attempt->fresh()->transaction_payload);
            $this->assertNotSame('prepared', $attempt->fresh()->status);
        }
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
    }

    #[DataProvider('stalePreparationChanges')]
    public function test_stale_lease_or_revalidation_cannot_publish(string $change): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->providers(function () use ($change, $opportunity, $attempt): void {
            match ($change) {
                'lease' => $attempt->refresh()->update(['preparation_expires_at' => now()->subSecond()]),
                'market age' => $this->travel(61)->seconds(),
                'scanner' => $opportunity->update(['scanner' => 'momentum']),
            };
        });
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(409);
        $this->assertNull($attempt->fresh()->transaction_payload);
        $this->assertSame('released', $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
    }

    public static function stalePreparationChanges(): array
    {
        return [['lease'], ['market age'], ['scanner']];
    }

    public function test_expiry_cleanup_during_rpc_verification_preserves_late_broadcast_recovery(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->providers();
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertOk();
        $payload = $attempt->fresh()->transaction_payload;
        $hash = '0x'.str_repeat('a', 64);
        $this->partialMock(EthereumService::class)->shouldReceive('getTransactionByHash')->once()->with($hash)
            ->andReturnUsing(function () use ($payload, $hash, $opportunity): array {
                $this->travel(2)->minutes();
                $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
                $this->assertSame(TradeOpportunityStatus::Expired, $opportunity->fresh()->status);

                return ['chain_id' => '1', 'hash' => $hash, 'from' => self::WALLET,
                    'to' => $payload['to'], 'value' => $payload['value'], 'input' => $payload['data']];
            });
        $report = ['attempt_id' => $attempt->id, 'transaction_hash' => $hash];
        $this->postJson(route('wallets.ethereum.submitted'), $report)->assertOk();
        $this->postJson(route('wallets.ethereum.submitted'), $report)->assertOk();
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        $this->assertSame('submitted', $opportunity->fresh()->execution_data['stage']);
        $this->assertNull($opportunity->fresh()->executed_at);
        Http::assertNothingSent();
    }

    public function test_cleanup_fences_out_late_provider_response_and_cannot_affect_new_claim(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->providers(function () use ($user, $opportunity, $attempt): void {
            $attempt->refresh()->update(['preparation_expires_at' => now()->subSecond()]);
            $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
            app(EthereumOpportunityReservationService::class)->reserve($opportunity, $user, $this->input());
            $attempt->refresh()->update(['status' => 'preparing', 'preparation_token' => 'new-claim', 'preparation_expires_at' => now()->addMinute()]);
        });
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(409);
        $this->assertSame('new-claim', $attempt->fresh()->preparation_token);
        $this->assertSame('preparing', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_payload);
    }

    public function test_cancellation_releases_published_opportunity_without_clearing_payload(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->providers();
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertOk();
        $this->postJson(route('wallets.ethereum.cancelled'), ['attempt_id' => $attempt->id])->assertOk();
        $this->assertSame('cancelled', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->transaction_payload);
        $this->assertSame(TradeOpportunityStatus::Ignored, $opportunity->fresh()->status);
    }

    public function test_preparing_and_released_intentions_are_excluded_from_history(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        foreach (['reserved', 'preparing', 'released'] as $status) {
            $attempt->update(['status' => $status]);
            $this->actingAs($user)->getJson(route('wallets.ethereum.history'))->assertOk()->assertJsonCount(0, 'transactions');
        }
    }

    /** @return array{User, TradeOpportunity, EthereumSwapAttempt, ConnectedWallet} */
    private function reservation(): array
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => false, 'risk.max_trade_amount' => '0.1', 'risk.max_slippage_percent' => 1]);
        app(UserTradingPreferenceService::class)->forUser($user)->update(['execution_mode' => 'live', 'entry_mode' => 'confirm', 'trading_enabled' => true]);
        $wallet = ConnectedWallet::query()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'address' => self::WALLET,
            'address_hash' => ConnectedWallet::addressHash('ethereum', self::WALLET), 'verified_at' => now()]);
        $opportunity = TradeOpportunity::factory()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'address' => self::TOKEN,
            'scanner' => 'new-token', 'execution_mode' => 'live', 'entry_mode' => 'confirm', 'status' => 'pending_confirmation', 'qualified_at' => now()]);
        $attempt = app(EthereumOpportunityReservationService::class)->reserve($opportunity, $user, $this->input());

        return [$user, $opportunity, $attempt, $wallet];
    }

    public function test_unknown_security_requires_explicit_reapproval_and_fresh_checks_on_same_row(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->market($this->validMarket(), ['available' => false, 'passed' => false, 'failure_class' => 'malformed']);
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(503);
        $this->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(409);
        $this->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('success');
        $this->providers();
        $this->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertOk()->assertJsonPath('order.attempt_id', $attempt->id);
        $this->assertSame('prepared', $attempt->fresh()->status);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    #[DataProvider('recoveryAges')]
    public function test_cleanup_uses_actual_qualification_age(string $status, int $maximumAge, int $elapsed, bool $fresh): void
    {
        config(['services.ethereum.opportunity_max_age_seconds' => $maximumAge]);
        [$user, $opportunity, $attempt] = $this->reservation();
        if ($status === 'preparing') {
            $attempt->update(['status' => 'preparing', 'preparation_token' => 'abandoned', 'preparation_expires_at' => now()->addMinutes(3)]);
        }
        $this->travel($elapsed)->seconds();
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame($fresh ? 'released' : 'expired', $attempt->fresh()->status);
        $this->assertSame($fresh ? TradeOpportunityStatus::PendingConfirmation : TradeOpportunityStatus::Expired, $opportunity->fresh()->status);
        $events = $opportunity->events()->count();
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertSame($events, $opportunity->events()->count());
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas($fresh ? 'success' : 'error');
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public static function recoveryAges(): array
    {
        return [['reserved', 900, 360, true], ['reserved', 300, 360, false],
            ['preparing', 300, 181, true], ['preparing', 300, 301, false], ['reserved', 600, 601, false]];
    }

    public function test_outage_that_crosses_freshness_deadline_expires_instead_of_advertising_retry(): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        $this->mock(EthereumChainAdapter::class)->shouldReceive('liveMarketData')->once()->andReturnUsing(function (): array {
            $this->travel(301)->seconds();
            throw new RuntimeException('unavailable');
        });
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(503);
        $this->assertSame('expired', $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::Expired, $opportunity->fresh()->status);
    }

    #[DataProvider('databaseFailureStages')]
    public function test_database_failure_logs_no_lease_binding(string $stage): void
    {
        [$user, $opportunity, $attempt] = $this->reservation();
        Log::spy();
        if ($stage === 'prepared') {
            $this->providers();
        }
        EthereumSwapAttempt::updating(function (EthereumSwapAttempt $model) use ($stage): void {
            if ($model->status === $stage) {
                throw new QueryException('testing', 'update attempts set preparation_token = ?', ['private-lease-token'], new \PDOException('failed'));
            }
        });
        $this->actingAs($user)->postJson(route('opportunities.ethereum.prepare', $opportunity))->assertStatus(503)->assertDontSee('private-lease-token');
        Log::shouldHaveReceived('warning')->once()->with($stage === 'prepared'
            ? 'Ethereum preparation database operation failed.' : 'Ethereum swap database operation failed.');
        Log::shouldNotHaveReceived('error');
        $this->assertNull($attempt->fresh()->transaction_payload);
    }

    public static function databaseFailureStages(): array
    {
        return [['preparing'], ['prepared']];
    }

    private function changeContext(string $change, User $user, TradeOpportunity $opportunity, ConnectedWallet $wallet): void
    {
        $opportunity->refresh();
        match ($change) {
            'chain' => $opportunity->update(['chain' => 'solana']),
            'token' => $opportunity->update(['address' => '0x'.str_repeat('9', 40)]),
            'ignored' => $opportunity->update(['status' => 'ignored']),
            'wallet changed' => $wallet->update(['address' => '0x'.str_repeat('9', 40)]),
            'wallet unverified' => $wallet->update(['verified_at' => null]),
            'wallet disconnected' => $wallet->update(['disconnected_at' => now()]),
            'wallet deleted' => $wallet->delete(),
            'kill switch' => app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]),
            'disabled' => $user->tradingPreference()->update(['trading_enabled' => false]),
            'paper' => $user->tradingPreference()->update(['execution_mode' => 'paper']),
            'auto' => $user->tradingPreference()->update(['entry_mode' => 'auto']),
            'signal' => $user->tradingPreference()->update(['entry_mode' => 'signal']),
            'risk amount' => app(ApplicationSettingsService::class)->update(['risk.max_trade_amount' => '0.0001']),
            'risk slippage' => app(ApplicationSettingsService::class)->update(['risk.max_slippage_percent' => 0.5]),
        };
    }

    /** @return array{sell_amount_wei: string, slippage_bps: int} */
    private function input(): array
    {
        return ['sell_amount_wei' => '1000000000000000', 'slippage_bps' => 100];
    }

    /** @return array<string, mixed> */
    private function validMarket(): array
    {
        return ['available' => true, 'requested_token_is_base' => true, 'base_token_address' => self::TOKEN,
            'requested_token_address' => self::TOKEN, 'market_cap' => 10000, 'liquidity_usd' => 2000, 'volume_5m' => 1000,
            'pair_address' => '0x'.str_repeat('3', 40), 'raw' => ['chainId' => 'ethereum', 'marketCap' => 10000, 'liquidity' => ['usd' => 2000], 'volume' => ['m5' => 1000], 'pairAddress' => '0x'.str_repeat('3', 40)]];
    }

    /** @return array<string, mixed> */
    private function validSecurity(): array
    {
        return ['available' => true, 'passed' => true, 'chain' => 'ethereum', 'address' => self::TOKEN, 'provider' => 'test-security'];
    }

    /** @param array<string, mixed> $market
     * @param  array<string, mixed>|null  $security
     */
    private function market(array $market, ?array $security = null): void
    {
        $market['raw']['marketCap'] = $market['market_cap'] ?? null;
        $market['raw']['liquidity']['usd'] = $market['liquidity_usd'] ?? null;
        $market['raw']['volume']['m5'] = $market['volume_5m'] ?? null;
        $adapter = $this->mock(EthereumChainAdapter::class);
        $adapter->shouldReceive('liveMarketData')->once()->with(self::TOKEN, 'new-token')->andReturn($market);
        if ($security !== null) {
            $adapter->shouldReceive('securityData')->once()->with(self::TOKEN)->andReturn($security);
        }
    }

    private function providers(?Closure $duringQuote = null, bool $mockMarket = true): void
    {
        if ($mockMarket) {
            $this->market($this->validMarket(), $this->validSecurity());
        }
        $level = DB::transactionLevel();
        $this->mock(EthereumService::class)->shouldReceive('getBalanceWei')->once()->with(self::WALLET)->andReturnUsing(function () use ($level): string {
            $this->assertSame($level, DB::transactionLevel());

            return '1000000000000000000';
        });
        $this->mock(ZeroXSwapService::class)->shouldReceive('quote')->once()->with(self::WALLET, self::TOKEN, '1000000000000000', 100)
            ->andReturnUsing(function () use ($level, $duringQuote): array {
                $this->assertSame($level, DB::transactionLevel());
                $duringQuote?->__invoke();

                return ['quote_id' => 'test-quote', 'network_fee_wei' => '21000', 'transaction' => [
                    'from' => self::WALLET, 'to' => '0x'.str_repeat('4', 40), 'value' => '1000000000000000',
                    'data' => '0x1234', 'chainId' => '1', 'gas' => '21000', 'gasPrice' => '1',
                ]];
            });
    }
}
