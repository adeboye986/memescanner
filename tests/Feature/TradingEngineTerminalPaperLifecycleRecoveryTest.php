<?php

namespace Tests\Feature;

use App\Chain;
use App\Jobs\ProjectTradingEngineEvent;
use App\Models\PaperPosition;
use App\Models\PaperPositionSnapshot;
use App\Models\PaperWallet;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEnginePaperLifecycleDecision;
use App\Models\TradingEnginePaperObservation;
use App\Models\TradingEnginePaperPositionLink;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TradingEngineTerminalPaperLifecycleRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 12:00:00');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
            'services.trading_engine.paper_lifecycle_canary_user_ids' => '',
            'services.trading_engine.projection_recovery_lease_seconds' => 120,
            'services.trading_engine.paper_decision_integration_enabled' => false,
            'services.trading_engine.live_decision_integration_enabled' => false,
            'services.trading_engine.live_preparation_integration_enabled' => false,
            'services.trading_engine.solana_live_integration_enabled' => false,
        ]);
    }

    public function test_recovery_requires_gates_and_general_retry_remains_prohibited(): void
    {
        foreach ([
            'services.trading_engine.enabled',
            'services.trading_engine.paper_lifecycle_integration_enabled',
            'services.trading_engine.paper_lifecycle_authoritative_enabled',
        ] as $gate) {
            $event = $this->failedRecorded($this->fixture());
            config()->set($gate, false);

            $this->artisan($this->command(), $this->arguments($event))
                ->expectsOutput('Trading engine authoritative PAPER lifecycle recovery is disabled.')
                ->assertFailed();
            $this->assertSame(TradingEngineEvent::STATUS_FAILED, $event->fresh()->handling_status);
            config()->set($gate, true);
        }

        $event = $this->failedRecorded($this->fixture());
        $this->artisan('trading-engine:retry-projection', ['event-id' => $event->event_id])
            ->expectsOutput('The event has a terminal integrity failure and cannot be retried.')
            ->assertFailed();
    }

    public function test_recovery_requires_exact_error_supported_type_and_failed_status(): void
    {
        $event = $this->failedRecorded($this->fixture());
        $this->artisan($this->command(), ['event-id' => $event->event_id])
            ->expectsOutput('The --expected-error option is required.')
            ->assertFailed();
        $this->artisan($this->command(), $this->arguments($event, 'PAPER_LIFECYCLE_CORRELATION_MISMATCH'))
            ->expectsOutput('The supplied expected error does not match the stored terminal error.')
            ->assertFailed();

        $type = $this->failedRecorded($this->fixture());
        $type->forceFill(['event_type' => 'opportunity.recorded.v1'])->save();
        $this->artisan($this->command(), $this->arguments($type))
            ->expectsOutput('The event type is not eligible for terminal PAPER lifecycle recovery.')
            ->assertFailed();

        $error = $this->failedRecorded($this->fixture());
        $error->forceFill(['handling_error_code' => 'PAPER_LIFECYCLE_VERSION_MISMATCH'])->save();
        $this->artisan($this->command(), $this->arguments($error, 'PAPER_LIFECYCLE_VERSION_MISMATCH'))
            ->expectsOutput('The stored terminal error is not eligible for this recovery path.')
            ->assertFailed();

        $status = $this->failedRecorded($this->fixture());
        $status->forceFill(['handling_status' => TradingEngineEvent::STATUS_RETRYABLE])->save();
        $this->artisan($this->command(), $this->arguments($status))
            ->expectsOutput('The event is not in the terminal failed state.')
            ->assertFailed();
    }

    public function test_recovery_rejects_canary_user_and_identity_failures(): void
    {
        $nonCanary = $this->fixture();
        $event = $this->failedRecorded($nonCanary);
        config()->set('services.trading_engine.paper_lifecycle_canary_user_ids', '999999');
        $this->artisan($this->command(), $this->arguments($event))
            ->expectsOutput('The lifecycle event does not belong to a configured canary user.')
            ->assertFailed();

        $cross = $this->fixture();
        $payload = $this->recordedPayload($cross);
        $other = (string) User::factory()->create()->getKey();
        $payload['subject']['control_plane_user_id'] = $other;
        config()->set('services.trading_engine.paper_lifecycle_canary_user_ids', $other);
        $event = $this->failedEvent('paper.position.recorded.v1', $cross, $payload, 'PAPER_POSITION_LINK_IDENTITY_MISMATCH');
        $this->artisan($this->command(), $this->arguments($event))
            ->expectsOutput('The lifecycle event failed recovery identity checks.')
            ->assertFailed();

        $mismatch = $this->fixture();
        $payload = $this->recordedPayload($mismatch);
        $payload['position_id'] = (string) Str::ulid();
        $event = $this->failedEvent('paper.position.recorded.v1', $mismatch, $payload, 'PAPER_POSITION_LINK_IDENTITY_MISMATCH');
        $this->artisan($this->command(), $this->arguments($event))
            ->expectsOutput('The lifecycle event failed recovery identity checks.')
            ->assertFailed();
    }

    public function test_recovery_requires_position_link_and_observation(): void
    {
        $fixture = $this->fixture();
        $event = $this->failedRecorded($fixture);
        $fixture['link']->delete();
        $fixture['position']->delete();
        $this->artisan($this->command(), $this->arguments($event))
            ->expectsOutput('The correlated PAPER position was not found.')
            ->assertFailed();

        $fixture = $this->fixture();
        $event = $this->failedRecorded($fixture);
        $fixture['link']->delete();
        $this->artisan($this->command(), $this->arguments($event))
            ->expectsOutput('The correlated PAPER lifecycle link was not found.')
            ->assertFailed();

        $fixture = $this->fixture();
        $observation = $this->observation($fixture);
        $event = $this->failedEvaluated($fixture, $observation);
        $observation->delete();
        $this->artisan($this->command(), $this->arguments($event, 'PAPER_LIFECYCLE_CORRELATION_MISMATCH'))
            ->expectsOutput('The correlated PAPER observation was not found.')
            ->assertFailed();
    }

    public function test_recovery_revalidates_registration_and_observation_hashes(): void
    {
        $fixture = $this->fixture();
        $event = $this->failedRecorded($fixture);
        $fixture['link']->forceFill(['registration_payload_sha256' => str_repeat('0', 64)])->save();
        $this->artisan($this->command(), $this->arguments($event))
            ->expectsOutput('The stored registration snapshot failed its canonical hash check.')
            ->assertFailed();

        $fixture = $this->fixture();
        $observation = $this->observation($fixture);
        $event = $this->failedEvaluated($fixture, $observation);
        $observation->forceFill(['payload_sha256' => str_repeat('0', 64)])->save();
        $this->artisan($this->command(), $this->arguments($event, 'PAPER_LIFECYCLE_CORRELATION_MISMATCH'))
            ->expectsOutput('The stored PAPER observation failed its canonical hash check.')
            ->assertFailed();
    }

    public function test_preparation_is_immutable_and_duplicate_attempt_dispatches_once(): void
    {
        Queue::fake();
        $fixture = $this->fixture();
        $event = $this->failedRecorded($fixture);
        $immutable = $event->only(['event_id', 'event_type', 'aggregate_id', 'aggregate_version', 'correlation_id', 'causation_id', 'idempotency_key', 'payload_sha256', 'raw_body_sha256', 'event_envelope', 'payload']);
        $fixture['position']->refresh();
        $fixture['wallet']->refresh();
        $position = $fixture['position']->getAttributes();
        $wallet = $fixture['wallet']->getAttributes();

        $this->artisan($this->command(), $this->arguments($event))
            ->expectsTable(['Field', 'Value'], [
                ['Inbox ID', (string) $event->getKey()],
                ['Event type', 'paper.position.recorded.v1'],
                ['PAPER position ID', (string) $fixture['position']->getKey()],
                ['User ID', (string) $fixture['user']->getKey()],
                ['Previous terminal error', 'PAPER_POSITION_LINK_IDENTITY_MISMATCH'],
                ['Recovery status', 'dispatched'],
            ])->assertSuccessful();
        $this->artisan($this->command(), $this->arguments($event))->assertFailed();

        Queue::assertPushed(ProjectTradingEngineEvent::class, 1);
        $event->refresh();
        $this->assertSame(TradingEngineEvent::STATUS_RETRYABLE, $event->handling_status);
        $this->assertTrue($event->next_handling_at->isAfter(now()));
        $this->assertSame($immutable, $event->only(array_keys($immutable)));
        $this->assertEquals($position, $fixture['position']->fresh()->getAttributes());
        $this->assertEquals($wallet, $fixture['wallet']->fresh()->getAttributes());
    }

    public function test_reordered_recorded_event_recovers_but_real_change_fails_closed(): void
    {
        $fixture = $this->fixture();
        $event = $this->failedEvent('paper.position.recorded.v1', $fixture, $this->reorder($this->recordedPayload($fixture)), 'PAPER_POSITION_LINK_IDENTITY_MISMATCH');
        $this->artisan($this->command(), $this->arguments($event))->assertSuccessful();
        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $event->fresh()->handling_status);

        $fixture = $this->fixture();
        $payload = $this->reorder($this->recordedPayload($fixture));
        $payload['strategy']['stop_loss_percent'] = '11';
        $event = $this->failedEvent('paper.position.recorded.v1', $fixture, $payload, 'PAPER_POSITION_LINK_IDENTITY_MISMATCH');
        $this->artisan($this->command(), $this->arguments($event))->assertSuccessful();
        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $event->fresh()->handling_status);
        $this->assertSame('PAPER_POSITION_LINK_IDENTITY_MISMATCH', $event->fresh()->handling_error_code);
    }

    public function test_reordered_level_one_hold_recovers_once_without_settlement_or_wallet_change(): void
    {
        $fixture = $this->fixture();
        $observation = $this->observation($fixture);
        $event = $this->failedEvent('paper.position.evaluated.v1', $fixture, $this->reorder($this->evaluatedPayload($fixture, $observation)), 'PAPER_LIFECYCLE_CORRELATION_MISMATCH');
        $wallet = $fixture['wallet']->only(['available_balance_sol', 'invested_balance_sol', 'realized_pnl_sol']);

        $this->artisan($this->command(), $this->arguments($event, 'PAPER_LIFECYCLE_CORRELATION_MISMATCH'))->assertSuccessful();

        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $event->fresh()->handling_status);
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 1);
        $this->assertDatabaseCount('paper_position_snapshots', 1);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 0);
        $this->assertSame('evaluated', $observation->fresh()->status);
        $this->assertSame('open', $fixture['position']->fresh()->status);
        $this->assertTrue($fixture['position']->fresh()->tp_50_hit);
        $this->assertFalse($fixture['position']->fresh()->tp_2x_hit);
        $this->assertSame('level_1', TradingEnginePaperLifecycleDecision::query()->sole()->protection_after);
        $this->assertSame('engine_lifecycle', PaperPositionSnapshot::query()->sole()->snapshot_type);
        $this->assertSame($wallet, $fixture['wallet']->fresh()->only(array_keys($wallet)));

        $this->artisan($this->command(), $this->arguments($event, 'PAPER_LIFECYCLE_CORRELATION_MISMATCH'))->assertFailed();
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 1);
        $this->assertDatabaseCount('paper_position_snapshots', 1);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 0);
        $this->assertSame($wallet, $fixture['wallet']->fresh()->only(array_keys($wallet)));
    }

    public function test_changed_evaluated_observation_fails_closed_without_side_effects(): void
    {
        $fixture = $this->fixture();
        $observation = $this->observation($fixture);
        $payload = $this->evaluatedPayload($fixture, $observation);
        $payload['market']['market_cap_usd'] = '21001';
        $event = $this->failedEvent('paper.position.evaluated.v1', $fixture, $payload, 'PAPER_LIFECYCLE_CORRELATION_MISMATCH');
        $wallet = $fixture['wallet']->only(['available_balance_sol', 'invested_balance_sol', 'realized_pnl_sol']);

        $this->artisan($this->command(), $this->arguments($event, 'PAPER_LIFECYCLE_CORRELATION_MISMATCH'))->assertSuccessful();

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $event->fresh()->handling_status);
        $this->assertSame('PAPER_LIFECYCLE_CORRELATION_MISMATCH', $event->fresh()->handling_error_code);
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 0);
        $this->assertDatabaseCount('paper_position_snapshots', 0);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlements', 0);
        $this->assertSame('submitted', $observation->fresh()->status);
        $this->assertSame('open', $fixture['position']->fresh()->status);
        $this->assertSame($wallet, $fixture['wallet']->fresh()->only(array_keys($wallet)));
    }

    public function test_dispatch_failure_leaves_safe_retryable_state_without_leaking_error(): void
    {
        $event = $this->failedRecorded($this->fixture());
        $this->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('private-dispatch-secret'));
        }));

        $this->artisan($this->command(), $this->arguments($event))
            ->doesntExpectOutputToContain('private-dispatch-secret')
            ->expectsOutput('The projection job could not be dispatched and remains recoverable.')
            ->assertFailed();

        $event->refresh();
        $this->assertSame(TradingEngineEvent::STATUS_RETRYABLE, $event->handling_status);
        $this->assertSame('PROJECTION_DISPATCH_FAILED', $event->handling_error_code);
        $this->assertTrue($event->next_handling_at->isAfter(now()));
    }

    /** @return array{user: User, opportunity: TradeOpportunity, position: PaperPosition, wallet: PaperWallet, link: TradingEnginePaperPositionLink} */
    private function fixture(): array
    {
        $user = User::factory()->create();
        config()->set('services.trading_engine.paper_lifecycle_canary_user_ids', (string) $user->getKey());
        $opportunity = TradeOpportunity::factory()->for($user)->create(['chain' => Chain::Solana, 'address' => 'So'.str_pad((string) $user->getKey(), 42, '1'), 'scanner' => 'new-token']);
        $wallet = PaperWallet::query()->create(['user_id' => $user->getKey(), 'name' => 'default', 'chain' => Chain::Solana, 'currency' => 'SOL', 'starting_balance_sol' => 5, 'available_balance_sol' => 4.9, 'invested_balance_sol' => 0.1, 'realized_pnl_sol' => 0]);
        $position = PaperPosition::query()->create(['user_id' => $user->getKey(), 'chain' => Chain::Solana, 'address' => $opportunity->address, 'symbol' => 'CANARY', 'entry_market_cap' => 10000, 'entry_price' => 0.001, 'entry_liquidity' => 1000, 'strategy_snapshot' => ['stop_loss_percent' => 10, 'protection_level_1_percent' => 100, 'protection_level_2_percent' => 200], 'status' => 'open', 'entry_at' => now(), 'initial_investment_sol' => 0.1, 'remaining_investment_sol' => 0.1, 'remaining_fraction' => 1, 'realized_value_multiple' => 0, 'strategy_value_multiple' => 1, 'strategy_return_percent' => 0, 'exit_events' => []]);
        $engineOpportunityId = (string) Str::ulid();
        $registration = $this->registration($user, $opportunity, $position, $engineOpportunityId);
        $link = TradingEnginePaperPositionLink::query()->create(['paper_position_id' => $position->getKey(), 'trade_opportunity_id' => $opportunity->getKey(), 'user_id' => $user->getKey(), 'engine_opportunity_id' => $engineOpportunityId, 'engine_position_id' => (string) Str::ulid(), 'chain' => Chain::Solana->value, 'asset_address' => $position->address, 'ownership_state' => 'registered', 'next_observation_sequence' => 2, 'ownership_snapshot' => ['owner' => 'trading-engine', 'authoritative' => true], 'registration_payload' => $registration, 'registration_payload_sha256' => $this->canonical()->hash($registration), 'registration_idempotency_key' => 'paper:position:record:laravel:'.$position->getKey().':v1', 'registered_at' => now()]);

        return compact('user', 'opportunity', 'position', 'wallet', 'link');
    }

    /** @return array<string, mixed> */
    private function registration(User $user, TradeOpportunity $opportunity, PaperPosition $position, string $engineOpportunityId): array
    {
        return ['schema_version' => 1, 'source' => ['system' => 'meme-scanner-laravel', 'paper_position_id' => (string) $position->getKey(), 'trade_opportunity_id' => (string) $opportunity->getKey(), 'engine_opportunity_id' => $engineOpportunityId], 'subject' => ['control_plane_user_id' => (string) $user->getKey()], 'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'], 'asset' => ['address' => $position->address, 'symbol' => $position->symbol], 'entry' => ['initial_investment_native' => '0.1', 'market_cap_usd' => '10000', 'price_usd' => '0.001', 'liquidity_usd' => '1000', 'entered_at' => $position->entry_at->toISOString()], 'strategy' => ['stop_loss_percent' => '10', 'protection_level_1_percent' => '100', 'protection_level_2_percent' => '200']];
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function recordedPayload(array $fixture): array
    {
        return ['operation_id' => (string) Str::ulid(), 'position_id' => $fixture['link']->engine_position_id, 'policy' => ['key' => 'laravel-paper-protection', 'version' => 1], ...$fixture['link']->registration_payload];
    }

    /** @param array<string, mixed> $fixture */
    private function observation(array $fixture): TradingEnginePaperObservation
    {
        $timestamp = now()->toISOString();
        $payload = ['schema_version' => 1, 'position_id' => $fixture['link']->engine_position_id, 'source' => ['paper_position_id' => (string) $fixture['position']->getKey(), 'observation_id' => 'paper-position-'.$fixture['position']->getKey().'-observation-1', 'sequence' => 1], 'subject' => ['control_plane_user_id' => (string) $fixture['user']->getKey()], 'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'], 'asset' => ['address' => $fixture['position']->address], 'market' => ['market_cap_usd' => '21000', 'price_usd' => '0.0021', 'liquidity_usd' => '1500', 'observed_at' => $timestamp, 'fetched_at' => $timestamp, 'provider' => 'dexscreener'], 'validation' => ['status' => 'eligible', 'identity_verified' => true, 'simulation_allowed' => true]];

        return TradingEnginePaperObservation::query()->create(['position_link_id' => $fixture['link']->getKey(), 'paper_position_id' => $fixture['position']->getKey(), 'observation_id' => $payload['source']['observation_id'], 'observation_sequence' => 1, 'payload' => $payload, 'payload_sha256' => $this->canonical()->hash($payload), 'idempotency_key' => 'paper:position:observe:laravel:'.$fixture['position']->getKey().':1:v1', 'status' => 'submitted', 'submitted_at' => now()]);
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function evaluatedPayload(array $fixture, TradingEnginePaperObservation $observation): array
    {
        $stored = $observation->payload;

        return ['position_id' => $fixture['link']->engine_position_id, 'decision_id' => (string) Str::ulid(), 'source' => $stored['source'], 'subject' => $stored['subject'], 'network' => $stored['network'], 'asset' => $stored['asset'], 'policy' => ['key' => 'laravel-paper-protection', 'version' => 1], 'lifecycle_version' => 1, 'decision' => 'HOLD', 'exit_type' => null, 'observed_multiple' => '2.1', 'trigger_multiple' => null, 'market' => $stored['market'], 'peak_market_cap_usd' => '21000', 'peak_multiple' => '2.1', 'drawdown_percent' => '0', 'protection_before' => 'none', 'protection_after' => 'level_1', 'transitions' => [['type' => 'protection_armed', 'level' => 'level_1']]];
    }

    /** @param array<string, mixed> $fixture */
    private function failedRecorded(array $fixture): TradingEngineEvent
    {
        return $this->failedEvent('paper.position.recorded.v1', $fixture, $this->recordedPayload($fixture), 'PAPER_POSITION_LINK_IDENTITY_MISMATCH');
    }

    /** @param array<string, mixed> $fixture */
    private function failedEvaluated(array $fixture, TradingEnginePaperObservation $observation): TradingEngineEvent
    {
        return $this->failedEvent('paper.position.evaluated.v1', $fixture, $this->evaluatedPayload($fixture, $observation), 'PAPER_LIFECYCLE_CORRELATION_MISMATCH');
    }

    /** @param array<string, mixed> $fixture
     * @param  array<string, mixed>  $payload
     */
    private function failedEvent(string $type, array $fixture, array $payload, string $error): TradingEngineEvent
    {
        $eventId = (string) Str::ulid();

        if ($type === 'paper.position.evaluated.v1') {
            $fixture['link']->observations()->sole()->forceFill([
                'engine_decision_id' => $payload['decision_id'],
                'engine_decision' => $payload['decision'],
            ])->save();
        }

        return TradingEngineEvent::query()->create(['event_id' => $eventId, 'event_type' => $type, 'schema_version' => 1, 'occurred_at' => now(), 'producer' => 'trading-engine', 'aggregate_type' => 'paper_position', 'aggregate_id' => $payload['position_id'], 'aggregate_version' => 1, 'correlation_id' => 'paper-terminal-recovery-test', 'causation_id' => (string) Str::ulid(), 'idempotency_key' => $type === 'paper.position.recorded.v1' ? $fixture['link']->registration_idempotency_key : $fixture['link']->observations()->sole()->idempotency_key, 'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01', 'payload_sha256' => $this->canonical()->hash($payload), 'raw_body_sha256' => hash('sha256', $eventId), 'event_envelope' => ['event_id' => $eventId, 'payload' => $payload], 'payload' => $payload, 'handling_status' => TradingEngineEvent::STATUS_FAILED, 'handling_attempts' => 1, 'handling_error_code' => $error, 'received_at' => now(), 'handled_at' => now()]);
    }

    /** @return array<string, string> */
    private function arguments(TradingEngineEvent $event, string $error = 'PAPER_POSITION_LINK_IDENTITY_MISMATCH'): array
    {
        return ['event-id' => $event->event_id, '--expected-error' => $error];
    }

    private function command(): string
    {
        return 'trading-engine:recover-terminal-paper-lifecycle';
    }

    private function reorder(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->reorder($item), $value);
        }
        $reordered = [];
        foreach (array_reverse($value, true) as $key => $item) {
            $reordered[$key] = $this->reorder($item);
        }

        return $reordered;
    }

    private function canonical(): TradingEngineCanonicalJson
    {
        return app(TradingEngineCanonicalJson::class);
    }
}
