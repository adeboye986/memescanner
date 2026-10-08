<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEnginePaperEntryIntent;
use App\Models\TradingEnginePaperPositionProjection;
use App\Models\TradingEnginePaperWalletProjection;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEnginePaperEntryNotifier;
use App\Services\TradingEngine\TradingEnginePaperEntryPayloadValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TradingEnginePaperEntryProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.paper_entry_integration_enabled' => true,
        ]);
    }

    public function test_entry_event_projects_once_without_legacy_financial_write_or_close_authority(): void
    {
        $user = User::factory()->create();
        $opportunity = TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'discovery_key' => hash('sha256', 'engine-entry-projection'),
            'address' => 'So11111111111111111111111111111111111111112',
            'symbol' => 'MEME',
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Auto,
            'price' => 0.000001,
            'market_cap' => 12000,
            'liquidity' => 3000.25,
            'qualified_at' => now(),
        ]);
        $recorded = $this->event($this->id(1), 'opportunity.recorded.v1', $this->id(2), 'projected');
        $evaluated = $this->event($this->id(3), 'opportunity.evaluated.v1', $this->id(4), 'projected');
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
        $command = $this->command($opportunity, $link, $evaluation);
        $intent = TradingEnginePaperEntryIntent::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'opportunity_link_id' => $link->getKey(),
            'opportunity_evaluation_id' => $evaluation->getKey(),
            'user_id' => $user->getKey(),
            'idempotency_key' => 'paper:entry:laravel:'.$opportunity->getKey().':v1',
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($command),
            'payload' => $command,
            'status' => 'submitted',
        ]);
        $payload = $this->entryEventPayload($command);
        $this->assertTrue(app(TradingEnginePaperEntryPayloadValidator::class)->isValid($payload));
        $event = $this->event(
            $this->id(5),
            'paper.entry.executed.v1',
            $this->id(9),
            TradingEngineEvent::STATUS_STORED,
            $payload,
            'paper:entry:laravel:'.$opportunity->getKey().':v1',
            $this->id(6),
        );
        $legacyWalletsBefore = DB::table('paper_wallets')->count();
        $this->mock(TradingEnginePaperEntryNotifier::class)
            ->shouldReceive('send')
            ->once();

        app(TradingEngineOpportunityProjector::class)->project($event->event_id);
        app(TradingEngineOpportunityProjector::class)->project($event->event_id);

        $this->assertDatabaseCount('trading_engine_paper_wallet_projections', 1);
        $this->assertDatabaseCount('trading_engine_paper_position_projections', 1);
        $this->assertDatabaseCount('trading_engine_paper_position_states', 1);
        $this->assertDatabaseCount('trading_engine_paper_entry_intents', 1);
        $this->assertSame(
            '0.000001234567890123456789012345',
            TradingEnginePaperPositionProjection::query()->sole()->entry_price_usd,
        );
        $this->assertSame(
            '4.9',
            TradingEnginePaperWalletProjection::query()->sole()->available_balance_native,
        );
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertSame($legacyWalletsBefore, DB::table('paper_wallets')->count());
        $this->assertSame('projected', $intent->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::Executed, $opportunity->fresh()->status);
        $this->assertNull($opportunity->fresh()->paper_position_id);
        $this->assertSame($this->id(9), data_get($opportunity->fresh()->execution_data, 'engine_position_id'));

        $this->actingAs($user)
            ->post(route('paper-trades.close', ['position' => $this->id(9)]))
            ->assertNotFound();
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('trading_engine_paper_position_projections', 1);

        $tamperedPayload = $payload;
        $tamperedPayload['position']['entry_market_cap_usd'] = '12001';
        $tampered = $this->event(
            $this->id(13),
            'paper.entry.executed.v1',
            $this->id(9),
            TradingEngineEvent::STATUS_STORED,
            $tamperedPayload,
            'paper:entry:laravel:'.$opportunity->getKey().':v1',
            $this->id(6),
        );

        $result = app(TradingEngineOpportunityProjector::class)->project($tampered->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame('PAPER_ENTRY_EVENT_CORRELATION_INVALID', $tampered->fresh()->handling_error_code);
        $this->assertDatabaseCount('trading_engine_paper_position_projections', 1);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    /** @return array<string, mixed> */
    private function command(
        TradeOpportunity $opportunity,
        TradingEngineOpportunityLink $link,
        TradingEngineOpportunityEvaluation $evaluation,
    ): array {
        return [
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-laravel',
                'trade_opportunity_id' => (string) $opportunity->getKey(),
                'engine_opportunity_id' => $link->engine_opportunity_id,
                'evaluation_id' => $evaluation->evaluation_id,
                'evaluation_result_sha256' => $evaluation->result_sha256,
            ],
            'subject' => ['control_plane_user_id' => (string) $opportunity->user_id],
            'network' => [
                'id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
                'native_currency' => 'SOL',
            ],
            'asset' => ['address' => $opportunity->address, 'symbol' => $opportunity->symbol],
            'entry' => [
                'requested_notional_native' => '0.1',
                'market_cap_usd' => '12000',
                'price_usd' => '0.000001234567890123456789012345',
                'liquidity_usd' => '3000.25',
                'intent_created_at' => '2026-10-07T12:00:00.000Z',
                'expires_at' => '2026-10-07T12:05:00.000Z',
            ],
            'authority' => [
                'execution_mode' => 'paper',
                'entry_mode' => 'auto',
                'trading_enabled' => true,
                'preference_version' => 'preference-1',
                'kill_switch_engaged' => false,
                'kill_switch_version' => 'kill-switch-1',
                'strategy' => [
                    'stop_loss_percent' => '10',
                    'protection_level_1_percent' => '100',
                    'protection_level_2_percent' => '200',
                ],
                'risk' => ['trade_size_native' => '0.1', 'source' => 'laravel-control-plane'],
                'effective_policy' => ['key' => 'engine-paper-entry', 'version' => 1],
            ],
        ];
    }

    /**
     *   array<string, mixed>  $command
     *  array<string, mixed>
     */
    private function entryEventPayload(array $command): array
    {
        $canonical = app(TradingEngineCanonicalJson::class);

        return [
            'operation_id' => $this->id(6),
            'wallet' => [
                'wallet_id' => $this->id(7),
                'currency' => 'SOL',
                'opening_balance_native' => '5',
                'available_balance_native' => '4.9',
                'invested_balance_native' => '0.1',
            ],
            'intent' => [
                'intent_id' => $this->id(8),
                'intent_sha256' => $canonical->hash($command),
                'authority_sha256' => $canonical->hash($command['authority']),
                'notional_native' => '0.1',
            ],
            'order' => [
                'order_id' => $this->id(10),
                'side' => 'buy',
                'order_type' => 'simulated_market',
                'status' => 'filled',
            ],
            'fill' => [
                'fill_id' => $this->id(11),
                'notional_native' => '0.1',
                'fee_native' => '0',
                'fill_price_usd' => $command['entry']['price_usd'],
                'quantity' => '1',
                'quantity_unit' => 'normalized_position_unit',
                'fill_model' => 'observed_mark_normalized_notional_v1',
                'executed_at' => '2026-10-07T12:00:01.000Z',
            ],
            'position' => [
                'position_id' => $this->id(9),
                'state' => 'open',
                'cost_basis_native' => '0.1',
                'entry_market_cap_usd' => '12000',
                'entry_price_usd' => $command['entry']['price_usd'],
                'entry_liquidity_usd' => '3000.25',
                'lifecycle' => [
                    'policy_key' => 'laravel-paper-protection',
                    'policy_version' => 1,
                    'lifecycle_version' => 0,
                    'state' => 'open',
                ],
            ],
            'source' => $command['source'],
            'subject' => $command['subject'],
            'network' => $command['network'],
            'asset' => $command['asset'],
            'authority' => $command['authority'],
            'ledger' => [
                'opening_transaction_reference' => 'paper:wallet:opening:'.$command['subject']['control_plane_user_id'].':'.$command['network']['id'].':SOL:v1',
                'entry_transaction_id' => $this->id(12),
                'entry_transaction_reference' => 'paper:entry:'.$command['source']['engine_opportunity_id'].':v1',
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function event(
        string $eventId,
        string $type,
        string $aggregateId,
        string $status,
        array $payload = [],
        string $idempotencyKey = 'test-event',
        ?string $causationId = null,
    ): TradingEngineEvent {
        $payloadHash = app(TradingEngineCanonicalJson::class)->hash($payload);

        return TradingEngineEvent::query()->create([
            'event_id' => $eventId,
            'event_type' => $type,
            'schema_version' => 1,
            'occurred_at' => '2026-10-07T12:00:01.000Z',
            'producer' => 'trading-engine',
            'aggregate_type' => 'paper_position',
            'aggregate_id' => $aggregateId,
            'aggregate_version' => 1,
            'correlation_id' => 'entry-correlation',
            'causation_id' => $causationId ?? $aggregateId,
            'idempotency_key' => $idempotencyKey,
            'traceparent' => self::TRACEPARENT,
            'payload_sha256' => $payloadHash,
            'raw_body_sha256' => str_repeat('e', 64),
            'event_envelope' => [],
            'payload' => $payload,
            'handling_status' => $status,
            'handling_attempts' => 0,
            'received_at' => now(),
        ]);
    }

    private function id(int $sequence): string
    {
        return '01M5'.str_pad((string) $sequence, 22, '0', STR_PAD_LEFT);
    }
}
