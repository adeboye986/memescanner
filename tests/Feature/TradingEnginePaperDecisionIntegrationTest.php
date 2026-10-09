<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Jobs\ProjectTradingEngineEvent;
use App\Jobs\SubmitTradingEnginePaperEntry;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEnginePaperEntryIntent;
use App\Models\User;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use App\Services\EntryPolicy;
use App\Services\TradeExecutionManager;
use App\Services\TradeOpportunityService;
use App\Services\Trading\LiveTradeExecutor;
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionPolicy;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEnginePaperDecisionIntegration;
use App\Services\UserTelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TradingEnginePaperDecisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/internal/trading-engine/events';

    private const SECRET = 'test-only-webhook-secret-32-bytes-long';

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-29 20:00:00');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => true,
            'services.trading_engine.decision_boundary_enabled' => true,
            'services.trading_engine.paper_decision_integration_enabled' => true,
            'services.trading_engine.webhook_secret' => self::SECRET,
            'services.trading_engine.webhook_timestamp_tolerance_seconds' => 60,
            'services.trading_engine.webhook_body_max_bytes' => 262144,
        ]);
    }

    public function test_disabled_integration_preserves_legacy_paper_auto_behavior(): void
    {
        config()->set('services.trading_engine.paper_decision_integration_enabled', false);
        app(ApplicationSettingsService::class)->update([
            'trading.execution_mode' => ExecutionMode::Paper->value,
            'trading.entry_mode' => EntryMode::Auto->value,
        ]);
        $user = User::factory()->create(['is_admin' => true]);

        $result = app(TradeOpportunityService::class)->qualify($this->candidate(), $user);

        $this->assertInstanceOf(PaperPosition::class, $result['position']);
        $this->assertSame(TradeOpportunityStatus::Executed, $result['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 1);
    }

    public function test_enabled_integration_defers_auto_entry_until_projection_exists(): void
    {
        app(ApplicationSettingsService::class)->update([
            'trading.execution_mode' => ExecutionMode::Paper->value,
            'trading.entry_mode' => EntryMode::Auto->value,
        ]);
        $user = User::factory()->create(['is_admin' => true]);

        $result = app(TradeOpportunityService::class)->qualify($this->candidate(), $user);

        $this->assertNull($result['position']);
        $this->assertSame(TradeOpportunityStatus::Qualified, $result['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_cutover_routes_one_canary_entry_without_laravel_financial_writes(): void
    {
        Queue::fake();
        $chain = $this->chain(2_001);
        UserTradingPreference::factory()->create([
            'user_id' => $chain['opportunity']->user_id,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Auto,
            'trading_enabled' => true,
        ]);
        config()->set([
            'services.trading_engine.paper_entry_integration_enabled' => true,
            'services.trading_engine.paper_entry_canary_user_ids' => (string) $chain['opportunity']->user_id,
        ]);

        $beforeWallets = PaperWallet::query()->count();
        $first = $this->integration()->attempt($chain['opportunity']);
        $second = $this->integration()->attempt($chain['opportunity']->fresh());
        $intent = TradingEnginePaperEntryIntent::query()->sole();

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(TradeOpportunityStatus::Qualified, $chain['opportunity']->fresh()->status);
        $this->assertNull($chain['opportunity']->fresh()->paper_position_id);
        $this->assertSame('pending', $intent->status);
        $this->assertSame('paper:entry:laravel:'.$chain['opportunity']->getKey().':v1', $intent->idempotency_key);
        $this->assertDatabaseCount('trading_engine_paper_entry_intents', 1);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertSame($beforeWallets, PaperWallet::query()->count());
        Queue::assertPushed(SubmitTradingEnginePaperEntry::class, 1);
        $this->assertNoLiveOrWalletSideEffects();
    }

    public function test_cutover_blocks_repeated_engine_entry_when_matching_legacy_position_is_open(): void
    {
        Queue::fake();
        $chain = $this->chain(2_002);
        $position = $this->legacyPosition(
            $chain['opportunity']->user,
            $chain['opportunity']->address,
        );
        $this->enablePaperEntryCutover($chain['opportunity']->user_id);

        $first = $this->integration()->attempt($chain['opportunity']);
        $second = $this->integration()->attempt($chain['opportunity']->fresh());

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(TradeOpportunityStatus::Qualified, $chain['opportunity']->fresh()->status);
        $this->assertNull($chain['opportunity']->fresh()->paper_position_id);
        $this->assertModelExists($position);
        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertDatabaseCount('trading_engine_paper_entry_intents', 0);
        Queue::assertNotPushed(SubmitTradingEnginePaperEntry::class);
        $this->assertNoLiveOrWalletSideEffects();
    }

    public function test_cutover_allows_engine_entry_for_a_different_token(): void
    {
        Queue::fake();
        $chain = $this->chain(2_003);
        $this->legacyPosition(
            $chain['opportunity']->user,
            'So11111111111111111111111111111111111111112',
        );
        $this->enablePaperEntryCutover($chain['opportunity']->user_id);

        $this->assertNull($this->integration()->attempt($chain['opportunity']));

        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertDatabaseCount('trading_engine_paper_entry_intents', 1);
        Queue::assertPushed(SubmitTradingEnginePaperEntry::class, 1);
    }

    public function test_cutover_allows_engine_entry_when_another_user_owns_the_open_token(): void
    {
        Queue::fake();
        $chain = $this->chain(2_004);
        $this->legacyPosition(User::factory()->create(), $chain['opportunity']->address);
        $this->enablePaperEntryCutover($chain['opportunity']->user_id);

        $this->assertNull($this->integration()->attempt($chain['opportunity']));

        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertDatabaseCount('trading_engine_paper_entry_intents', 1);
        Queue::assertPushed(SubmitTradingEnginePaperEntry::class, 1);
    }

    public function test_cutover_allows_engine_entry_when_the_matching_legacy_position_is_closed(): void
    {
        Queue::fake();
        $chain = $this->chain(2_005);
        $this->legacyPosition($chain['opportunity']->user, $chain['opportunity']->address, [
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $this->enablePaperEntryCutover($chain['opportunity']->user_id);

        $this->assertNull($this->integration()->attempt($chain['opportunity']));

        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertDatabaseCount('trading_engine_paper_entry_intents', 1);
        Queue::assertPushed(SubmitTradingEnginePaperEntry::class, 1);
    }

    public function test_non_canary_duplicate_preserves_the_existing_legacy_entry_path(): void
    {
        Queue::fake();
        $chain = $this->chain(2_006);
        $position = $this->legacyPosition(
            $chain['opportunity']->user,
            $chain['opportunity']->address,
        );
        $this->enablePaperEntryCutover(User::factory()->create()->getKey());

        $result = $this->integration()->attempt($chain['opportunity']);

        $this->assertInstanceOf(PaperPosition::class, $result);
        $this->assertTrue($position->is($result));
        $this->assertSame(TradeOpportunityStatus::Executed, $chain['opportunity']->fresh()->status);
        $this->assertSame($position->getKey(), $chain['opportunity']->fresh()->paper_position_id);
        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertDatabaseCount('trading_engine_paper_entry_intents', 0);
        Queue::assertNotPushed(SubmitTradingEnginePaperEntry::class);
        $this->assertNoLiveOrWalletSideEffects();
    }

    public function test_verified_would_enter_uses_existing_paper_path_once(): void
    {
        Http::preventStrayRequests();
        $chain = $this->chain(1);

        $first = $this->integration()->attempt($chain['opportunity']);
        $second = $this->integration()->attempt($chain['opportunity']->fresh());

        $this->assertInstanceOf(PaperPosition::class, $first);
        $this->assertTrue($first->is($second));
        $this->assertSame(TradeOpportunityStatus::Executed, $chain['opportunity']->fresh()->status);
        $this->assertSame($first->getKey(), $chain['opportunity']->fresh()->paper_position_id);
        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertEqualsWithDelta(
            4.9,
            (float) PaperWallet::query()->where('user_id', $chain['opportunity']->user_id)->sole()->available_balance_sol,
            0.000001,
        );
        Http::assertNothingSent();
        $this->assertNoLiveOrWalletSideEffects();
    }

    #[DataProvider('prerequisiteFlagProvider')]
    public function test_every_prerequisite_flag_fails_closed(string $flag): void
    {
        $chain = $this->chain(10 + crc32($flag) % 1000);
        config()->set($flag, false);

        $this->assertNull($this->integration()->attempt($chain['opportunity']));
        $this->assertSame(TradeOpportunityStatus::Qualified, $chain['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    /** @return array<string, array{string}> */
    public static function prerequisiteFlagProvider(): array
    {
        return [
            'engine integration' => ['services.trading_engine.enabled'],
            'projection' => ['services.trading_engine.opportunity_projection_enabled'],
            'evaluation consumption' => ['services.trading_engine.evaluation_consumption_enabled'],
            'decision boundary' => ['services.trading_engine.decision_boundary_enabled'],
            'paper integration' => ['services.trading_engine.paper_decision_integration_enabled'],
        ];
    }

    #[DataProvider('nonPassingOutcomeProvider')]
    public function test_non_passing_evaluations_prevent_paper_auto_entry(string $outcome, string $reasonCode): void
    {
        $chain = $this->chain($outcome === 'failed' ? 20 : 21, [
            'outcome' => $outcome,
            'reason_codes' => [$reasonCode],
        ]);

        $this->assertNull($this->integration()->attempt($chain['opportunity']));
        $this->assertSame(TradeOpportunityStatus::Qualified, $chain['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function nonPassingOutcomeProvider(): array
    {
        return [
            'failed' => ['failed', 'LIQUIDITY_BELOW_MINIMUM'],
            'indeterminate' => ['indeterminate', 'REQUIRED_FACT_MISSING'],
        ];
    }

    public function test_missing_link_and_missing_evaluation_each_fail_closed(): void
    {
        $missingLink = $this->opportunity(30);
        $missingEvaluation = $this->chain(31, ['omit_evaluation' => true]);

        $this->assertNull($this->integration()->attempt($missingLink));
        $this->assertNull($this->integration()->attempt($missingEvaluation['opportunity']));
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_integrity_mismatch_and_unapproved_policy_each_fail_closed(): void
    {
        $hashMismatch = $this->chain(32, ['source_request_sha256' => str_repeat('0', 64)]);
        $unapprovedPolicy = $this->chain(33, ['algorithm_version' => 2]);

        $this->assertNull($this->integration()->attempt($hashMismatch['opportunity']));
        $this->assertNull($this->integration()->attempt($unapprovedPolicy['opportunity']));
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_kill_switch_and_disabled_user_each_prevent_entry(): void
    {
        $killSwitch = $this->chain(40);
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]);
        $this->assertNull($this->integration()->attempt($killSwitch['opportunity']));

        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => false]);
        $disabledUser = $this->chain(41);
        UserTradingPreference::factory()->create([
            'user_id' => $disabledUser['opportunity']->user_id,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Auto,
            'trading_enabled' => false,
        ]);
        $this->assertNull($this->integration()->attempt($disabledUser['opportunity']));

        $this->assertDatabaseCount('paper_positions', 0);
    }

    #[DataProvider('holdingEntryModeProvider')]
    public function test_confirm_and_signal_remain_existing_hold_behaviors(
        EntryMode $entryMode,
        TradeOpportunityStatus $expectedStatus,
    ): void {
        $opportunity = $this->opportunity(50 + ($entryMode === EntryMode::Confirm ? 1 : 2), [
            'entry_mode' => $entryMode,
        ]);

        $this->assertNull(app(EntryPolicy::class)->apply($opportunity));
        $this->assertSame($expectedStatus, $opportunity->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    /** @return array<string, array{EntryMode, TradeOpportunityStatus}> */
    public static function holdingEntryModeProvider(): array
    {
        return [
            'confirm' => [EntryMode::Confirm, TradeOpportunityStatus::PendingConfirmation],
            'signal' => [EntryMode::Signal, TradeOpportunityStatus::Qualified],
        ];
    }

    public function test_live_opportunity_cannot_cross_paper_only_boundary(): void
    {
        $this->mock(LiveTradeExecutor::class)->shouldNotReceive('execute');
        $chain = $this->chain(60, [
            'opportunity' => ['execution_mode' => ExecutionMode::Live],
        ]);

        $this->assertNull($this->integration()->attempt($chain['opportunity']));

        try {
            app(TradeExecutionManager::class)->executePaper($chain['opportunity']);
            $this->fail('The PAPER-only execution boundary accepted a LIVE opportunity.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The PAPER-only execution boundary rejected a non-PAPER opportunity.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(TradeOpportunityStatus::Qualified, $chain['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertNoLiveOrWalletSideEffects();
    }

    public function test_entry_policy_fails_closed_when_integration_throws_unexpectedly(): void
    {
        $integration = $this->mock(TradingEnginePaperDecisionIntegration::class);
        $integration->shouldReceive('attempt')->once()->andThrow(new RuntimeException('synthetic failure'));
        $opportunity = $this->opportunity(61);

        $this->assertNull(app(EntryPolicy::class)->apply($opportunity));
        $this->assertSame(TradeOpportunityStatus::Qualified, $opportunity->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_projected_evaluation_reenters_gate_and_sends_one_existing_paper_notification(): void
    {
        $notifications = $this->mock(UserTelegramNotificationService::class);
        $notifications->shouldReceive('send')->once();
        app(ApplicationSettingsService::class)->update([
            'trading.execution_mode' => ExecutionMode::Paper->value,
            'trading.entry_mode' => EntryMode::Auto->value,
        ]);
        $user = User::factory()->create(['is_admin' => true]);
        $candidate = $this->candidate();
        $candidate['send_notification'] = true;
        $qualified = app(TradeOpportunityService::class)->qualify($candidate, $user);
        $projection = $this->pendingProjection(70, [
            'existing_opportunity' => $qualified['opportunity'],
        ]);
        $job = new ProjectTradingEngineEvent($projection['recorded']->event_id);

        $this->assertNull($qualified['position']);
        $job->handle(app(TradingEngineOpportunityProjector::class), $this->integration());
        $job->handle(app(TradingEngineOpportunityProjector::class), $this->integration());

        $this->assertSame(TradeOpportunityStatus::Executed, $projection['opportunity']->fresh()->status);
        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertNoLiveOrWalletSideEffects();
    }

    public function test_signed_fractional_events_execute_one_idempotent_paper_entry(): void
    {
        $this->travelTo('2026-10-02 18:05:15');
        $opportunity = $this->opportunity(92, [
            'address' => 'So11111111111111111111111111111111111111112',
        ]);
        $eventData = $this->eventData($opportunity, 92, []);
        $recordedEvent = $this->webhookEnvelope($eventData['recorded'], '2026-10-02T18:05:14.755Z');
        $evaluatedEvent = $this->webhookEnvelope($eventData['evaluated'], '2026-10-02T18:05:14.987Z');

        $this->sendSigned($recordedEvent)
            ->assertAccepted();
        $this->assertDatabaseCount('paper_positions', 0);

        $this->sendSigned($evaluatedEvent)
            ->assertAccepted();

        $opportunity->refresh();
        $wallet = PaperWallet::query()
            ->where('user_id', $opportunity->user_id)
            ->where('chain', Chain::Solana->value)
            ->sole();
        $position = PaperPosition::query()->sole();

        $this->assertSame(TradeOpportunityStatus::Executed, $opportunity->status);
        $this->assertSame($position->getKey(), $opportunity->paper_position_id);
        $this->assertSame($opportunity->getKey(), data_get($position->meta, 'trade_opportunity_id'));
        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
        $this->assertEqualsWithDelta(
            4.9,
            (float) $wallet->available_balance_sol,
            0.000001,
        );
        $this->assertEqualsWithDelta(
            0.1,
            (float) $wallet->invested_balance_sol,
            0.000001,
        );

        $this->sendSigned($evaluatedEvent)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $wallet->refresh();
        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertSame($position->getKey(), $opportunity->fresh()->paper_position_id);
        $this->assertEqualsWithDelta(
            4.9,
            (float) $wallet->available_balance_sol,
            0.000001,
        );
        $this->assertEqualsWithDelta(
            0.1,
            (float) $wallet->invested_balance_sol,
            0.000001,
        );
        $this->assertNoLiveOrWalletSideEffects();
    }

    public function test_export_flag_is_independent_from_paper_decision_integration(): void
    {
        $disabledExport = $this->chain(80);
        config()->set('services.trading_engine.opportunity_export_enabled', false);
        $this->assertInstanceOf(PaperPosition::class, $this->integration()->attempt($disabledExport['opportunity']));

        $enabledExport = $this->chain(81);
        config()->set('services.trading_engine.opportunity_export_enabled', true);
        $this->assertInstanceOf(PaperPosition::class, $this->integration()->attempt($enabledExport['opportunity']));

        $this->assertDatabaseCount('paper_positions', 2);
    }

    private function integration(): TradingEnginePaperDecisionIntegration
    {
        return app(TradingEnginePaperDecisionIntegration::class);
    }

    private function enablePaperEntryCutover(int $canaryUserId): void
    {
        UserTradingPreference::factory()->create([
            'user_id' => $canaryUserId,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Auto,
            'trading_enabled' => true,
        ]);
        config()->set([
            'services.trading_engine.paper_entry_integration_enabled' => true,
            'services.trading_engine.paper_entry_canary_user_ids' => (string) $canaryUserId,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function legacyPosition(User $user, string $address, array $overrides = []): PaperPosition
    {
        return PaperPosition::query()->create([
            'user_id' => $user->getKey(),
            'chain' => Chain::Solana,
            'address' => $address,
            'symbol' => 'LEGACY',
            'entry_market_cap' => 12_000,
            'entry_price' => 0.001,
            'entry_at' => now(),
            'status' => 'open',
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            ...$overrides,
        ]);
    }

    private function assertNoLiveOrWalletSideEffects(): void
    {
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('connected_wallets', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
    }

    /** @return array<string, mixed> */
    private function candidate(): array
    {
        return [
            'chain' => Chain::Solana->value,
            'address' => 'So11111111111111111111111111111111111111112',
            'symbol' => 'TEST',
            'name' => 'Test Token',
            'entry_market_cap' => 12000,
            'discovery_market_cap' => 10000,
            'entry_price' => 0.001,
            'entry_liquidity' => 1000,
            'scanner' => 'new-token',
            'send_notification' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function opportunity(int $sequence, array $overrides = []): TradeOpportunity
    {
        return TradeOpportunity::factory()->for(User::factory())->create([
            'chain' => Chain::Solana,
            'discovery_key' => hash('sha256', 'paper-decision-discovery-'.$sequence),
            'address' => 'So'.str_pad((string) $sequence, 42, '1', STR_PAD_LEFT),
            'symbol' => 'TEST',
            'name' => 'Test Token',
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Auto,
            'price' => 0.001,
            'market_cap' => 12000,
            'liquidity' => 1000,
            'volume' => 5000,
            'qualification_data' => [
                'discovery_market_cap' => 10000,
                'move_since_discovery_percent' => 20,
                'send_notification' => false,
                'meta' => [],
            ],
            'security_data' => ['score' => 95],
            'qualified_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *   opportunity: TradeOpportunity,
     *   recorded: TradingEngineEvent,
     *   link: TradingEngineOpportunityLink,
     *   evaluation_event: TradingEngineEvent,
     *   evaluation: ?TradingEngineOpportunityEvaluation
     * }
     */
    private function chain(int $sequence, array $options = []): array
    {
        $opportunity = $this->opportunity($sequence, $options['opportunity'] ?? []);
        $eventData = $this->eventData($opportunity, $sequence, $options);
        $recorded = $this->event($eventData['recorded']);
        $link = TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => $eventData['engine_opportunity_id'],
            'recorded_event_id' => $recorded->event_id,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $opportunity->discovery_key,
            'scanner' => $opportunity->scanner,
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
            'recorded_at' => $recorded->occurred_at,
            'linked_at' => now(),
        ]);
        $evaluationEvent = $this->event($eventData['evaluated']);
        $evaluation = ($options['omit_evaluation'] ?? false)
            ? null
            : TradingEngineOpportunityEvaluation::query()->create([
                'opportunity_link_id' => $link->getKey(),
                'trade_opportunity_id' => $opportunity->getKey(),
                'evaluation_id' => $eventData['evaluation_id'],
                'engine_opportunity_id' => $eventData['engine_opportunity_id'],
                'evaluation_event_id' => $evaluationEvent->event_id,
                'recorded_event_id' => $recorded->event_id,
                ...$eventData['evaluation_record'],
                'correlation_id' => $evaluationEvent->correlation_id,
                'traceparent' => $evaluationEvent->traceparent,
                'evaluated_at' => $evaluationEvent->occurred_at,
                'event_received_at' => $evaluationEvent->received_at,
                'projected_at' => now(),
            ]);

        return compact('opportunity', 'recorded', 'link', 'evaluationEvent', 'evaluation') + [
            'evaluation_event' => $evaluationEvent,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{opportunity: TradeOpportunity, recorded: TradingEngineEvent, evaluated: TradingEngineEvent}
     */
    private function pendingProjection(int $sequence, array $options = []): array
    {
        $opportunity = $options['existing_opportunity'] ?? $this->opportunity($sequence, $options['opportunity'] ?? []);
        $eventData = $this->eventData($opportunity, $sequence, $options);
        $recorded = $this->event($eventData['recorded'], TradingEngineEvent::STATUS_STORED);
        $evaluated = $this->event($eventData['evaluated'], TradingEngineEvent::STATUS_STORED);

        return compact('opportunity', 'recorded', 'evaluated');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *   engine_opportunity_id: string,
     *   evaluation_id: string,
     *   recorded: array<string, mixed>,
     *   evaluated: array<string, mixed>,
     *   evaluation_record: array<string, mixed>
     * }
     */
    private function eventData(TradeOpportunity $opportunity, int $sequence, array $options): array
    {
        $engineOpportunityId = $this->engineId($sequence * 10 + 1);
        $recordedEventId = $this->engineId($sequence * 10 + 2);
        $evaluationEventId = $this->engineId($sequence * 10 + 3);
        $evaluationId = $this->engineId($sequence * 10 + 4);
        $operationId = $this->engineId($sequence * 10 + 5);
        $body = [
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-laravel',
                'opportunity_id' => (string) $opportunity->getKey(),
                'discovery_key' => $opportunity->discovery_key,
                'scanner' => $opportunity->scanner,
            ],
            'subject' => ['control_plane_user_id' => (string) $opportunity->user_id],
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'asset' => [
                'address' => $opportunity->address,
                'symbol' => $opportunity->symbol,
            ],
            'market_snapshot' => [
                'market_cap_usd' => ['value' => '12000', 'provider' => 'birdeye'],
                'liquidity_usd' => ['value' => '1000', 'provider' => 'birdeye'],
            ],
            'qualification' => [
                'qualified_at' => '2026-09-29T20:00:00.000Z',
                'discovery_market_cap_usd' => '10000',
                'move_since_discovery_percent' => '20',
                'classification' => 'strong',
            ],
            'security' => [
                'status' => 'passed',
                'provider' => 'goplus',
                'passed' => true,
            ],
        ];
        $sourceRequestSha256 = $options['source_request_sha256'] ?? $this->canonicalHash($body);
        $evaluationInputSha256 = $this->canonicalHash([
            'policy_definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            'source_request_sha256' => $sourceRequestSha256,
        ]);
        $outcome = $options['outcome'] ?? 'passed';
        $reasonCodes = $options['reason_codes'] ?? [];
        $advisoryCodes = [];
        $algorithmVersion = $options['algorithm_version'] ?? TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_VERSION;
        $evidence = [
            'profile' => 'solana:new-token',
            'facts' => [
                'market_cap_usd' => '12000',
                'liquidity_usd' => '1000',
                'volume_5m_usd' => null,
                'move_since_discovery_percent' => '20',
                'classification' => 'strong',
                'pair_address' => null,
                'pair_available' => null,
                'requested_token_is_base' => null,
                'security_status' => 'passed',
                'security_provider' => 'goplus',
                'security_passed' => true,
            ],
            'checks' => [
                ['check' => 'market_cap', 'status' => 'passed'],
                ['check' => 'liquidity', 'status' => 'passed'],
                ['check' => 'movement', 'status' => 'passed'],
                ['check' => 'classification', 'status' => 'passed'],
                ['check' => 'security', 'status' => 'passed'],
            ],
        ];
        $evaluationRecord = [
            'policy_key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY,
            'policy_version' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_VERSION,
            'algorithm_key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_KEY,
            'algorithm_version' => $algorithmVersion,
            'policy_definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            'source_request_sha256' => $sourceRequestSha256,
            'evaluation_input_sha256' => $evaluationInputSha256,
            'outcome' => $outcome,
            'reason_codes' => $reasonCodes,
            'advisory_codes' => $advisoryCodes,
            'evidence' => $evidence,
        ];
        $resultSha256 = $this->canonicalHash($evaluationRecord);
        $evaluationRecord['result_sha256'] = $resultSha256;
        $recordedPayload = [
            'operation_id' => $operationId,
            'opportunity_id' => $engineOpportunityId,
            ...$body,
        ];
        $evaluationPayload = [
            'evaluation_id' => $evaluationId,
            'opportunity_id' => $engineOpportunityId,
            'policy' => [
                'key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY,
                'version' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_VERSION,
                'algorithm_key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_KEY,
                'algorithm_version' => $algorithmVersion,
                'definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            ],
            'source' => [
                'request_sha256' => $sourceRequestSha256,
                'evaluation_input_sha256' => $evaluationInputSha256,
            ],
            'outcome' => $outcome,
            'reason_codes' => $reasonCodes,
            'advisory_codes' => $advisoryCodes,
            'evidence' => $evidence,
            'result_sha256' => $resultSha256,
        ];

        return [
            'engine_opportunity_id' => $engineOpportunityId,
            'evaluation_id' => $evaluationId,
            'recorded' => [
                'event_id' => $recordedEventId,
                'event_type' => 'opportunity.recorded.v1',
                'aggregate_type' => 'opportunity',
                'aggregate_id' => $engineOpportunityId,
                'causation_id' => $operationId,
                'idempotency_key' => 'opportunity:record:laravel:'.$opportunity->getKey().':v1',
                'payload' => $recordedPayload,
            ],
            'evaluated' => [
                'event_id' => $evaluationEventId,
                'event_type' => 'opportunity.evaluated.v1',
                'aggregate_type' => 'opportunity_evaluation',
                'aggregate_id' => $evaluationId,
                'causation_id' => $recordedEventId,
                'correlation_id' => 'correlation-'.$engineOpportunityId,
                'idempotency_key' => 'evaluation:'.$engineOpportunityId.':'.TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY.':1',
                'payload' => $evaluationPayload,
            ],
            'evaluation_record' => $evaluationRecord,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function webhookEnvelope(array $event, string $occurredAt): array
    {
        $payload = $event['payload'];

        return [
            'event_id' => $event['event_id'],
            'event_type' => $event['event_type'],
            'schema_version' => 1,
            'occurred_at' => $occurredAt,
            'producer' => 'trading-engine',
            'aggregate_type' => $event['aggregate_type'],
            'aggregate_id' => $event['aggregate_id'],
            'aggregate_version' => 1,
            'correlation_id' => $event['correlation_id'] ?? 'correlation-'.$event['aggregate_id'],
            'causation_id' => $event['causation_id'],
            'idempotency_key' => $event['idempotency_key'],
            'traceparent' => self::TRACEPARENT,
            'payload' => $payload,
            'payload_sha256' => $this->canonicalHash($payload),
        ];
    }

    /** @param array<string, mixed> $event */
    private function sendSigned(array $event): TestResponse
    {
        $rawBody = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.POST.'.self::PATH.'.'.$rawBody, self::SECRET);

        return $this->call('POST', self::PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENGINE_TIMESTAMP' => $timestamp,
            'HTTP_X_ENGINE_SIGNATURE' => 'v1='.$signature,
            'HTTP_X_ENGINE_EVENT_ID' => $event['event_id'],
            'HTTP_X_CORRELATION_ID' => $event['correlation_id'],
            'HTTP_TRACEPARENT' => $event['traceparent'],
        ], $rawBody);
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides, string $status = TradingEngineEvent::STATUS_PROJECTED): TradingEngineEvent
    {
        $payload = $overrides['payload'];

        return TradingEngineEvent::query()->create([
            'event_id' => $overrides['event_id'],
            'event_type' => $overrides['event_type'],
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => $overrides['aggregate_type'],
            'aggregate_id' => $overrides['aggregate_id'],
            'aggregate_version' => 1,
            'correlation_id' => $overrides['correlation_id'] ?? 'correlation-'.$overrides['aggregate_id'],
            'causation_id' => $overrides['causation_id'],
            'idempotency_key' => $overrides['idempotency_key'],
            'traceparent' => self::TRACEPARENT,
            'payload' => $payload,
            'payload_sha256' => $this->canonicalHash($payload),
            'raw_body_sha256' => str_repeat('a', 64),
            'event_envelope' => [],
            'handling_status' => $status,
            'handling_attempts' => $status === TradingEngineEvent::STATUS_PROJECTED ? 1 : 0,
            'received_at' => now(),
            'handled_at' => $status === TradingEngineEvent::STATUS_PROJECTED ? now() : null,
        ]);
    }

    private function engineId(int $number): string
    {
        return '01KC'.str_pad((string) $number, 22, '0', STR_PAD_LEFT);
    }

    private function canonicalHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalValue($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalValue(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }
}
