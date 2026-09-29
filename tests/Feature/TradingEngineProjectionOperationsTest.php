<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Jobs\ProjectTradingEngineEvent;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityLink;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineProjectionRecovery;
use App\Services\TradingEngine\TradingEngineProjectionStatus;
use App\Services\UserTelegramNotificationService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TradingEngineProjectionOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-29 12:00:00');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.projection_recovery_batch_size' => 100,
            'services.trading_engine.projection_recovery_stale_after_seconds' => 300,
            'services.trading_engine.projection_recovery_lease_seconds' => 120,
        ]);
    }

    public function test_status_snapshot_reports_exact_states_recovery_metrics_and_deterministic_oldest_events(): void
    {
        $causal = $this->event(90, TradingEngineEvent::STATUS_PROJECTED, receivedAt: now()->subMinutes(30));
        $this->link($this->opportunity(), $causal);
        $oldestOutstanding = $this->event(1, receivedAt: now()->subMinutes(20));
        $this->event(2, receivedAt: now()->subMinute());
        $this->event(3, TradingEngineEvent::STATUS_PROJECTED, receivedAt: now()->subMinutes(25));
        $oldestRetryable = $this->event(
            4,
            TradingEngineEvent::STATUS_RETRYABLE,
            now()->subMinute(),
            now()->subMinutes(15),
        );
        $this->event(
            5,
            TradingEngineEvent::STATUS_RETRYABLE,
            now()->addMinutes(2),
            now()->subMinutes(14),
        );
        $oldestDeferred = $this->event(
            6,
            TradingEngineEvent::STATUS_DEFERRED,
            now()->subMinute(),
            now()->subMinutes(13),
            'opportunity.evaluated.v1',
            $this->eventId(99),
        );
        $this->event(
            7,
            TradingEngineEvent::STATUS_DEFERRED,
            now()->subMinute(),
            now()->subMinutes(12),
            'opportunity.evaluated.v1',
            $causal->event_id,
        );
        $oldestFailed = $this->event(8, TradingEngineEvent::STATUS_FAILED, receivedAt: now()->subMinutes(11));
        $this->event(9, TradingEngineEvent::STATUS_UNHANDLED, receivedAt: now()->subMinutes(10));
        $this->event(
            10,
            TradingEngineEvent::STATUS_STORED,
            receivedAt: now()->subMinutes(40),
            eventType: 'foundation.noop_accepted.v1',
        );

        $snapshot = app(TradingEngineProjectionStatus::class)->snapshot();

        $this->assertSame(10, $snapshot['inbox']['total_projectable']);
        $this->assertSame([
            TradingEngineEvent::STATUS_STORED => 2,
            TradingEngineEvent::STATUS_PROJECTED => 2,
            TradingEngineEvent::STATUS_RETRYABLE => 2,
            TradingEngineEvent::STATUS_DEFERRED => 2,
            TradingEngineEvent::STATUS_FAILED => 1,
            TradingEngineEvent::STATUS_UNHANDLED => 1,
        ], $snapshot['inbox']['counts']);
        $this->assertSame([
            'stale_stored_eligible' => 1,
            'retryable_due' => 1,
            'deferred_causally_eligible' => 1,
            'active_leases' => 1,
        ], $snapshot['recovery']);
        $this->assertSame($oldestOutstanding->event_id, $snapshot['oldest']['outstanding']['event_id']);
        $this->assertSame(1200, $snapshot['oldest']['outstanding']['age_seconds']);
        $this->assertSame($oldestRetryable->event_id, $snapshot['oldest']['retryable']['event_id']);
        $this->assertSame($oldestDeferred->event_id, $snapshot['oldest']['deferred']['event_id']);
        $this->assertSame($oldestFailed->event_id, $snapshot['oldest']['failed']['event_id']);
    }

    public function test_status_command_is_read_only_and_dispatches_nothing(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $event = $this->event(20, receivedAt: now()->subMinutes(10));
        $before = $event->getAttributes();

        $this->artisan('trading-engine:projection-status')
            ->expectsOutputToContain('Trading engine projection status')
            ->expectsOutputToContain('Stale stored eligible')
            ->assertSuccessful();

        $this->assertEquals($before, $event->fresh()->getAttributes());
        Queue::assertNothingPushed();
    }

    public function test_event_inspection_prints_only_safe_metadata_and_unknown_ids_fail_safely(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $event = $this->event(21, receivedAt: now()->subMinutes(10));
        $event->forceFill([
            'event_envelope' => ['private_key' => 'do-not-print-private-key'],
            'payload' => ['signature' => 'do-not-print-signature'],
            'raw_body_sha256' => str_repeat('9', 64),
        ])->save();
        $before = $event->fresh()->getAttributes();

        $exitCode = Artisan::call('trading-engine:projection-status', ['--event' => $event->event_id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($event->event_id, $output);
        $this->assertStringContainsString('Processing status', $output);
        $this->assertStringNotContainsString('do-not-print-private-key', $output);
        $this->assertStringNotContainsString('do-not-print-signature', $output);
        $this->assertStringNotContainsString(str_repeat('9', 64), $output);
        $this->assertSame($before, $event->fresh()->getAttributes());
        Queue::assertNothingPushed();

        $missingCode = Artisan::call('trading-engine:projection-status', [
            '--event' => '01K9ZZZZZZZZZZZZZZZZZZZZZZ',
        ]);

        $this->assertSame(1, $missingCode);
        $this->assertStringContainsString('was not found', Artisan::output());
    }

    /** @param array{engine: bool, projection: bool} $flags */
    #[DataProvider('disabledFlagCombinations')]
    public function test_manual_retry_requires_engine_and_projection_flags(array $flags): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        config()->set([
            'services.trading_engine.enabled' => $flags['engine'],
            'services.trading_engine.opportunity_projection_enabled' => $flags['projection'],
            'services.trading_engine.opportunity_export_enabled' => true,
        ]);
        $event = $this->event(30);

        $this->artisan('trading-engine:retry-projection', ['event-id' => $event->event_id])
            ->expectsOutputToContain('projection is disabled')
            ->assertFailed();

        $this->assertNull($event->fresh()->next_handling_at);
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{array{engine: bool, projection: bool}}> */
    public static function disabledFlagCombinations(): array
    {
        return [
            'engine disabled' => [['engine' => false, 'projection' => true]],
            'projection disabled' => [['engine' => true, 'projection' => false]],
        ];
    }

    public function test_supported_stored_and_due_retryable_events_dispatch_identity_only_without_export(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $stored = $this->event(31);
        $retryable = $this->event(
            32,
            TradingEngineEvent::STATUS_RETRYABLE,
            now()->subSecond(),
        );

        $this->assertFalse(config('services.trading_engine.opportunity_export_enabled'));
        $this->artisan('trading-engine:retry-projection', ['event-id' => $stored->event_id])
            ->assertSuccessful();
        $this->artisan('trading-engine:retry-projection', ['event-id' => $retryable->event_id])
            ->assertSuccessful();

        Queue::assertPushed(ProjectTradingEngineEvent::class, 2);
        Queue::assertPushed(ProjectTradingEngineEvent::class, function (ProjectTradingEngineEvent $job) use ($stored): bool {
            return $job->eventId === $stored->event_id
                && ! property_exists($job, 'event')
                && ! property_exists($job, 'payload');
        });
        $this->assertTrue($stored->fresh()->next_handling_at->equalTo(now()->addSeconds(120)));
        $this->assertTrue($retryable->fresh()->next_handling_at->equalTo(now()->addSeconds(120)));
    }

    /** @param array{status: string, event_type: string, message: string} $case */
    #[DataProvider('terminalAndUnsupportedEvents')]
    public function test_manual_retry_rejects_terminal_states_and_unsupported_events(array $case): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $event = $this->event(40, $case['status'], eventType: $case['event_type']);

        $this->artisan('trading-engine:retry-projection', ['event-id' => $event->event_id])
            ->expectsOutputToContain($case['message'])
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    /** @return array<string, array{array{status: string, event_type: string, message: string}}> */
    public static function terminalAndUnsupportedEvents(): array
    {
        return [
            'projected' => [[
                'status' => TradingEngineEvent::STATUS_PROJECTED,
                'event_type' => 'opportunity.recorded.v1',
                'message' => 'already projected',
            ]],
            'failed' => [[
                'status' => TradingEngineEvent::STATUS_FAILED,
                'event_type' => 'opportunity.recorded.v1',
                'message' => 'terminal integrity failure',
            ]],
            'unhandled' => [[
                'status' => TradingEngineEvent::STATUS_UNHANDLED,
                'event_type' => 'opportunity.recorded.v1',
                'message' => 'Unhandled event types',
            ]],
            'unsupported type' => [[
                'status' => TradingEngineEvent::STATUS_STORED,
                'event_type' => 'foundation.noop_accepted.v1',
                'message' => 'not supported',
            ]],
        ];
    }

    public function test_deferred_evaluation_requires_its_causal_recorded_link(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $recorded = $this->event(50, TradingEngineEvent::STATUS_PROJECTED);
        $evaluation = $this->event(
            51,
            TradingEngineEvent::STATUS_DEFERRED,
            now()->subMinute(),
            eventType: 'opportunity.evaluated.v1',
            causationId: $recorded->event_id,
        );

        $this->artisan('trading-engine:retry-projection', ['event-id' => $evaluation->event_id])
            ->expectsOutputToContain('waiting for its recorded opportunity correlation')
            ->assertFailed();
        Queue::assertNothingPushed();

        $this->link($this->opportunity(), $recorded);

        $this->artisan('trading-engine:retry-projection', ['event-id' => $evaluation->event_id])
            ->assertSuccessful();
        Queue::assertPushed(
            ProjectTradingEngineEvent::class,
            fn (ProjectTradingEngineEvent $job): bool => $job->eventId === $evaluation->event_id,
        );
    }

    public function test_active_lease_blocks_retry_and_expired_lease_is_reacquired(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $event = $this->event(60, nextHandlingAt: now()->addSeconds(120));

        $this->artisan('trading-engine:retry-projection', ['event-id' => $event->event_id])
            ->expectsOutputToContain('active dispatch lease')
            ->assertFailed();
        Queue::assertNothingPushed();

        $this->travel(121)->seconds();
        $this->artisan('trading-engine:retry-projection', ['event-id' => $event->event_id])
            ->assertSuccessful();

        Queue::assertPushed(ProjectTradingEngineEvent::class, 1);
        $this->assertTrue($event->fresh()->next_handling_at->equalTo(now()->addSeconds(120)));
    }

    public function test_duplicate_manual_retry_attempts_acquire_one_lease_and_dispatch_once(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $event = $this->event(61);
        $recovery = app(TradingEngineProjectionRecovery::class);

        $first = $recovery->retry($event->event_id);
        $second = $recovery->retry($event->event_id);

        $this->assertSame('dispatched', $first['status']);
        $this->assertSame('blocked', $second['status']);
        $this->assertSame('PROJECTION_LEASE_ACTIVE', $second['error_code']);
        Queue::assertPushed(ProjectTradingEngineEvent::class, 1);
    }

    public function test_queue_dispatch_failure_is_redacted_and_leaves_event_recoverable(): void
    {
        $event = $this->event(62);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('secret queue credential and private signature'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $exitCode = Artisan::call('trading-engine:retry-projection', ['event-id' => $event->event_id]);
        $output = Artisan::output();
        $recovered = $event->fresh();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('remains recoverable', $output);
        $this->assertStringNotContainsString('secret queue credential', $output);
        $this->assertSame(TradingEngineEvent::STATUS_RETRYABLE, $recovered->handling_status);
        $this->assertSame('PROJECTION_DISPATCH_FAILED', $recovered->handling_error_code);
        $this->assertTrue($recovered->next_handling_at->equalTo(now()->addMinute()));
        $this->assertStringNotContainsString(
            'secret queue credential',
            json_encode($recovered->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_manual_retry_has_zero_trading_wallet_swap_position_and_notification_side_effects(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        Notification::fake();
        $this->mock(UserTelegramNotificationService::class)->shouldNotReceive('send');
        $opportunity = $this->opportunity();
        $before = $opportunity->fresh()->getAttributes();
        $event = $this->event(70);

        $this->artisan('trading-engine:retry-projection', ['event-id' => $event->event_id])
            ->assertSuccessful();

        $this->assertSame($before, $opportunity->fresh()->getAttributes());
        $this->assertDatabaseCount('trade_opportunity_events', 0);
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('connected_wallets', 0);
        Notification::assertNothingSent();
        Queue::assertPushed(ProjectTradingEngineEvent::class, 1);
    }

    private function event(
        int $number,
        string $status = TradingEngineEvent::STATUS_STORED,
        mixed $nextHandlingAt = null,
        mixed $receivedAt = null,
        string $eventType = 'opportunity.recorded.v1',
        ?string $causationId = null,
    ): TradingEngineEvent {
        $eventId = $this->eventId($number);

        return TradingEngineEvent::query()->create([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'schema_version' => 1,
            'occurred_at' => now()->subMinutes(10),
            'producer' => 'trading-engine',
            'aggregate_type' => $eventType === 'opportunity.evaluated.v1'
                ? 'opportunity_evaluation'
                : 'opportunity',
            'aggregate_id' => $eventId,
            'aggregate_version' => 1,
            'correlation_id' => 'operations-correlation-'.$number,
            'causation_id' => $causationId ?? $eventId,
            'idempotency_key' => 'operations:'.$number,
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'payload_sha256' => str_repeat('a', 64),
            'raw_body_sha256' => str_repeat('b', 64),
            'event_envelope' => ['test' => true],
            'payload' => ['test' => true],
            'handling_status' => $status,
            'handling_attempts' => 0,
            'handling_error_code' => $status === TradingEngineEvent::STATUS_FAILED
                ? 'TERMINAL_TEST_FAILURE'
                : null,
            'next_handling_at' => $nextHandlingAt,
            'received_at' => $receivedAt ?? now(),
            'handled_at' => in_array($status, [
                TradingEngineEvent::STATUS_PROJECTED,
                TradingEngineEvent::STATUS_FAILED,
                TradingEngineEvent::STATUS_UNHANDLED,
            ], true) ? now() : null,
        ]);
    }

    private function opportunity(): TradeOpportunity
    {
        $user = User::factory()->create();

        return TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'discovery_key' => str_repeat('a', 64),
            'address' => 'So11111111111111111111111111111111111111112',
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::PendingConfirmation,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Confirm,
            'execution_data' => ['unchanged' => true],
        ]);
    }

    private function link(TradeOpportunity $opportunity, TradingEngineEvent $recorded): void
    {
        TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => $recorded->aggregate_id,
            'recorded_event_id' => $recorded->event_id,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $opportunity->discovery_key,
            'scanner' => $opportunity->scanner,
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
            'recorded_at' => $recorded->occurred_at,
            'linked_at' => now(),
        ]);
    }

    private function eventId(int $number): string
    {
        return '01KA'.str_pad((string) $number, 22, '0', STR_PAD_LEFT);
    }
}
