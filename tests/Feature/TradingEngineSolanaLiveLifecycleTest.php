<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\ConnectedWallet;
use App\Models\LivePosition;
use App\Models\SolanaSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use App\Services\JupiterSwapExecutionService;
use App\Services\JupiterSwapOrderService;
use App\Services\SolanaOpportunityPreparationService;
use App\Services\SolanaPreparedAttemptIntegrity;
use App\Services\SolanaReceiptReconciliationService;
use App\Services\SolanaService;
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionDecision;
use App\Services\TradingEngine\TradingEngineLiveDecisionIntegration;
use App\Services\TradingEngine\TradingEngineOpportunityDecision;
use App\Services\TradingEngine\TradingEngineSolanaLivePreparationIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TradingEngineSolanaLiveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP';

    private const TOKEN = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';

    private const SIGNATURE = '3333333333333333333333333333333333333333333333333333333333333333333333333333333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 12:00:00');
        config()->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => true,
            'services.trading_engine.decision_boundary_enabled' => true,
            'services.trading_engine.live_decision_integration_enabled' => true,
            'services.trading_engine.live_preparation_enabled' => true,
            'services.trading_engine.live_recovery_enabled' => true,
            'services.trading_engine.solana_live_integration_enabled' => true,
        ]);
        app(ApplicationSettingsService::class)->update([
            'risk.kill_switch' => false,
            'risk.max_trade_amount' => '0.001',
            'risk.max_slippage_percent' => 1,
        ]);
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
    }

    public function test_default_off_gate_creates_no_attempt_and_calls_no_decision_or_provider(): void
    {
        config()->set('services.trading_engine.solana_live_integration_enabled', false);
        $opportunity = $this->opportunity();
        $this->wallet($opportunity->user);
        $this->mock(TradingEngineLiveDecisionIntegration::class)->shouldNotReceive('assess');
        $this->mock(JupiterSwapOrderService::class)->shouldNotReceive('prepare');

        $this->assertNull(app(TradingEngineSolanaLivePreparationIntegration::class)->attempt($opportunity));
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_verified_auto_decision_prepares_once_seals_integrity_and_stops_before_wallet(): void
    {
        [$opportunity, $attempt] = $this->prepared();

        $this->assertSame('prepared', $attempt->status);
        $this->assertSame(SolanaPreparedAttemptIntegrity::ENGINE_ORIGIN, $attempt->preparation_origin);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $attempt->preparation_binding_sha256);
        $this->assertNull($attempt->signing_requested_at);
        $this->assertNull($attempt->signing_armed_at);
        $this->assertNull($attempt->transaction_signature);
        $this->assertNull($attempt->submitted_at);
        $this->assertSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        $this->assertSame('prepared', $opportunity->fresh()->execution_data['stage']);
        $this->assertDatabaseCount('solana_swap_attempts', 1);
        $this->assertDatabaseCount('live_positions', 0);

        $same = app(TradingEngineSolanaLivePreparationIntegration::class)->attempt($opportunity);
        $this->assertSame($attempt->id, $same?->id);
        $this->assertDatabaseCount('solana_swap_attempts', 1);
    }

    public function test_paper_non_auto_kill_switch_and_disabled_user_never_prepare(): void
    {
        $paper = $this->opportunity(['execution_mode' => ExecutionMode::Paper]);
        $confirm = $this->opportunity(['entry_mode' => EntryMode::Confirm]);
        $killSwitch = $this->opportunity();
        $disabled = $this->opportunity();
        foreach ([$paper, $confirm, $killSwitch, $disabled] as $index => $candidate) {
            $this->wallet($candidate->user, substr(self::WALLET, 0, -1).(string) ($index + 1));
        }
        $this->mock(TradingEngineLiveDecisionIntegration::class)->shouldReceive('assess')->andReturn($this->wouldEnter());
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]);

        $integration = app(TradingEngineSolanaLivePreparationIntegration::class);
        $this->assertNull($integration->attempt($paper));
        $this->assertNull($integration->attempt($confirm));
        $this->assertNull($integration->attempt($killSwitch));

        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => false]);
        $disabled->user->tradingPreference()->update(['trading_enabled' => false]);
        $this->assertNull($integration->attempt($disabled));
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_only_owner_can_claim_and_an_unarmed_attempt_cannot_be_reported(): void
    {
        [$opportunity, $attempt] = $this->prepared();

        $this->postJson(route('opportunities.solana.confirm', $opportunity))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->postJson(route('opportunities.solana.confirm', $opportunity))->assertNotFound();

        $claim = $this->actingAs($opportunity->user)
            ->postJson(route('opportunities.solana.confirm', $opportunity))
            ->assertOk()
            ->assertJsonPath('order.attempt_id', $attempt->id)
            ->json('order.signing_claim_token');
        $this->assertNotNull($attempt->fresh()->signing_requested_at);
        $this->assertNull($attempt->fresh()->transaction_signature);

        $this->mock(SolanaService::class)->shouldNotReceive('getTransactionBase64');
        $this->postJson(route('opportunities.solana.submitted', $opportunity), [
            'attempt_id' => $attempt->id,
            'transaction_signature' => self::SIGNATURE,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Explicit wallet confirmation is required before transaction reporting.');

        $this->postJson(route('opportunities.solana.signing', [$opportunity, 'arm']), [
            'signing_claim_token' => $claim,
        ])->assertOk()->assertJsonPath('armed', true);
        $this->assertNull($attempt->fresh()->transaction_signature);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_tampered_prepared_binding_fails_closed_before_wallet_handoff(): void
    {
        [$opportunity, $attempt] = $this->prepared();
        $this->assertSame(1, DB::table('solana_swap_attempts')
            ->where('id', $attempt->id)
            ->update(['input_amount_lamports' => 1]));

        $this->actingAs($opportunity->user)
            ->postJson(route('opportunities.solana.confirm', $opportunity))
            ->assertStatus(409)
            ->assertJsonPath('message', 'The prepared Solana attempt failed its integrity check.');

        $this->assertNull($attempt->fresh()->signing_requested_at);
        $this->assertNull($attempt->fresh()->transaction_signature);
    }

    public function test_same_chain_signature_is_verified_once_and_duplicate_reporting_is_idempotent(): void
    {
        [$opportunity, $attempt] = $this->prepared();
        $claim = $this->arm($opportunity);

        $this->mock(SolanaService::class)
            ->shouldReceive('getTransactionBase64')->once()->with(self::SIGNATURE)
            ->andReturn(base64_encode('signed-chain-transaction'));
        $this->mock(JupiterSwapExecutionService::class)
            ->shouldReceive('validateSigned')->once()
            ->withArgs(fn (SolanaSwapAttempt $candidate, string $bytes, string $wallet): bool => $candidate->id === $attempt->id
                && $bytes === base64_encode('signed-chain-transaction') && $wallet === self::WALLET)
            ->andReturn(['transaction_signature' => self::SIGNATURE]);

        $payload = ['attempt_id' => $attempt->id, 'transaction_signature' => self::SIGNATURE];
        $this->actingAs($opportunity->user)
            ->postJson(route('opportunities.solana.submitted', $opportunity), $payload)
            ->assertOk()->assertJsonPath('swap.status', 'submitted');
        $this->postJson(route('opportunities.solana.submitted', $opportunity), $payload)
            ->assertOk()->assertJsonPath('swap.transaction_signature', self::SIGNATURE);

        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $this->assertNotNull($attempt->submitted_at);
        $this->assertNotNull($attempt->signing_armed_at);
        $this->assertSame($claim, $this->claimTokenFromHash($attempt->signing_claim_hash, $claim));
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_a_different_chain_signature_is_rejected_without_recording_submission(): void
    {
        [$opportunity, $attempt] = $this->prepared();
        $this->arm($opportunity);

        $this->mock(SolanaService::class)
            ->shouldReceive('getTransactionBase64')->once()->with(self::SIGNATURE)
            ->andReturn(base64_encode('substituted-chain-transaction'));
        $this->mock(JupiterSwapExecutionService::class)
            ->shouldReceive('validateSigned')->once()
            ->andReturn(['transaction_signature' => str_repeat('4', 88)]);

        $this->actingAs($opportunity->user)
            ->postJson(route('opportunities.solana.submitted', $opportunity), [
                'attempt_id' => $attempt->id,
                'transaction_signature' => self::SIGNATURE,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The reported Solana transaction does not match the prepared swap.');

        $this->assertNull($attempt->fresh()->transaction_signature);
        $this->assertNull($attempt->fresh()->submitted_at);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_expired_preparation_is_recovered_by_bounded_cleanup_without_broadcast(): void
    {
        [$opportunity, $attempt] = $this->prepared();
        $this->travel(3)->minutes();

        $this->assertSame(1, app(SolanaOpportunityPreparationService::class)->cleanup());
        $this->assertSame('expired', $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::Expired, $opportunity->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_signature);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_failed_chain_receipt_creates_no_position_and_reconciliation_is_idempotent(): void
    {
        [$opportunity, $attempt] = $this->prepared();
        $this->arm($opportunity);
        $attempt->update([
            'status' => 'submitted', 'transaction_signature' => self::SIGNATURE, 'submitted_at' => now(),
        ]);
        $opportunity->refresh()->update([
            'execution_data' => [...($opportunity->execution_data ?? []), 'stage' => 'submitted'],
        ]);
        $receipt = [
            'succeeded' => false, 'slot' => 123456789, 'network_fee_lamports' => 5000,
            'error' => ['InstructionError' => [2, 'Custom']],
        ];

        $reconciliation = app(SolanaReceiptReconciliationService::class);
        $this->assertSame('failed', $reconciliation->apply($attempt->fresh(), $receipt));
        $this->assertNull($reconciliation->apply($attempt->fresh(), $receipt));

        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::Failed, $opportunity->fresh()->status);
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertSame(1, $opportunity->events()->where('action', 'live_execution_failed')->count());
    }

    public function test_confirmation_and_duplicate_reconciliation_create_exactly_one_position(): void
    {
        [$opportunity, $attempt] = $this->prepared();
        $this->arm($opportunity);
        $attempt->update([
            'status' => 'submitted', 'transaction_signature' => self::SIGNATURE, 'submitted_at' => now(),
        ]);
        $opportunity->refresh()->update([
            'execution_data' => [...($opportunity->execution_data ?? []), 'stage' => 'submitted'],
        ]);
        $receipt = [
            'succeeded' => true, 'slot' => 123456789, 'network_fee_lamports' => 5000,
            'error' => null, 'acquired_raw_amount' => '2500000', 'token_decimals' => 6,
        ];
        config()->set('services.trading_engine.solana_live_integration_enabled', false);

        $reconciliation = app(SolanaReceiptReconciliationService::class);
        $this->assertSame('confirmed', $reconciliation->apply($attempt->fresh(), $receipt));
        $this->assertNull($reconciliation->apply($attempt->fresh(), $receipt));

        $position = LivePosition::query()->where('trade_opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($position);
        $this->assertSame('solana', $position->chain->value);
        $this->assertSame('2500000', $position->acquired_raw_amount);
        $this->assertSame(6, $position->token_decimals);
        $this->assertSame('verified', $position->accounting_status);
        $this->assertDatabaseCount('live_positions', 1);
        $this->assertSame(1, $opportunity->events()->where('action', 'live_execution_confirmed')->count());
    }

    /** @return array{TradeOpportunity, SolanaSwapAttempt, ConnectedWallet} */
    private function prepared(): array
    {
        $opportunity = $this->opportunity();
        $wallet = $this->wallet($opportunity->user);
        $this->mock(TradingEngineLiveDecisionIntegration::class)
            ->shouldReceive('assess')->twice()->andReturn($this->wouldEnter());
        $this->mock(SolanaService::class)
            ->shouldReceive('getBalanceLamports')->once()->with(self::WALLET)->andReturn(10_000_000);
        $this->mock(JupiterSwapOrderService::class)
            ->shouldReceive('prepare')->once()->with(self::WALLET, self::TOKEN, '1000000', 100)
            ->andReturn([
                'request_id' => 'engine-solana-request',
                'transaction' => base64_encode('prepared-transaction'),
                'validation' => [
                    'message_hash' => str_repeat('a', 64),
                    'recent_blockhash' => '11111111111111111111111111111111',
                ],
            ]);

        $attempt = app(TradingEngineSolanaLivePreparationIntegration::class)->attempt($opportunity);
        $this->assertNotNull($attempt);

        return [$opportunity, $attempt, $wallet];
    }

    private function opportunity(array $overrides = []): TradeOpportunity
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        UserTradingPreference::factory()->create([
            'user_id' => $user->id, 'execution_mode' => ExecutionMode::Live,
            'entry_mode' => $overrides['entry_mode'] ?? EntryMode::Auto, 'trading_enabled' => true,
        ]);

        return TradeOpportunity::factory()->create([
            'user_id' => $user->id, 'chain' => Chain::Solana, 'address' => self::TOKEN,
            'scanner' => 'new-token', 'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::Live, 'entry_mode' => EntryMode::Auto,
            'qualified_at' => now(), ...$overrides,
        ]);
    }

    private function wallet(User $user, string $address = self::WALLET): ConnectedWallet
    {
        return ConnectedWallet::query()->create([
            'user_id' => $user->id, 'chain' => Chain::Solana, 'address' => $address,
            'address_hash' => ConnectedWallet::addressHash(Chain::Solana, $address),
            'provider' => 'phantom', 'verified_at' => now(), 'last_connected_at' => now(),
        ]);
    }

    private function wouldEnter(): TradingEngineOpportunityDecision
    {
        return TradingEngineOpportunityDecision::wouldEnter(
            TradingEngineEvaluationConsumptionDecision::eligible(
                '01KC0000000000000000012345',
                '01KC0000000000000000067890',
            ),
            'AUTO_ENTRY_CONFIGURED',
        );
    }

    private function arm(TradeOpportunity $opportunity): string
    {
        $claim = $this->actingAs($opportunity->user)
            ->postJson(route('opportunities.solana.confirm', $opportunity))
            ->assertOk()->json('order.signing_claim_token');
        $this->postJson(route('opportunities.solana.signing', [$opportunity, 'arm']), [
            'signing_claim_token' => $claim,
        ])->assertOk();

        return $claim;
    }

    private function claimTokenFromHash(?string $hash, string $claim): string
    {
        $this->assertNotNull($hash);
        $this->assertTrue(hash_equals($hash, hash('sha256', $claim)));

        return $claim;
    }
}
