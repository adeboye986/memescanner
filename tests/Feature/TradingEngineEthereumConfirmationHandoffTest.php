<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\ConnectedWallet;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use App\Services\EthereumOpportunityRevalidationService;
use App\Services\EthereumPreparedAttemptIntegrity;
use App\Services\EthereumReceiptReconciliationService;
use App\Services\EthereumService;
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionDecision;
use App\Services\TradingEngine\TradingEngineLiveDecisionIntegration;
use App\Services\TradingEngine\TradingEngineLivePreparationIntegration;
use App\Services\TradingEngine\TradingEngineOpportunityDecision;
use App\Services\ZeroXSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TradingEngineEthereumConfirmationHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0x2222222222222222222222222222222222222222';

    private const WALLET = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-29 23:30:00');
        config()->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('h', 32)),
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => true,
            'services.trading_engine.decision_boundary_enabled' => true,
            'services.trading_engine.live_decision_integration_enabled' => true,
            'services.trading_engine.live_preparation_enabled' => true,
        ]);
        app(ApplicationSettingsService::class)->update([
            'risk.kill_switch' => false,
            'risk.max_trade_amount' => '0.1',
            'risk.max_slippage_percent' => 1,
        ]);
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
    }

    public function test_engine_prepared_attempt_is_sealed_and_stops_before_human_action(): void
    {
        [$opportunity, $attempt] = $this->prepared();

        $this->assertSame(EthereumPreparedAttemptIntegrity::ENGINE_ORIGIN, $attempt->preparation_origin);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $attempt->preparation_binding_sha256);
        $this->assertSame('prepared', $attempt->status);
        $this->assertNull($attempt->signing_requested_at);
        $this->assertNull($attempt->signing_armed_at);
        $this->assertNull($attempt->transaction_hash);
        $this->assertNull($attempt->submitted_at);
        $this->assertSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_only_authenticated_owner_can_claim_and_arm_the_existing_wallet_handoff(): void
    {
        [$opportunity, $attempt] = $this->prepared();

        $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->postJson(route('opportunities.ethereum.confirm', $opportunity))
            ->assertNotFound();

        $claim = $this->actingAs($opportunity->user)
            ->postJson(route('opportunities.ethereum.confirm', $opportunity), [
                'attempt_id' => 999,
                'sell_amount_wei' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('order.attempt_id', $attempt->id)
            ->assertJsonPath('order.transaction.value', '100000000000000000')
            ->json('order.signing_claim_token');

        $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertStatus(409);
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'arm']), [
            'signing_claim_token' => $claim,
        ])->assertOk()->assertJsonPath('armed', true);
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'arm']), [
            'signing_claim_token' => $claim,
        ])->assertStatus(409);

        $this->assertNotNull($attempt->fresh()->signing_requested_at);
        $this->assertNotNull($attempt->fresh()->signing_armed_at);
        $this->assertNull($attempt->fresh()->transaction_hash);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_tampered_binding_and_changed_wallet_fail_before_handoff(): void
    {
        [$opportunity, $attempt, $wallet] = $this->prepared();

        DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->update([
            'sell_amount_wei' => '1',
        ]);
        $this->actingAs($opportunity->user)
            ->postJson(route('opportunities.ethereum.confirm', $opportunity))
            ->assertStatus(409)
            ->assertJsonPath('message', 'The prepared Ethereum attempt failed its integrity check.');

        DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->update([
            'sell_amount_wei' => '100000000000000000',
        ]);
        $wallet->update(['address' => '0x'.str_repeat('9', 40)]);
        $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertStatus(422);

        $this->assertNull($attempt->fresh()->signing_requested_at);
        $this->assertNull($attempt->fresh()->transaction_hash);
        Http::assertNothingSent();
    }

    public function test_engine_attempt_cannot_be_reported_without_armed_human_confirmation(): void
    {
        [$opportunity, $attempt] = $this->prepared();
        app(EthereumService::class)->shouldNotReceive('getTransactionByHash');

        $this->actingAs($opportunity->user)->postJson(route('wallets.ethereum.submitted'), [
            'attempt_id' => $attempt->id,
            'transaction_hash' => '0x'.str_repeat('a', 64),
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Explicit wallet confirmation is required before transaction reporting.');

        $this->assertNull($attempt->fresh()->transaction_hash);
        $this->assertNull($attempt->fresh()->submitted_at);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_expiry_kill_switch_and_user_disable_fail_closed_without_repreparation(): void
    {
        [$expired, $attempt] = $this->prepared();
        $this->travel(61)->seconds();

        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($expired));
        $this->actingAs($expired->user)
            ->postJson(route('opportunities.ethereum.confirm', $expired))
            ->assertStatus(409);
        $this->assertNull($attempt->fresh()->signing_requested_at);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);

        $this->travelBack();
        [$killSwitch, $killAttempt] = $this->prepared('0x'.str_repeat('5', 40));
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]);
        $this->actingAs($killSwitch->user)
            ->postJson(route('opportunities.ethereum.confirm', $killSwitch))
            ->assertStatus(422);
        $this->assertNull($killAttempt->fresh()->signing_requested_at);

        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => false]);
        [$disabled, $disabledAttempt] = $this->prepared('0x'.str_repeat('6', 40));
        $disabled->user->tradingPreference()->update(['trading_enabled' => false]);
        $this->actingAs($disabled->user)
            ->postJson(route('opportunities.ethereum.confirm', $disabled))
            ->assertStatus(422);
        $this->assertNull($disabledAttempt->fresh()->signing_requested_at);
    }

    public function test_duplicate_reporting_and_reconciliation_create_one_submission_and_position(): void
    {
        [$opportunity, $attempt] = $this->prepared();
        $claim = $this->actingAs($opportunity->user)
            ->postJson(route('opportunities.ethereum.confirm', $opportunity))
            ->assertOk()
            ->json('order.signing_claim_token');
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'arm']), [
            'signing_claim_token' => $claim,
        ])->assertOk();

        $hash = '0x'.str_repeat('a', 64);
        app(EthereumService::class)->shouldReceive('getTransactionByHash')->once()->andReturn([
            'chain_id' => '1',
            'hash' => $hash,
            'from' => self::WALLET,
            'to' => '0x'.str_repeat('4', 40),
            'value' => '100000000000000000',
            'input' => '0x1234',
        ]);
        app(EthereumService::class)->shouldReceive('matchesPreparedTransaction')->once()->andReturnTrue();
        $report = ['attempt_id' => $attempt->id, 'transaction_hash' => $hash];
        $this->postJson(route('wallets.ethereum.submitted'), $report)->assertOk();
        $this->postJson(route('wallets.ethereum.submitted'), $report)->assertOk();

        $receipt = [
            'transaction_hash' => $hash,
            'succeeded' => true,
            'block_number' => '100',
            'gas_used' => '21000',
            'effective_gas_price_wei' => '1',
            'actual_network_fee_wei' => '21000',
        ];
        $reconciliation = app(EthereumReceiptReconciliationService::class);
        $this->assertSame('confirmed', $reconciliation->apply($attempt->fresh(), $receipt));
        $this->assertNull($reconciliation->apply($attempt->fresh(), $receipt));
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertDatabaseCount('live_positions', 1);
        $this->assertSame(1, $opportunity->events()->where('action', 'live_execution_confirmed')->count());
    }

    /** @return array{TradeOpportunity, \App\Models\EthereumSwapAttempt, ConnectedWallet} */
    private function prepared(string $walletAddress = self::WALLET): array
    {
        $user = User::factory()->create();
        UserTradingPreference::factory()->create([
            'user_id' => $user->id,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Auto,
            'trading_enabled' => true,
        ]);
        $wallet = ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Ethereum,
            'address' => $walletAddress,
            'address_hash' => ConnectedWallet::addressHash(Chain::Ethereum, $walletAddress),
            'verified_at' => now(),
        ]);
        $opportunity = TradeOpportunity::factory()->create([
            'user_id' => $user->id,
            'chain' => Chain::Ethereum,
            'address' => self::TOKEN,
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Auto,
            'qualified_at' => now(),
        ]);
        $this->mock(TradingEngineLiveDecisionIntegration::class)
            ->shouldReceive('assess')
            ->twice()
            ->andReturn(TradingEngineOpportunityDecision::wouldEnter(
                TradingEngineEvaluationConsumptionDecision::eligible(
                    '01KC0000000000000000012345',
                    '01KC0000000000000000067890',
                ),
                'AUTO_ENTRY_CONFIGURED',
            ));
        $this->mock(EthereumOpportunityRevalidationService::class)
            ->shouldReceive('check')
            ->once()
            ->andReturn([
                'passed' => true,
                'failure_class' => null,
                'reason' => 'qualified',
                'scanner' => 'new-token',
                'checked_at' => now()->toIso8601String(),
            ]);
        $this->mock(EthereumService::class)
            ->shouldReceive('getBalanceWei')
            ->once()
            ->with($walletAddress)
            ->andReturn('1000000000000000000');
        $this->mock(ZeroXSwapService::class)
            ->shouldReceive('quote')
            ->once()
            ->with($walletAddress, self::TOKEN, '100000000000000000', 100)
            ->andReturn([
                'quote_id' => 'engine-preparation-quote',
                'network_fee_wei' => '21000',
                'transaction' => [
                    'from' => $walletAddress,
                    'to' => '0x'.str_repeat('4', 40),
                    'value' => '100000000000000000',
                    'data' => '0x1234',
                    'chainId' => '1',
                    'gas' => '21000',
                    'gasPrice' => '1',
                ],
            ]);

        $attempt = app(TradingEngineLivePreparationIntegration::class)->attempt($opportunity);
        $this->assertNotNull($attempt);

        return [$opportunity, $attempt, $wallet];
    }
}
