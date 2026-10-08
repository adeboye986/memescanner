<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Jobs\SubmitTradingEnginePaperFinancialObservation;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEnginePaperEntryIntent;
use App\Models\TradingEnginePaperExitSettlementProjection;
use App\Models\TradingEnginePaperFinancialObservation;
use App\Models\TradingEnginePaperPositionProjection;
use App\Models\TradingEnginePaperPositionState;
use App\Models\TradingEnginePaperWalletProjection;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEnginePaperFinancialLifecycleIntegration;
use App\Services\TradingEngine\TradingEnginePaperFinancialLifecyclePayloadValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TradingEnginePaperFinancialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.paper_entry_integration_enabled' => true,
            'services.trading_engine.paper_financial_lifecycle_enabled' => true,
        ]);
    }

    public function test_hold_projects_once_without_financial_or_legacy_mutation(): void
    {
        Queue::fake();
        $fixture = $this->enginePosition();
        $legacyWalletsBefore = DB::table('paper_wallets')->count();
        $legacyPositionsBefore = DB::table('paper_positions')->count();
        config()->set('services.trading_engine.paper_entry_integration_enabled', false);
        $observation = app(TradingEnginePaperFinancialLifecycleIntegration::class)->submitValidated(
            $fixture['position'],
            $this->marketObservation('13000', '0.0000011'),
        );
        $duplicate = app(TradingEnginePaperFinancialLifecycleIntegration::class)->submitValidated(
            $fixture['position'],
            $this->marketObservation('14000', '0.0000012'),
        );

        $this->assertInstanceOf(TradingEnginePaperFinancialObservation::class, $observation);
        $this->assertSame($observation->getKey(), $duplicate?->getKey());
        $this->assertDatabaseCount('trading_engine_paper_financial_observations', 1);
        Queue::assertPushed(SubmitTradingEnginePaperFinancialObservation::class, 1);

        $payload = $this->decisionPayload($fixture, $observation, 'HOLD');
        $this->assertTrue(app(TradingEnginePaperFinancialLifecyclePayloadValidator::class)->isValidHeld($payload));
        $event = $this->event('paper.position.held.v1', $fixture, $observation, $payload);

        app(TradingEngineOpportunityProjector::class)->project($event->event_id);
        app(TradingEngineOpportunityProjector::class)->project($event->event_id);

        $state = $fixture['state']->fresh();
        $this->assertSame('open', $state->state);
        $this->assertSame(1, $state->lifecycle_version);
        $this->assertSame(1, $state->last_observation_sequence);
        $this->assertSame('13000', $state->last_market_cap_usd);
        $this->assertSame('projected', $observation->fresh()->status);
        $this->assertSame('HOLD', $observation->fresh()->engine_decision);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlement_projections', 0);
        $this->assertSame('4.9', $fixture['wallet']->fresh()->available_balance_native);
        $this->assertSame('0.1', $fixture['wallet']->fresh()->invested_balance_native);
        $this->assertSame($legacyWalletsBefore, DB::table('paper_wallets')->count());
        $this->assertSame($legacyPositionsBefore, DB::table('paper_positions')->count());
    }

    public function test_exit_projects_authoritative_settlement_once_without_legacy_financial_write(): void
    {
        Queue::fake();
        $fixture = $this->enginePosition();
        $legacyWalletsBefore = DB::table('paper_wallets')->count();
        $legacyPositionsBefore = DB::table('paper_positions')->count();
        $observation = app(TradingEnginePaperFinancialLifecycleIntegration::class)->submitValidated(
            $fixture['position'],
            $this->marketObservation('10200', '0.00000085'),
        );
        $payload = $this->decisionPayload($fixture, $observation, 'EXIT');
        $this->assertTrue(app(TradingEnginePaperFinancialLifecyclePayloadValidator::class)->isValidSettled($payload));
        $event = $this->event('paper.exit.settled.v1', $fixture, $observation, $payload);

        app(TradingEngineOpportunityProjector::class)->project($event->event_id);
        app(TradingEngineOpportunityProjector::class)->project($event->event_id);

        $settlement = TradingEnginePaperExitSettlementProjection::query()->sole();
        $this->assertSame($this->id(19), $settlement->engine_settlement_id);
        $this->assertSame('0.1', $settlement->cost_basis_native);
        $this->assertSame('0.085', $settlement->proceeds_native);
        $this->assertSame('-0.015', $settlement->realized_pnl_native);
        $this->assertSame('closed', $fixture['state']->fresh()->state);
        $this->assertSame('projected', $observation->fresh()->status);
        $this->assertSame('EXIT', $observation->fresh()->engine_decision);
        $this->assertSame('4.985', $fixture['wallet']->fresh()->available_balance_native);
        $this->assertSame('0', $fixture['wallet']->fresh()->invested_balance_native);
        $this->assertSame('-0.015', $fixture['wallet']->fresh()->realized_pnl_native);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlement_projections', 1);
        $this->assertSame($legacyWalletsBefore, DB::table('paper_wallets')->count());
        $this->assertSame($legacyPositionsBefore, DB::table('paper_positions')->count());
        $this->assertSame('open', $fixture['position']->fresh()->state);
    }

    public function test_changed_observation_evidence_fails_closed_without_settlement(): void
    {
        Queue::fake();
        $fixture = $this->enginePosition();
        $observation = app(TradingEnginePaperFinancialLifecycleIntegration::class)->submitValidated(
            $fixture['position'],
            $this->marketObservation('10200', '0.00000085'),
        );
        $payload = $this->decisionPayload($fixture, $observation, 'EXIT');
        $this->assertTrue(app(TradingEnginePaperFinancialLifecyclePayloadValidator::class)->isValidSettled($payload));
        $payload['market']['market_cap_usd'] = '10199';
        $event = $this->event('paper.exit.settled.v1', $fixture, $observation, $payload);

        $result = app(TradingEngineOpportunityProjector::class)->project($event->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame(
            'PAPER_FINANCIAL_LIFECYCLE_CORRELATION_INVALID',
            $event->fresh()->handling_error_code,
        );
        $this->assertSame('open', $fixture['state']->fresh()->state);
        $this->assertSame('pending', $observation->fresh()->status);
        $this->assertDatabaseCount('trading_engine_paper_exit_settlement_projections', 0);
        $this->assertSame('4.9', $fixture['wallet']->fresh()->available_balance_native);
        $this->assertSame('0.1', $fixture['wallet']->fresh()->invested_balance_native);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    /**
     * @return array{position: TradingEnginePaperPositionProjection, state: TradingEnginePaperPositionState, wallet: TradingEnginePaperWalletProjection}
     */
    private function enginePosition(): array
    {
        $user = User::factory()->create();
        $opportunity = TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'discovery_key' => hash('sha256', 'engine-financial-lifecycle'),
            'address' => 'So11111111111111111111111111111111111111112',
            'symbol' => 'MEME',
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::Executed,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Auto,
        ]);
        $recorded = $this->supportingEvent($this->id(1), 'opportunity.recorded.v1', $this->id(2));
        $evaluated = $this->supportingEvent($this->id(3), 'opportunity.evaluated.v1', $this->id(4));
        $link = TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => $this->id(2),
            'recorded_event_id' => $recorded->event_id,
            'user_id' => $user->getKey(),
            'discovery_key' => $opportunity->discovery_key,
            'scanner' => 'new-token',
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
            'recorded_at' => now(),
            'linked_at' => now(),
        ]);
        $evaluation = TradingEngineOpportunityEvaluation::query()->create([
            'opportunity_link_id' => $link->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'evaluation_id' => $this->id(4),
            'engine_opportunity_id' => $this->id(2),
            'evaluation_event_id' => $evaluated->event_id,
            'recorded_event_id' => $recorded->event_id,
            'policy_key' => 'migration-opportunity-snapshot',
            'policy_version' => 1,
            'algorithm_key' => 'threshold-matrix',
            'algorithm_version' => 1,
            'policy_definition_sha256' => str_repeat('a', 64),
            'source_request_sha256' => str_repeat('b', 64),
            'evaluation_input_sha256' => str_repeat('c', 64),
            'result_sha256' => str_repeat('d', 64),
            'outcome' => 'passed',
            'reason_codes' => [],
            'advisory_codes' => [],
            'evidence' => [],
            'correlation_id' => 'entry-correlation',
            'traceparent' => self::TRACEPARENT,
            'evaluated_at' => now(),
            'event_received_at' => now(),
            'projected_at' => now(),
        ]);
        $intent = TradingEnginePaperEntryIntent::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'opportunity_link_id' => $link->getKey(),
            'opportunity_evaluation_id' => $evaluation->getKey(),
            'user_id' => $user->getKey(),
            'idempotency_key' => 'paper:entry:laravel:'.$opportunity->getKey().':v1',
            'payload_sha256' => str_repeat('e', 64),
            'payload' => [],
            'status' => 'projected',
        ]);
        $wallet = TradingEnginePaperWalletProjection::query()->create([
            'engine_wallet_id' => $this->id(5),
            'user_id' => $user->getKey(),
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'currency' => 'SOL',
            'opening_balance_native' => '5',
            'available_balance_native' => '4.9',
            'invested_balance_native' => '0.1',
            'realized_pnl_native' => '0',
            'last_event_id' => $this->id(6),
            'last_event_occurred_at' => '2026-10-07T12:00:00.000Z',
            'last_payload_sha256' => str_repeat('f', 64),
            'projected_at' => now(),
        ]);
        $position = TradingEnginePaperPositionProjection::query()->create([
            'engine_position_id' => $this->id(7),
            'wallet_projection_id' => $wallet->getKey(),
            'paper_entry_intent_id' => $intent->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'opportunity_evaluation_id' => $evaluation->getKey(),
            'user_id' => $user->getKey(),
            'engine_intent_id' => $this->id(8),
            'engine_order_id' => $this->id(9),
            'engine_fill_id' => $this->id(10),
            'engine_opportunity_id' => $this->id(2),
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
            'symbol' => $opportunity->symbol,
            'state' => 'open',
            'quantity' => '1',
            'quantity_unit' => 'normalized_position_unit',
            'cost_basis_native' => '0.1',
            'entry_price_usd' => '0.000001',
            'entry_market_cap_usd' => '12000',
            'entry_liquidity_usd' => '3000',
            'strategy_snapshot' => [
                'stop_loss_percent' => '10',
                'protection_level_1_percent' => '100',
                'protection_level_2_percent' => '200',
            ],
            'authority_snapshot' => [],
            'entry_event_id' => $this->id(11),
            'entry_payload_sha256' => str_repeat('1', 64),
            'opened_at' => now(),
            'projected_at' => now(),
        ]);
        $state = TradingEnginePaperPositionState::query()->create([
            'position_projection_id' => $position->getKey(),
            'lifecycle_version' => 0,
            'next_observation_sequence' => 1,
            'state' => 'open',
            'protection_state' => 'none',
            'peak_market_cap_usd' => '12000',
            'peak_multiple' => '1',
            'max_drawdown_percent' => '0',
        ]);

        return compact('position', 'state', 'wallet');
    }

    /** @return array<string, mixed> */
    private function marketObservation(string $marketCap, string $price): array
    {
        return [
            'simulation_allowed' => true,
            'market_cap' => $marketCap,
            'price_usd' => $price,
            'liquidity_usd' => '2800',
            'provider_observed_at' => '2026-10-07T12:00:00.000Z',
            'fetched_at' => '2026-10-07T12:00:00.000Z',
            'provider' => 'birdeye',
        ];
    }

    /**
     * @param  array{position: TradingEnginePaperPositionProjection, state: TradingEnginePaperPositionState, wallet: TradingEnginePaperWalletProjection}  $fixture
     * @return array<string, mixed>
     */
    private function decisionPayload(array $fixture, TradingEnginePaperFinancialObservation $observation, string $decision): array
    {
        $payload = [
            'operation_id' => $this->id(12),
            'position_id' => $fixture['position']->engine_position_id,
            'decision_id' => $this->id(13),
            'source' => $observation->payload['source'],
            'subject' => $observation->payload['subject'],
            'network' => $observation->payload['network'],
            'asset' => $observation->payload['asset'],
            'policy' => ['key' => 'laravel-paper-protection', 'version' => 1],
            'lifecycle_version' => 1,
            'decision' => $decision,
            'exit_type' => $decision === 'EXIT' ? 'stop_loss' : null,
            'market' => $observation->payload['market'],
            'observed_multiple' => $decision === 'EXIT' ? '0.85' : '1.083333333333333333333333333333',
            'trigger_multiple' => $decision === 'EXIT' ? '0.9' : null,
            'peak_market_cap_usd' => $decision === 'EXIT' ? '12000' : '13000',
            'peak_multiple' => $decision === 'EXIT' ? '1' : '1.083333333333333333333333333333',
            'drawdown_percent' => $decision === 'EXIT' ? '-15' : '0',
            'protection_before' => 'none',
            'protection_after' => 'none',
            'transitions' => [],
        ];

        if ($decision === 'EXIT') {
            $payload['wallet'] = [
                'wallet_id' => $fixture['wallet']->engine_wallet_id,
                'currency' => 'SOL',
                'available_balance_native' => '4.985',
                'invested_balance_native' => '0',
                'realized_pnl_native' => '-0.015',
            ];
            $payload['settlement'] = [
                'settlement_id' => $this->id(19),
                'order_id' => $this->id(20),
                'fill_id' => $this->id(21),
                'ledger_transaction_id' => $this->id(22),
                'ledger_transaction_reference' => 'paper:exit:'.$fixture['position']->engine_position_id.':v1',
                'cost_basis_native' => '0.1',
                'proceeds_native' => '0.085',
                'realized_pnl_native' => '-0.015',
                'exit_price_usd' => '0.00000085',
                'exit_market_cap_usd' => '10200',
                'observed_multiple' => '0.85',
                'fill_model' => 'observed_market_cap_ratio_v1',
                'settled_at' => '2026-10-07T12:00:01.000Z',
            ];
        }

        return $payload;
    }

    /**
     * @param  array{position: TradingEnginePaperPositionProjection, state: TradingEnginePaperPositionState, wallet: TradingEnginePaperWalletProjection}  $fixture
     * @param  array<string, mixed>  $payload
     */
    private function event(
        string $type,
        array $fixture,
        TradingEnginePaperFinancialObservation $observation,
        array $payload,
    ): TradingEngineEvent {
        return $this->supportingEvent(
            $this->id(14),
            $type,
            $fixture['position']->engine_position_id,
            TradingEngineEvent::STATUS_STORED,
            $payload,
            $observation->idempotency_key,
            $payload['operation_id'],
            2,
        );
    }

    /** @param array<string, mixed> $payload */
    private function supportingEvent(
        string $eventId,
        string $type,
        string $aggregateId,
        string $status = TradingEngineEvent::STATUS_PROJECTED,
        array $payload = [],
        string $idempotencyKey = 'test-event',
        ?string $causationId = null,
        int $aggregateVersion = 1,
    ): TradingEngineEvent {
        return TradingEngineEvent::query()->create([
            'event_id' => $eventId,
            'event_type' => $type,
            'schema_version' => 1,
            'occurred_at' => '2026-10-07T12:00:01.000Z',
            'producer' => 'trading-engine',
            'aggregate_type' => 'paper_position',
            'aggregate_id' => $aggregateId,
            'aggregate_version' => $aggregateVersion,
            'correlation_id' => 'financial-lifecycle-correlation',
            'causation_id' => $causationId ?? $aggregateId,
            'idempotency_key' => $idempotencyKey,
            'traceparent' => self::TRACEPARENT,
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($payload),
            'raw_body_sha256' => str_repeat('9', 64),
            'event_envelope' => [],
            'payload' => $payload,
            'handling_status' => $status,
            'handling_attempts' => 0,
            'received_at' => now(),
        ]);
    }

    private function id(int $sequence): string
    {
        return '01M6'.str_pad((string) $sequence, 22, '0', STR_PAD_LEFT);
    }
}
