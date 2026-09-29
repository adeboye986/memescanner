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
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEngineProjectionEligibility;
use App\Services\TradingEngine\TradingEngineProjectionRecovery;
use App\Services\UserTelegramNotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TradingEngineProjectionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const ENGINE_OPPORTUNITY_ID = '01K9ABCDEFGHJKMNPQRSTVWXYZ';

    private const EVALUATION_EVENT_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBC';

    private const EVALUATION_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBA';

    private const RECORDED_EVENT_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBB';

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

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

    /**
     * @param  array{engine: bool, projection: bool, export: bool}  $flags
     */
    #[DataProvider('disabledFlagCombinations')]
    public function test_recovery_requires_engine_and_projection_flags(array $flags): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        config()->set([
            'services.trading_engine.enabled' => $flags['engine'],
            'services.trading_engine.opportunity_projection_enabled' => $flags['projection'],
            'services.trading_engine.opportunity_export_enabled' => $flags['export'],
        ]);
        $event = $this->genericEvent(
            1,
            receivedAt: now()->subMinutes(10),
        );

        $this->artisan('trading-engine:recover-projections')
            ->expectsOutputToContain('projection recovery is disabled')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($event->fresh()->next_handling_at);
    }

    /** @return array<string, array{array{engine: bool, projection: bool, export: bool}}> */
    public static function disabledFlagCombinations(): array
    {
        return [
            'all disabled' => [[
                'engine' => false,
                'projection' => false,
                'export' => false,
            ]],
            'engine only' => [[
                'engine' => true,
                'projection' => false,
                'export' => false,
            ]],
            'projection and export without engine' => [[
                'engine' => false,
                'projection' => true,
                'export' => true,
            ]],
        ];
    }

    public function test_export_flag_is_not_required_for_projection_recovery(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $event = $this->genericEvent(2, receivedAt: now()->subMinutes(10));

        $result = app(TradingEngineProjectionRecovery::class)->recover();

        $this->assertFalse(config('services.trading_engine.opportunity_export_enabled'));
        $this->assertSame([
            'enabled' => true,
            'selected' => 1,
            'dispatched' => 1,
            'failed' => 0,
        ], $result);
        Queue::assertPushed(
            ProjectTradingEngineEvent::class,
            fn (ProjectTradingEngineEvent $job): bool => $job->eventId === $event->event_id,
        );
    }

    public function test_stale_stored_selection_is_bounded_deterministic_and_excludes_recent_events(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        config()->set('services.trading_engine.projection_recovery_batch_size', 2);
        $first = $this->genericEvent(10, receivedAt: now()->subMinutes(10));
        $second = $this->genericEvent(11, receivedAt: now()->subMinutes(10));
        $third = $this->genericEvent(12, receivedAt: now()->subMinutes(10));
        $recent = $this->genericEvent(13, receivedAt: now());

        $result = app(TradingEngineProjectionRecovery::class)->recover();

        $this->assertSame(2, $result['selected']);
        $this->assertSame(
            [$first->event_id, $second->event_id],
            Queue::pushed(ProjectTradingEngineEvent::class)
                ->map(fn (ProjectTradingEngineEvent $job): string => $job->eventId)
                ->values()
                ->all(),
        );
        $this->assertTrue($first->fresh()->next_handling_at->equalTo(now()->addSeconds(120)));
        $this->assertTrue($second->fresh()->next_handling_at->equalTo(now()->addSeconds(120)));
        $this->assertNull($third->fresh()->next_handling_at);
        $this->assertNull($recent->fresh()->next_handling_at);
    }

    public function test_only_due_retryable_events_are_recovered(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $due = $this->genericEvent(
            20,
            TradingEngineEvent::STATUS_RETRYABLE,
            now()->subSecond(),
            now()->subMinutes(10),
        );
        $future = $this->genericEvent(
            21,
            TradingEngineEvent::STATUS_RETRYABLE,
            now()->addMinute(),
            now()->subMinutes(10),
        );
        $missingSchedule = $this->genericEvent(
            22,
            TradingEngineEvent::STATUS_RETRYABLE,
            null,
            now()->subMinutes(10),
        );

        $result = app(TradingEngineProjectionRecovery::class)->recover();

        $this->assertSame(1, $result['selected']);
        Queue::assertPushed(
            ProjectTradingEngineEvent::class,
            fn (ProjectTradingEngineEvent $job): bool => $job->eventId === $due->event_id,
        );
        $this->assertTrue($future->fresh()->next_handling_at->equalTo(now()->addMinute()));
        $this->assertNull($missingSchedule->fresh()->next_handling_at);
    }

    public function test_dispatch_lease_prevents_duplicate_recovery_and_expiry_recovers_lost_work(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $event = $this->genericEvent(30, receivedAt: now()->subMinutes(10));
        $recovery = app(TradingEngineProjectionRecovery::class);

        $first = $recovery->recover();
        $second = $recovery->recover();
        $this->travel(121)->seconds();
        $third = $recovery->recover();

        $this->assertSame(1, $first['selected']);
        $this->assertSame(0, $second['selected']);
        $this->assertSame(1, $third['selected']);
        Queue::assertPushed(ProjectTradingEngineEvent::class, 2);
        $this->assertSame(
            [$event->event_id, $event->event_id],
            Queue::pushed(ProjectTradingEngineEvent::class)
                ->map(fn (ProjectTradingEngineEvent $job): string => $job->eventId)
                ->values()
                ->all(),
        );
    }

    public function test_deferred_evaluation_does_not_churn_until_its_causal_link_exists(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $recordedEventId = $this->eventId(40);
        $evaluation = $this->genericEvent(
            41,
            TradingEngineEvent::STATUS_DEFERRED,
            now()->subMinute(),
            now()->subMinutes(10),
            'opportunity.evaluated.v1',
            $recordedEventId,
        );
        $originalNextHandlingAt = $evaluation->next_handling_at;

        $first = app(TradingEngineProjectionRecovery::class)->recover();
        $this->travel(1)->minute();
        $second = app(TradingEngineProjectionRecovery::class)->recover();

        $this->assertSame(0, $first['selected']);
        $this->assertSame(0, $second['selected']);
        Queue::assertNothingPushed();
        $this->assertTrue($evaluation->fresh()->next_handling_at->equalTo($originalNextHandlingAt));

        $opportunity = $this->opportunity();
        $recorded = $this->genericEvent(
            40,
            TradingEngineEvent::STATUS_PROJECTED,
            null,
            now()->subMinutes(10),
            'opportunity.recorded.v1',
        );
        $this->link($opportunity, $recorded);
        $third = app(TradingEngineProjectionRecovery::class)->recover();

        $this->assertSame(1, $third['selected']);
        Queue::assertPushed(
            ProjectTradingEngineEvent::class,
            fn (ProjectTradingEngineEvent $job): bool => $job->eventId === $evaluation->event_id,
        );
    }

    public function test_terminal_and_nonprojectable_events_are_never_recovered(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);

        foreach ([
            TradingEngineEvent::STATUS_PROJECTED,
            TradingEngineEvent::STATUS_FAILED,
            TradingEngineEvent::STATUS_UNHANDLED,
        ] as $index => $status) {
            $this->genericEvent(
                50 + $index,
                $status,
                now()->subMinute(),
                now()->subMinutes(10),
            );
        }

        $this->genericEvent(
            60,
            TradingEngineEvent::STATUS_STORED,
            null,
            now()->subMinutes(10),
            'foundation.noop_accepted.v1',
        );

        $result = app(TradingEngineProjectionRecovery::class)->recover();

        $this->assertSame(0, $result['selected']);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_failure_is_safe_and_remains_retryable(): void
    {
        $event = $this->genericEvent(70, receivedAt: now()->subMinutes(10));
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn (mixed $job): bool => $job instanceof ProjectTradingEngineEvent
                && $job->eventId === $event->event_id))
            ->andThrow(new RuntimeException('private-key-material-must-not-be-persisted'));
        $recovery = new TradingEngineProjectionRecovery(
            $dispatcher,
            app(TradingEngineOpportunityProjector::class),
            app(TradingEngineProjectionEligibility::class),
        );

        $result = $recovery->recover();
        $recovered = $event->fresh();

        $this->assertSame([
            'enabled' => true,
            'selected' => 1,
            'dispatched' => 0,
            'failed' => 1,
        ], $result);
        $this->assertSame(TradingEngineEvent::STATUS_RETRYABLE, $recovered->handling_status);
        $this->assertSame('PROJECTION_DISPATCH_FAILED', $recovered->handling_error_code);
        $this->assertTrue($recovered->next_handling_at->equalTo(now()->addMinute()));
        $this->assertNull($recovered->handled_at);
        $this->assertStringNotContainsString(
            'private-key-material',
            json_encode($recovered->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_reversed_delivery_recovers_once_without_business_or_execution_side_effects(): void
    {
        Notification::fake();
        $this->mock(UserTelegramNotificationService::class)->shouldNotReceive('send');
        config()->set('queue.default', 'sync');
        $opportunity = $this->opportunity();
        $original = $opportunity->fresh()->getAttributes();
        $evaluation = $this->storeEvent(
            $this->evaluatedEvent(),
            TradingEngineEvent::STATUS_DEFERRED,
            now()->subMinute(),
            now()->subMinutes(10),
        );
        $recorded = $this->storeEvent(
            $this->recordedEvent($opportunity),
            TradingEngineEvent::STATUS_STORED,
            null,
            now()->subMinutes(10),
        );
        $recovery = app(TradingEngineProjectionRecovery::class);

        $first = $recovery->recover();
        $second = $recovery->recover();

        $this->assertSame([
            'enabled' => true,
            'selected' => 1,
            'dispatched' => 1,
            'failed' => 0,
        ], $first);
        $this->assertSame(0, $second['selected']);
        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $recorded->fresh()->handling_status);
        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $evaluation->fresh()->handling_status);
        $this->assertSame($original, $opportunity->fresh()->getAttributes());
        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
        $this->assertDatabaseCount('trade_opportunity_events', 0);
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('connected_wallets', 0);
        Notification::assertNothingSent();
    }

    public function test_recovery_is_scheduled_every_minute_with_overlap_protection(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($candidate): bool => $candidate->description === 'trading-engine.projection-recovery');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertSame(5, $event->expiresAt);
        $this->assertStringContainsString('trading-engine:recover-projections', $event->command);
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

    private function genericEvent(
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
            'aggregate_type' => 'opportunity',
            'aggregate_id' => $eventId,
            'aggregate_version' => 1,
            'correlation_id' => 'recovery-correlation-'.$number,
            'causation_id' => $causationId ?? $eventId,
            'idempotency_key' => 'recovery:'.$number,
            'traceparent' => self::TRACEPARENT,
            'payload_sha256' => str_repeat('a', 64),
            'raw_body_sha256' => str_repeat('b', 64),
            'event_envelope' => ['test' => true],
            'payload' => ['test' => true],
            'handling_status' => $status,
            'handling_attempts' => 0,
            'handling_error_code' => null,
            'next_handling_at' => $nextHandlingAt,
            'received_at' => $receivedAt ?? now(),
            'handled_at' => in_array($status, [
                TradingEngineEvent::STATUS_PROJECTED,
                TradingEngineEvent::STATUS_FAILED,
                TradingEngineEvent::STATUS_UNHANDLED,
            ], true) ? now() : null,
        ]);
    }

    private function link(
        TradeOpportunity $opportunity,
        TradingEngineEvent $recorded,
    ): TradingEngineOpportunityLink {
        return TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => self::ENGINE_OPPORTUNITY_ID,
            'recorded_event_id' => $recorded->event_id,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $opportunity->discovery_key,
            'scanner' => $opportunity->scanner,
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
            'recorded_at' => now()->subMinutes(10),
            'linked_at' => now(),
        ]);
    }

    private function eventId(int $number): string
    {
        return '01K9'.str_pad((string) $number, 22, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $event */
    private function storeEvent(
        array $event,
        string $status,
        mixed $nextHandlingAt,
        mixed $receivedAt,
    ): TradingEngineEvent {
        return TradingEngineEvent::query()->create([
            ...$event,
            'raw_body_sha256' => str_repeat('f', 64),
            'event_envelope' => $event,
            'handling_status' => $status,
            'handling_attempts' => 0,
            'handling_error_code' => $status === TradingEngineEvent::STATUS_DEFERRED
                ? 'CAUSAL_RECORDED_EVENT_PENDING'
                : null,
            'next_handling_at' => $nextHandlingAt,
            'received_at' => $receivedAt,
            'handled_at' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function recordedEvent(TradeOpportunity $opportunity): array
    {
        $payload = [
            'operation_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBF',
            'opportunity_id' => self::ENGINE_OPPORTUNITY_ID,
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
                'volume_usd' => ['value' => '800', 'provider' => 'birdeye', 'window' => '1m'],
            ],
            'qualification' => [
                'qualified_at' => '2026-09-29T12:00:00.000Z',
                'discovery_market_cap_usd' => '10000',
                'move_since_discovery_percent' => '20',
            ],
        ];

        return $this->envelope([
            'event_id' => self::RECORDED_EVENT_ID,
            'event_type' => 'opportunity.recorded.v1',
            'aggregate_type' => 'opportunity',
            'aggregate_id' => self::ENGINE_OPPORTUNITY_ID,
            'causation_id' => $payload['operation_id'],
            'idempotency_key' => 'opportunity:record:laravel:'.$opportunity->getKey().':v1',
            'payload' => $payload,
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluatedEvent(): array
    {
        $payload = [
            'evaluation_id' => self::EVALUATION_ID,
            'opportunity_id' => self::ENGINE_OPPORTUNITY_ID,
            'policy' => [
                'key' => 'migration-opportunity-snapshot',
                'version' => 1,
                'algorithm_key' => 'threshold-matrix',
                'algorithm_version' => 1,
                'definition_sha256' => str_repeat('a', 64),
            ],
            'source' => [
                'request_sha256' => str_repeat('b', 64),
                'evaluation_input_sha256' => str_repeat('c', 64),
            ],
            'outcome' => 'passed',
            'reason_codes' => [],
            'advisory_codes' => ['SECURITY_EVIDENCE_UNAVAILABLE'],
            'evidence' => [
                'profile' => 'solana:new-token',
                'facts' => [
                    'market_cap_usd' => '12000',
                    'liquidity_usd' => null,
                    'volume_5m_usd' => null,
                    'move_since_discovery_percent' => '20',
                    'classification' => 'strong',
                    'pair_address' => null,
                    'pair_available' => null,
                    'requested_token_is_base' => null,
                    'security_status' => 'unavailable',
                    'security_provider' => null,
                    'security_passed' => null,
                ],
                'checks' => [
                    ['check' => 'market_cap', 'status' => 'passed'],
                    ['check' => 'liquidity', 'status' => 'indeterminate'],
                    ['check' => 'movement', 'status' => 'passed'],
                    ['check' => 'classification', 'status' => 'passed'],
                    ['check' => 'security', 'status' => 'passed'],
                ],
            ],
            'result_sha256' => str_repeat('d', 64),
        ];

        return $this->envelope([
            'event_id' => self::EVALUATION_EVENT_ID,
            'event_type' => 'opportunity.evaluated.v1',
            'aggregate_type' => 'opportunity_evaluation',
            'aggregate_id' => self::EVALUATION_ID,
            'causation_id' => self::RECORDED_EVENT_ID,
            'idempotency_key' => 'evaluation:'.self::ENGINE_OPPORTUNITY_ID.':migration-opportunity-snapshot:1',
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function envelope(array $overrides): array
    {
        return [
            'event_id' => $overrides['event_id'],
            'event_type' => $overrides['event_type'],
            'schema_version' => 1,
            'occurred_at' => '2026-09-29T12:00:00.000Z',
            'producer' => 'trading-engine',
            'aggregate_type' => $overrides['aggregate_type'],
            'aggregate_id' => $overrides['aggregate_id'],
            'aggregate_version' => 1,
            'correlation_id' => 'opportunity-correlation',
            'causation_id' => $overrides['causation_id'],
            'idempotency_key' => $overrides['idempotency_key'],
            'traceparent' => self::TRACEPARENT,
            'payload' => $overrides['payload'],
            'payload_sha256' => hash('sha256', json_encode(
                $overrides['payload'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )),
        ];
    }
}
