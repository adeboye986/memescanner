<?php

namespace Tests\Feature;

use App\Chain;
use App\Exceptions\TradingEngineProjectionException;
use App\Jobs\SubmitTradingEnginePaperObservation;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEnginePaperExitSettlement;
use App\Models\TradingEnginePaperObservation;
use App\Models\TradingEnginePaperPositionLink;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEnginePaperLifecycleEnrollment;
use App\Services\TradingEngine\TradingEnginePaperLifecycleIntegration;
use App\Services\UserTelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingEnginePaperLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.paper_lifecycle_integration_enabled' => false,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => false,
            'services.trading_engine.paper_lifecycle_canary_user_ids' => '',
        ]);
    }

    public function test_enrollment_defaults_off_and_requires_both_gates_and_explicit_user_scope(): void
    {
        [$position, $opportunity] = $this->positionAndOpportunity();

        $this->assertNull(app(TradingEnginePaperLifecycleEnrollment::class)->enroll($position, $opportunity));

        config()->set('services.trading_engine.paper_lifecycle_integration_enabled', true);
        config()->set('services.trading_engine.paper_lifecycle_authoritative_enabled', true);
        $this->assertNull(app(TradingEnginePaperLifecycleEnrollment::class)->enroll($position, $opportunity));

        config()->set('services.trading_engine.paper_lifecycle_canary_user_ids', (string) $position->user_id);
        $link = app(TradingEnginePaperLifecycleEnrollment::class)->enroll($position, $opportunity);

        $this->assertInstanceOf(TradingEnginePaperPositionLink::class, $link);
        $this->assertSame('pending_registration', $link->ownership_state);
        $this->assertSame('trading-engine', data_get($link->ownership_snapshot, 'owner'));
    }

    public function test_existing_position_is_never_implicitly_enrolled(): void
    {
        [$position, $opportunity] = $this->positionAndOpportunity();
        config()->set([
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
            'services.trading_engine.paper_lifecycle_canary_user_ids' => (string) $position->user_id,
        ]);

        $this->assertNull(
            app(TradingEnginePaperLifecycleEnrollment::class)->enroll($position->fresh(), $opportunity),
        );
        $this->assertDatabaseCount('trading_engine_paper_position_links', 0);
    }

    public function test_enrollment_eligibility_requires_engine_gates_canary_solana_new_position_and_matching_user(): void
    {
        [$position, $opportunity] = $this->positionAndOpportunity();
        $enrollment = app(TradingEnginePaperLifecycleEnrollment::class);
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
            'services.trading_engine.paper_lifecycle_canary_user_ids' => (string) $position->user_id,
        ]);

        config()->set('services.trading_engine.enabled', false);
        $this->assertFalse($enrollment->eligible($position, $opportunity));

        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.paper_lifecycle_integration_enabled' => false,
        ]);
        $this->assertFalse($enrollment->eligible($position, $opportunity));

        config()->set([
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => false,
        ]);
        $this->assertFalse($enrollment->eligible($position, $opportunity));

        config()->set([
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
            'services.trading_engine.paper_lifecycle_canary_user_ids' => '',
        ]);
        $this->assertFalse($enrollment->eligible($position, $opportunity));

        config()->set('services.trading_engine.paper_lifecycle_canary_user_ids', (string) $position->user_id);
        $this->assertTrue($enrollment->eligible($position, $opportunity));
        $this->assertFalse($enrollment->eligible($position->fresh(), $opportunity));

        $position->setAttribute('chain', Chain::Ethereum);
        $this->assertFalse($enrollment->eligible($position, $opportunity));
        $position->setAttribute('chain', Chain::Solana);

        $opportunity->setAttribute('user_id', User::factory()->create()->getKey());
        $this->assertFalse($enrollment->eligible($position, $opportunity));
    }

    public function test_engine_owned_position_bypasses_local_stop_loss_and_queues_validated_observation(): void
    {
        [$position, $opportunity, $wallet] = $this->positionAndOpportunity();
        $this->registeredLink($position, $opportunity);
        config()->set([
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
        ]);
        $position->refresh();
        $positionState = $position->only([
            'status',
            'remaining_investment_sol',
            'remaining_fraction',
            'realized_value_multiple',
            'strategy_value_multiple',
            'strategy_return_percent',
            'peak_market_cap',
            'peak_multiple',
            'max_drawdown_percent',
            'tp_50_hit',
            'tp_2x_hit',
            'stop_loss_hit',
            'trailing_stop_hit',
            'exit_events',
            'realized_sol',
            'trade_pnl_sol',
        ]);
        $walletState = $wallet->only([
            'available_balance_sol',
            'invested_balance_sol',
            'realized_pnl_sol',
        ]);
        Http::fake([
            'api.dexscreener.com/tokens/v1/solana/*' => Http::response([[
                'chainId' => 'solana',
                'dexId' => 'raydium',
                'pairAddress' => 'paper-lifecycle-pair',
                'baseToken' => ['address' => $position->address, 'symbol' => $position->symbol],
                'quoteToken' => ['address' => 'So11111111111111111111111111111111111111112', 'symbol' => 'SOL'],
                'priceUsd' => '0.00085',
                'marketCap' => 8500,
                'liquidity' => ['usd' => 50000],
                'txns' => ['m5' => ['buys' => 1, 'sells' => 1]],
                'volume' => ['m5' => 1000],
            ]]),
        ]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $this->assertSame($positionState, $position->fresh()->only(array_keys($positionState)));
        $this->assertSame($walletState, $wallet->fresh()->only(array_keys($walletState)));
        $this->assertDatabaseCount('trading_engine_paper_observations', 1);
        $observation = TradingEnginePaperObservation::query()->sole();
        $this->assertSame($position->getKey(), $observation->paper_position_id);
        $this->assertSame('pending', $observation->status);
        $this->assertDatabaseCount('paper_position_snapshots', 0);
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 0);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 0);
        Queue::assertPushed(SubmitTradingEnginePaperObservation::class, 1);
    }

    public function test_legacy_position_without_lifecycle_link_keeps_existing_stop_loss_behavior(): void
    {
        [$position, , $wallet] = $this->positionAndOpportunity();
        config()->set([
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
        ]);
        $this->mock(UserTelegramNotificationService::class)
            ->shouldReceive('send')
            ->once();
        Http::fake([
            'api.dexscreener.com/tokens/v1/solana/*' => Http::response([[
                'chainId' => 'solana',
                'dexId' => 'raydium',
                'pairAddress' => 'legacy-paper-pair',
                'baseToken' => ['address' => $position->address, 'symbol' => $position->symbol],
                'quoteToken' => ['address' => 'So11111111111111111111111111111111111111112', 'symbol' => 'SOL'],
                'priceUsd' => '0.00085',
                'marketCap' => 8500,
                'liquidity' => ['usd' => 50000],
                'txns' => ['m5' => ['buys' => 1, 'sells' => 1]],
                'volume' => ['m5' => 1000],
            ]]),
        ]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $position->refresh();
        $wallet->refresh();
        $this->assertSame('closed', $position->status);
        $this->assertCount(1, $position->exit_events);
        $this->assertSame('stop_loss', $position->exit_events[0]['type']);
        $this->assertEqualsWithDelta(4.985, (float) $wallet->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(0, (float) $wallet->invested_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(-0.015, (float) $wallet->realized_pnl_sol, 0.000001);
        $this->assertDatabaseCount('trading_engine_paper_position_links', 0);
        $this->assertDatabaseCount('trading_engine_paper_observations', 0);
        Queue::assertNotPushed(SubmitTradingEnginePaperObservation::class);
    }

    public function test_engine_owned_non_simulatable_observation_stays_unverified_without_submission_or_settlement(): void
    {
        [$position, $opportunity, $wallet] = $this->positionAndOpportunity();
        $this->registeredLink($position, $opportunity);
        config()->set([
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
        ]);
        $walletState = $wallet->only([
            'available_balance_sol',
            'invested_balance_sol',
            'realized_pnl_sol',
        ]);
        Http::fake([
            'api.dexscreener.com/tokens/v1/solana/*' => Http::response([[
                'chainId' => 'solana',
                'dexId' => 'raydium',
                'pairAddress' => 'unverified-paper-lifecycle-pair',
                'baseToken' => ['address' => $position->address, 'symbol' => $position->symbol],
                'quoteToken' => ['address' => 'So11111111111111111111111111111111111111112', 'symbol' => 'SOL'],
                'priceUsd' => null,
                'marketCap' => null,
                'liquidity' => ['usd' => 50000],
                'txns' => ['m5' => ['buys' => 1, 'sells' => 1]],
                'volume' => ['m5' => 1000],
            ]]),
        ]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $position->refresh();
        $this->assertSame('open', $position->status);
        $this->assertSame([], $position->exit_events);
        $this->assertFalse($position->stop_loss_hit);
        $this->assertFalse($position->tp_50_hit);
        $this->assertFalse($position->tp_2x_hit);
        $this->assertFalse($position->trailing_stop_hit);
        $this->assertSame('unverified', data_get($position->meta, 'market_observation.status'));
        $this->assertContains('invalid_valuation', data_get($position->meta, 'market_observation.reasons'));
        $this->assertSame($walletState, $wallet->fresh()->only(array_keys($walletState)));
        $this->assertSame(1, $position->snapshots()->where('snapshot_type', 'unverified')->count());
        $this->assertDatabaseCount('trading_engine_paper_observations', 0);
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 0);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 0);
        Queue::assertNotPushed(SubmitTradingEnginePaperObservation::class);
    }

    public function test_validated_market_cap_only_observation_is_exported_without_inventing_price_or_liquidity(): void
    {
        [$position, $opportunity] = $this->positionAndOpportunity();
        $link = $this->registeredLink($position, $opportunity);
        config()->set([
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
        ]);

        app(TradingEnginePaperLifecycleIntegration::class)->submitValidated($position, $link, [
            'market_cap' => 12000,
            'price_usd' => null,
            'liquidity_usd' => null,
            'checked_at' => now()->toISOString(),
            'provider' => 'dexscreener',
            'simulation_allowed' => true,
        ]);

        $observation = TradingEnginePaperObservation::query()->sole();
        $this->assertSame('12000', data_get($observation->payload, 'market.market_cap_usd'));
        $this->assertArrayNotHasKey('price_usd', $observation->payload['market']);
        $this->assertArrayNotHasKey('liquidity_usd', $observation->payload['market']);
        Queue::assertPushed(SubmitTradingEnginePaperObservation::class, 1);
    }

    public function test_authoritative_exit_settles_once_and_credits_only_the_correlated_wallet(): void
    {
        [$position, $opportunity, $wallet] = $this->positionAndOpportunity();
        $other = User::factory()->create();
        $otherWallet = PaperWallet::query()->create([
            'user_id' => $other->id,
            'name' => 'default',
            'chain' => Chain::Solana,
            'currency' => 'SOL',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 5,
            'invested_balance_sol' => 0,
            'realized_pnl_sol' => 0,
        ]);
        $link = $this->registeredLink($position, $opportunity);
        $observationPayload = $this->observationPayload($link, $position);
        $observation = TradingEnginePaperObservation::query()->create([
            'position_link_id' => $link->getKey(),
            'paper_position_id' => $position->getKey(),
            'observation_id' => 'paper-position-'.$position->getKey().'-observation-1',
            'observation_sequence' => 1,
            'payload' => $observationPayload,
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($observationPayload),
            'idempotency_key' => 'paper:position:observe:laravel:'.$position->getKey().':1:v1',
            'status' => 'submitted',
        ]);
        $evaluatedPayload = $this->evaluatedPayload($link, $position, $observation);
        $evaluated = $this->event('paper.position.evaluated.v1', $link, $evaluatedPayload);
        $exitPayload = [
            ...$evaluatedPayload,
            'evaluated_event_id' => $evaluated->event_id,
            'result_sha256' => $evaluated->payload_sha256,
        ];
        $exit = $this->event('paper.exit.requested.v1', $link, $exitPayload, $evaluated->event_id);

        $projector = app(TradingEngineOpportunityProjector::class);
        $projector->project($evaluated->event_id);
        $projector->project($exit->event_id);
        $projector->project($exit->event_id);

        $position->refresh();
        $wallet->refresh();

        $this->assertSame('closed', $position->status);
        $this->assertEqualsWithDelta(4.985, (float) $wallet->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(0, (float) $wallet->invested_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(-0.015, (float) $wallet->realized_pnl_sol, 0.000001);
        $this->assertCount(1, $position->exit_events);
        $this->assertEqualsWithDelta(5, (float) $otherWallet->fresh()->available_balance_sol, 0.000001);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 1);
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 1);
        $this->assertSame($exit->event_id, TradingEnginePaperExitSettlement::query()->sole()->exit_event_id);
    }

    public function test_reversed_exit_delivery_converges_after_the_causal_decision_is_projected(): void
    {
        [$position, $opportunity, $wallet] = $this->positionAndOpportunity();
        $link = $this->registeredLink($position, $opportunity);
        $observationPayload = $this->observationPayload($link, $position);
        $observation = $this->storedObservation($link, $position, $observationPayload);
        $evaluatedPayload = $this->evaluatedPayload($link, $position, $observation);
        $evaluated = $this->event('paper.position.evaluated.v1', $link, $evaluatedPayload);
        $exit = $this->event('paper.exit.requested.v1', $link, [
            ...$evaluatedPayload,
            'evaluated_event_id' => $evaluated->event_id,
            'result_sha256' => $evaluated->payload_sha256,
        ], $evaluated->event_id);
        $projector = app(TradingEngineOpportunityProjector::class);

        try {
            $projector->project($exit->event_id);
            $this->fail('The exit must wait for its causal lifecycle decision.');
        } catch (TradingEngineProjectionException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('PAPER_LIFECYCLE_DECISION_PENDING', $exception->errorCode);
        }

        $projector->project($evaluated->event_id);
        $result = $projector->project($exit->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $result['status']);
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertEqualsWithDelta(4.985, (float) $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 1);
    }

    public function test_cross_user_lifecycle_event_fails_closed_without_settlement(): void
    {
        [$position, $opportunity, $wallet] = $this->positionAndOpportunity();
        $link = $this->registeredLink($position, $opportunity);
        $observationPayload = $this->observationPayload($link, $position);
        $observation = TradingEnginePaperObservation::query()->create([
            'position_link_id' => $link->getKey(),
            'paper_position_id' => $position->getKey(),
            'observation_id' => 'paper-position-'.$position->getKey().'-observation-1',
            'observation_sequence' => 1,
            'payload' => $observationPayload,
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($observationPayload),
            'idempotency_key' => 'paper:position:observe:laravel:'.$position->getKey().':1:v1',
            'status' => 'submitted',
        ]);
        $payload = $this->evaluatedPayload($link, $position, $observation);
        $payload['subject']['control_plane_user_id'] = '999999';
        $event = $this->event('paper.position.evaluated.v1', $link, $payload);

        $result = app(TradingEngineOpportunityProjector::class)->project($event->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame('open', $position->fresh()->status);
        $this->assertEqualsWithDelta(4.9, (float) $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 0);
    }

    public function test_authenticated_lifecycle_webhook_is_accepted_into_the_durable_inbox(): void
    {
        [$position, $opportunity] = $this->positionAndOpportunity();
        $link = $this->registeredLink($position, $opportunity);
        $secret = 'test-only-paper-lifecycle-webhook-secret';
        $payload = [
            'operation_id' => (string) Str::ulid(),
            'position_id' => $link->engine_position_id,
            'policy' => ['key' => 'laravel-paper-protection', 'version' => 1],
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-laravel',
                'paper_position_id' => (string) $position->getKey(),
                'trade_opportunity_id' => (string) $opportunity->getKey(),
                'engine_opportunity_id' => $link->engine_opportunity_id,
            ],
            'subject' => ['control_plane_user_id' => (string) $position->user_id],
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'asset' => ['address' => $position->address, 'symbol' => $position->symbol],
            'entry' => [
                'initial_investment_native' => '0.1',
                'market_cap_usd' => '10000',
                'price_usd' => '0.001',
                'liquidity_usd' => '1000',
                'entered_at' => $position->entry_at->toISOString(),
            ],
            'strategy' => [
                'stop_loss_percent' => '10',
                'protection_level_1_percent' => '100',
                'protection_level_2_percent' => '200',
            ],
        ];
        $envelope = [
            'event_id' => (string) Str::ulid(),
            'event_type' => 'paper.position.recorded.v1',
            'schema_version' => 1,
            'occurred_at' => now()->toISOString(),
            'producer' => 'trading-engine',
            'aggregate_type' => 'paper_position',
            'aggregate_id' => $link->engine_position_id,
            'aggregate_version' => $payload['lifecycle_version'] ?? 1,
            'correlation_id' => 'paper-lifecycle-webhook',
            'causation_id' => $payload['operation_id'],
            'idempotency_key' => $link->registration_idempotency_key,
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'payload' => $payload,
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($payload),
        ];
        $rawBody = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.POST./internal/trading-engine/events.'.$rawBody,
            $secret,
        );
        config()->set([
            'services.trading_engine.webhook_secret' => $secret,
            'services.trading_engine.webhook_timestamp_tolerance_seconds' => 60,
            'services.trading_engine.webhook_body_max_bytes' => 262144,
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
        ]);

        $this->call('POST', '/internal/trading-engine/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENGINE_TIMESTAMP' => $timestamp,
            'HTTP_X_ENGINE_SIGNATURE' => 'v1='.$signature,
            'HTTP_X_ENGINE_EVENT_ID' => $envelope['event_id'],
            'HTTP_X_CORRELATION_ID' => $envelope['correlation_id'],
            'HTTP_TRACEPARENT' => $envelope['traceparent'],
        ], $rawBody)->assertAccepted();

        $this->assertDatabaseHas('trading_engine_event_inbox', [
            'event_id' => $envelope['event_id'],
            'event_type' => 'paper.position.recorded.v1',
            'handling_status' => TradingEngineEvent::STATUS_STORED,
        ]);
    }

    public function test_stale_or_out_of_order_lifecycle_version_fails_closed(): void
    {
        [$position, $opportunity] = $this->positionAndOpportunity();
        $link = $this->registeredLink($position, $opportunity);
        $observationPayload = $this->observationPayload($link, $position);
        $observation = $this->storedObservation($link, $position, $observationPayload);
        $payload = $this->evaluatedPayload($link, $position, $observation);
        $payload['lifecycle_version'] = 2;
        $event = $this->event('paper.position.evaluated.v1', $link, $payload);

        $result = app(TradingEngineOpportunityProjector::class)->project($event->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame('PAPER_LIFECYCLE_VERSION_MISMATCH', $event->fresh()->handling_error_code);
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 0);
        $this->assertSame('open', $position->fresh()->status);
    }

    public function test_cross_chain_lifecycle_event_fails_closed(): void
    {
        [$position, $opportunity] = $this->positionAndOpportunity();
        $link = $this->registeredLink($position, $opportunity);
        $observationPayload = $this->observationPayload($link, $position);
        $observation = $this->storedObservation($link, $position, $observationPayload);
        $payload = $this->evaluatedPayload($link, $position, $observation);
        $payload['network']['id'] = 'eip155:1';
        $event = $this->event('paper.position.evaluated.v1', $link, $payload);

        $result = app(TradingEngineOpportunityProjector::class)->project($event->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame('PAPER_LIFECYCLE_CORRELATION_MISMATCH', $event->fresh()->handling_error_code);
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 0);
    }

    public function test_exit_payload_cannot_change_the_authoritative_evaluated_fill(): void
    {
        [$position, $opportunity, $wallet] = $this->positionAndOpportunity();
        $link = $this->registeredLink($position, $opportunity);
        $observationPayload = $this->observationPayload($link, $position);
        $observation = $this->storedObservation($link, $position, $observationPayload);
        $evaluatedPayload = $this->evaluatedPayload($link, $position, $observation);
        $evaluated = $this->event('paper.position.evaluated.v1', $link, $evaluatedPayload);
        app(TradingEngineOpportunityProjector::class)->project($evaluated->event_id);
        $exitPayload = [
            ...$evaluatedPayload,
            'observed_multiple' => '0.99',
            'evaluated_event_id' => $evaluated->event_id,
            'result_sha256' => $evaluated->payload_sha256,
        ];
        $exit = $this->event('paper.exit.requested.v1', $link, $exitPayload, $evaluated->event_id);

        $result = app(TradingEngineOpportunityProjector::class)->project($exit->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame('PAPER_EXIT_DECISION_MISMATCH', $exit->fresh()->handling_error_code);
        $this->assertSame('open', $position->fresh()->status);
        $this->assertEqualsWithDelta(4.9, (float) $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 0);
    }

    /** @return array{PaperPosition, TradeOpportunity, PaperWallet} */
    private function positionAndOpportunity(): array
    {
        $user = User::factory()->create();
        $opportunity = TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'address' => 'So11111111111111111111111111111111111111112',
            'scanner' => 'new-token',
        ]);
        $engineOpportunityId = (string) Str::ulid();
        $recordedEventId = (string) Str::ulid();
        TradingEngineEvent::query()->create([
            'event_id' => $recordedEventId,
            'event_type' => 'opportunity.recorded.v1',
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => 'opportunity',
            'aggregate_id' => $engineOpportunityId,
            'aggregate_version' => 1,
            'correlation_id' => 'paper-lifecycle-fixture',
            'causation_id' => (string) Str::ulid(),
            'idempotency_key' => 'paper-lifecycle-fixture-'.$opportunity->getKey(),
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'payload_sha256' => str_repeat('c', 64),
            'raw_body_sha256' => str_repeat('d', 64),
            'event_envelope' => [],
            'payload' => [],
            'handling_status' => TradingEngineEvent::STATUS_PROJECTED,
            'handling_attempts' => 1,
            'received_at' => now(),
            'handled_at' => now(),
        ]);
        TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => $engineOpportunityId,
            'recorded_event_id' => $recordedEventId,
            'user_id' => $user->id,
            'discovery_key' => hash('sha256', 'lifecycle-'.$opportunity->getKey()),
            'scanner' => 'new-token',
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
            'recorded_at' => now(),
            'linked_at' => now(),
        ]);
        $wallet = PaperWallet::query()->create([
            'user_id' => $user->id,
            'name' => 'default',
            'chain' => Chain::Solana,
            'currency' => 'SOL',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 4.9,
            'invested_balance_sol' => 0.1,
            'realized_pnl_sol' => 0,
        ]);
        $position = PaperPosition::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Solana,
            'address' => $opportunity->address,
            'symbol' => 'CANARY',
            'entry_market_cap' => 10000,
            'entry_price' => 0.001,
            'entry_liquidity' => 1000,
            'strategy_snapshot' => [
                'stop_loss_percent' => 10,
                'protection_level_1_percent' => 100,
                'protection_level_2_percent' => 200,
            ],
            'status' => 'open',
            'entry_at' => now(),
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
            'realized_value_multiple' => 0,
            'strategy_value_multiple' => 1,
            'strategy_return_percent' => 0,
            'exit_events' => [],
        ]);

        return [$position, $opportunity, $wallet];
    }

    private function registeredLink(PaperPosition $position, TradeOpportunity $opportunity): TradingEnginePaperPositionLink
    {
        return TradingEnginePaperPositionLink::query()->create([
            'paper_position_id' => $position->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'user_id' => $position->user_id,
            'engine_opportunity_id' => TradingEngineOpportunityLink::query()->where('trade_opportunity_id', $opportunity->getKey())->value('engine_opportunity_id'),
            'engine_position_id' => (string) Str::ulid(),
            'chain' => Chain::Solana->value,
            'asset_address' => $position->address,
            'ownership_state' => 'registered',
            'next_observation_sequence' => 2,
            'ownership_snapshot' => ['owner' => 'trading-engine', 'authoritative' => true],
            'registration_payload' => ['immutable' => true],
            'registration_payload_sha256' => str_repeat('b', 64),
            'registration_idempotency_key' => 'paper:position:record:laravel:'.$position->getKey().':v1',
            'registered_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function storedObservation(
        TradingEnginePaperPositionLink $link,
        PaperPosition $position,
        array $payload,
    ): TradingEnginePaperObservation {
        return TradingEnginePaperObservation::query()->create([
            'position_link_id' => $link->getKey(),
            'paper_position_id' => $position->getKey(),
            'observation_id' => $payload['source']['observation_id'],
            'observation_sequence' => $payload['source']['sequence'],
            'payload' => $payload,
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($payload),
            'idempotency_key' => 'paper:position:observe:laravel:'.$position->getKey().':1:v1',
            'status' => 'submitted',
        ]);
    }

    /** @return array<string, mixed> */
    private function observationPayload(
        TradingEnginePaperPositionLink $link,
        PaperPosition $position,
    ): array {
        $timestamp = now()->toISOString();

        return [
            'schema_version' => 1,
            'position_id' => $link->engine_position_id,
            'source' => [
                'paper_position_id' => (string) $position->getKey(),
                'observation_id' => 'paper-position-'.$position->getKey().'-observation-1',
                'sequence' => 1,
            ],
            'subject' => ['control_plane_user_id' => (string) $position->user_id],
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'asset' => ['address' => $position->address],
            'market' => [
                'market_cap_usd' => '8500',
                'price_usd' => '0.00085',
                'liquidity_usd' => '900',
                'observed_at' => $timestamp,
                'fetched_at' => $timestamp,
                'provider' => 'dexscreener',
            ],
            'validation' => [
                'status' => 'eligible',
                'identity_verified' => true,
                'simulation_allowed' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function evaluatedPayload(
        TradingEnginePaperPositionLink $link,
        PaperPosition $position,
        TradingEnginePaperObservation $observation,
    ): array {
        $observationPayload = $observation->payload;

        return [
            'position_id' => $link->engine_position_id,
            'decision_id' => (string) Str::ulid(),
            'source' => $observationPayload['source'],
            'subject' => $observationPayload['subject'],
            'network' => $observationPayload['network'],
            'asset' => $observationPayload['asset'],
            'policy' => ['key' => 'laravel-paper-protection', 'version' => 1],
            'lifecycle_version' => 1,
            'decision' => 'EXIT',
            'exit_type' => 'stop_loss',
            'observed_multiple' => '0.85',
            'trigger_multiple' => '0.9',
            'market' => $observationPayload['market'],
            'peak_market_cap_usd' => '10000',
            'peak_multiple' => '1',
            'drawdown_percent' => '-15',
            'protection_before' => 'none',
            'protection_after' => 'none',
            'transitions' => [],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function event(
        string $type,
        TradingEnginePaperPositionLink $link,
        array $payload,
        ?string $causationId = null,
    ): TradingEngineEvent {
        $eventId = (string) Str::ulid();
        $hash = app(TradingEngineCanonicalJson::class)->hash($payload);

        return TradingEngineEvent::query()->create([
            'event_id' => $eventId,
            'event_type' => $type,
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => 'paper_position',
            'aggregate_id' => $link->engine_position_id,
            'aggregate_version' => 1,
            'correlation_id' => 'paper-lifecycle-test',
            'causation_id' => $causationId ?? (string) Str::ulid(),
            'idempotency_key' => $type === 'paper.exit.requested.v1'
                ? 'paper:exit:'.$link->engine_position_id.':1'
                : 'paper:position:observe:laravel:'.$link->paper_position_id.':1:v1',
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'payload_sha256' => $hash,
            'raw_body_sha256' => hash('sha256', $eventId),
            'event_envelope' => [],
            'payload' => $payload,
            'handling_status' => TradingEngineEvent::STATUS_STORED,
            'handling_attempts' => 0,
            'received_at' => now(),
        ]);
    }
}
