<?php

namespace Tests\Feature;

use App\Exceptions\TradingEngineException;
use App\Jobs\SubmitTradingEnginePaperEntry;
use App\Jobs\SubmitTradingEnginePaperFinancialObservation;
use App\Models\TradingEnginePaperEntryIntent;
use App\Models\TradingEnginePaperFinancialObservation;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEnginePaperDeliveryTracker;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TradingEnginePaperDeliveryStateTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.paper_entry_integration_enabled' => true,
            'services.trading_engine.paper_financial_lifecycle_enabled' => true,
        ]);
    }

    public function test_engine_acceptance_followed_by_laravel_timeout_is_ambiguous(): void
    {
        $intent = $this->entryIntent();
        $job = new SubmitTradingEnginePaperEntry($intent->getKey());
        $timeout = new TradingEngineException(
            'TRANSPORT_TIMEOUT',
            'The acknowledgement may have been lost.',
            true,
        );
        $client = Mockery::mock(TradingEngineClient::class);
        $client->shouldReceive('executePaperEntry')
            ->once()
            ->andReturnUsing(function () use ($timeout): never {
                $this->assertSame(0, DB::transactionLevel());

                throw $timeout;
            });

        try {
            app()->call([$job, 'handle'], ['client' => $client]);
            $this->fail('Expected the timeout to be retried.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('TRANSPORT_TIMEOUT', $exception->errorCode);
        }

        $job->failed($timeout);
        $intent->refresh();

        $this->assertSame(TradingEnginePaperEntryIntent::STATUS_ACCEPTANCE_UNKNOWN, $intent->status);
        $this->assertSame(1, $intent->attempt_count);
        $this->assertNull($intent->definitively_rejected_at);
        $this->assertNotNull($intent->acceptance_unknown_at);
    }

    public function test_invalid_acknowledgement_is_ambiguous_but_explicit_rejection_is_terminal(): void
    {
        $ambiguous = $this->entryIntent('paper:entry:test:ambiguous:v1');
        $invalidResponse = new TradingEngineException('INVALID_RESPONSE', 'raw provider body', false, 202);
        $ambiguousClient = Mockery::mock(TradingEngineClient::class);
        $ambiguousClient->shouldReceive('executePaperEntry')->once()->andThrow($invalidResponse);

        (new SubmitTradingEnginePaperEntry($ambiguous->getKey()))->handle(
            $ambiguousClient,
            app(TradingEngineCanonicalJson::class),
            app(TradingEnginePaperDeliveryTracker::class),
        );

        $this->assertSame(
            TradingEnginePaperEntryIntent::STATUS_ACCEPTANCE_UNKNOWN,
            $ambiguous->fresh()->status,
        );

        $rejected = $this->entryIntent('paper:entry:test:rejected:v1');
        $validationFailure = new TradingEngineException(
            'VALIDATION_FAILED',
            'raw provider body',
            false,
            422,
            'VALIDATION_FAILED',
        );
        $rejectingClient = Mockery::mock(TradingEngineClient::class);
        $rejectingClient->shouldReceive('executePaperEntry')->once()->andThrow($validationFailure);

        (new SubmitTradingEnginePaperEntry($rejected->getKey()))->handle(
            $rejectingClient,
            app(TradingEngineCanonicalJson::class),
            app(TradingEnginePaperDeliveryTracker::class),
        );

        $this->assertSame(TradingEnginePaperEntryIntent::STATUS_FAILED, $rejected->fresh()->status);
        $this->assertNotNull($rejected->fresh()->definitively_rejected_at);
        $this->assertStringNotContainsString(
            'raw provider body',
            json_encode($rejected->fresh()->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_duplicate_and_stale_entry_attempts_cannot_overwrite_newer_acceptance(): void
    {
        $intent = $this->entryIntent();
        $tracker = app(TradingEnginePaperDeliveryTracker::class);
        $first = $tracker->beginEntryAttempt($intent);

        $this->assertSame(1, $first);
        $this->assertNull($tracker->beginEntryAttempt($intent));

        $this->travel(121)->seconds();
        $second = $tracker->beginEntryAttempt($intent);
        $this->assertSame(2, $second);
        $tracker->entryAcknowledged($intent, $this->entryAcknowledgement(), $second);
        $tracker->entryRetrying($intent, 'TRANSPORT_TIMEOUT', $first);
        $tracker->entryAcceptanceUnknown($intent, 'JOB_FAILED', $first);

        $intent->refresh();
        $this->assertSame(TradingEnginePaperEntryIntent::STATUS_SUBMITTED, $intent->status);
        $this->assertSame(2, $intent->attempt_count);
        $this->assertNull($intent->last_error_code);
    }

    public function test_financial_observation_attempts_are_fenced_the_same_way(): void
    {
        $observation = $this->financialObservation();
        $tracker = app(TradingEnginePaperDeliveryTracker::class);
        $attempt = $tracker->beginObservationAttempt($observation);
        $tracker->observationAcknowledged($observation, $this->observationAcknowledgement(), $attempt);
        $tracker->observationRetrying($observation, 'TRANSPORT_TIMEOUT', $attempt);
        $tracker->observationAcceptanceUnknown($observation, 'JOB_FAILED', $attempt);

        $observation->refresh();
        $this->assertSame(TradingEnginePaperFinancialObservation::STATUS_SUBMITTED, $observation->status);
        $this->assertSame('HOLD', $observation->engine_decision);
        $this->assertNull($observation->last_error_code);
    }

    public function test_stale_delivery_recovery_is_bounded_and_does_not_reopen_terminal_rows(): void
    {
        Queue::fake([
            SubmitTradingEnginePaperEntry::class,
            SubmitTradingEnginePaperFinancialObservation::class,
        ]);
        $intent = $this->entryIntent();
        $intent->forceFill(['updated_at' => now()->subMinutes(3)])->save();
        $tracker = app(TradingEnginePaperDeliveryTracker::class);

        $this->assertSame(1, $tracker->recoverEntries());
        $this->assertSame(0, $tracker->recoverEntries());
        Queue::assertPushed(SubmitTradingEnginePaperEntry::class, 1);

        $intent->forceFill([
            'status' => TradingEnginePaperEntryIntent::STATUS_PROJECTED,
            'updated_at' => now()->subMinutes(3),
        ])->save();
        $this->travel(121)->seconds();

        $this->assertSame(0, $tracker->recoverEntries());
        $this->assertSame(1, $intent->fresh()->recovery_count);
    }

    public function test_pre_remediation_serialized_paper_jobs_remain_executable(): void
    {
        $intent = $this->entryIntent();
        $observation = $this->financialObservation();
        $this->assertSame(1, $intent->getKey());
        $this->assertSame(1, $observation->getKey());

        $entryJob = unserialize(
            'O:38:"App\\Jobs\\SubmitTradingEnginePaperEntry":1:{s:13:"entryIntentId";i:1;}',
            ['allowed_classes' => [SubmitTradingEnginePaperEntry::class]],
        );
        $observationJob = unserialize(
            'O:53:"App\\Jobs\\SubmitTradingEnginePaperFinancialObservation":1:{s:13:"observationId";i:1;}',
            ['allowed_classes' => [SubmitTradingEnginePaperFinancialObservation::class]],
        );
        $client = Mockery::mock(TradingEngineClient::class);
        $client->shouldReceive('executePaperEntry')->once()->andReturn($this->entryAcknowledgement());
        $client->shouldReceive('observePaperFinancialPosition')
            ->once()
            ->andReturn($this->observationAcknowledgement());

        $this->assertInstanceOf(SubmitTradingEnginePaperEntry::class, $entryJob);
        $this->assertInstanceOf(SubmitTradingEnginePaperFinancialObservation::class, $observationJob);
        app()->call([$entryJob, 'handle'], ['client' => $client]);
        app()->call([$observationJob, 'handle'], ['client' => $client]);

        $this->assertSame(
            TradingEnginePaperEntryIntent::STATUS_SUBMITTED,
            $intent->fresh()->status,
        );
        $this->assertSame(
            TradingEnginePaperFinancialObservation::STATUS_SUBMITTED,
            $observation->fresh()->status,
        );
    }

    public function test_historical_sqlite_rows_survive_delivery_and_authority_migrations(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_26_104023_add_market_observation_authority_to_trading_engine_paper_position_states.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_26_102118_harden_trading_engine_paper_command_delivery.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $createdAt = now()->subDay()->startOfSecond();
        $entryPayload = ['schema_version' => 1, 'source' => ['historical' => true]];
        $observationPayload = ['schema_version' => 1, 'position_id' => $this->id(7)];
        Schema::disableForeignKeyConstraints();

        try {
            DB::table('trading_engine_paper_entry_intents')->insert([
                'id' => 91,
                'trade_opportunity_id' => 991,
                'opportunity_link_id' => 992,
                'opportunity_evaluation_id' => 993,
                'user_id' => 994,
                'idempotency_key' => 'paper:entry:historical:v1',
                'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($entryPayload),
                'payload' => json_encode($entryPayload, JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            DB::table('trading_engine_paper_position_states')->insert([
                'id' => 92,
                'position_projection_id' => 995,
                'lifecycle_version' => 0,
                'next_observation_sequence' => 1,
                'state' => 'open',
                'protection_state' => 'none',
                'peak_market_cap_usd' => '12000',
                'peak_multiple' => '1',
                'max_drawdown_percent' => '0',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            DB::table('trading_engine_paper_financial_observations')->insert([
                'id' => 93,
                'position_state_id' => 92,
                'user_id' => 994,
                'observation_id' => 'historical-observation',
                'sequence' => 1,
                'idempotency_key' => 'paper:financial:historical:v1',
                'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($observationPayload),
                'payload' => json_encode($observationPayload, JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_10_26_102118_harden_trading_engine_paper_command_delivery.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_10_26_104023_add_market_observation_authority_to_trading_engine_paper_position_states.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('trading_engine_paper_entry_intents', [
            'id' => 91,
            'idempotency_key' => 'paper:entry:historical:v1',
            'status' => TradingEnginePaperEntryIntent::STATUS_PENDING,
            'attempt_count' => 0,
        ]);
        $this->assertDatabaseHas('trading_engine_paper_financial_observations', [
            'id' => 93,
            'idempotency_key' => 'paper:financial:historical:v1',
            'status' => TradingEnginePaperFinancialObservation::STATUS_PENDING,
            'attempt_count' => 0,
        ]);
        $this->assertDatabaseHas('trading_engine_paper_position_states', [
            'id' => 92,
            'market_observation_authority' => 'laravel',
        ]);
        $this->assertSame($entryPayload, TradingEnginePaperEntryIntent::query()->findOrFail(91)->payload);
        $this->assertSame(
            $observationPayload,
            TradingEnginePaperFinancialObservation::query()->findOrFail(93)->payload,
        );
        $this->assertNotNull(TradingEnginePaperEntryIntent::query()->findOrFail(91)->queued_at);
        $this->assertNotNull(TradingEnginePaperFinancialObservation::query()->findOrFail(93)->queued_at);
    }

    public function test_tracking_and_database_queue_persistence_roll_back_together_on_interruption(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $realBus = app(Dispatcher::class);
        $interruptingBus = Mockery::mock(Dispatcher::class);
        $interruptingBus->shouldReceive('dispatch')->once()->andReturnUsing(
            function (object $job) use ($realBus): never {
                $realBus->dispatch($job);

                throw new RuntimeException('Simulated interruption before SQLite commit.');
            },
        );
        $tracker = new TradingEnginePaperDeliveryTracker($interruptingBus);
        Schema::disableForeignKeyConstraints();

        try {
            $tracker->queueEntry(fn (): TradingEnginePaperEntryIntent => $this->entryIntent(status: TradingEnginePaperEntryIntent::STATUS_PENDING));
            $this->fail('Expected the simulated interruption.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated interruption before SQLite commit.', $exception->getMessage());
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->assertDatabaseCount('trading_engine_paper_entry_intents', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function entryIntent(
        string $idempotencyKey = 'paper:entry:test:v1',
        string $status = TradingEnginePaperEntryIntent::STATUS_QUEUED,
    ): TradingEnginePaperEntryIntent {
        $payload = ['schema_version' => 1, 'source' => ['test' => true]];
        Schema::disableForeignKeyConstraints();
        $intent = TradingEnginePaperEntryIntent::query()->create([
            'trade_opportunity_id' => random_int(1000, 999999),
            'opportunity_link_id' => random_int(1000, 999999),
            'opportunity_evaluation_id' => random_int(1000, 999999),
            'user_id' => random_int(1000, 999999),
            'idempotency_key' => $idempotencyKey,
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($payload),
            'payload' => $payload,
            'status' => $status,
            'queued_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        return $intent;
    }

    private function financialObservation(): TradingEnginePaperFinancialObservation
    {
        $payload = ['schema_version' => 1, 'position_id' => '01M50000000000000000000007'];
        Schema::disableForeignKeyConstraints();
        $observation = TradingEnginePaperFinancialObservation::query()->create([
            'position_state_id' => random_int(1000, 999999),
            'user_id' => random_int(1000, 999999),
            'observation_id' => 'paper-observation-'.bin2hex(random_bytes(8)),
            'sequence' => 1,
            'idempotency_key' => 'paper:financial:test:'.bin2hex(random_bytes(8)).':v1',
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($payload),
            'payload' => $payload,
            'status' => TradingEnginePaperFinancialObservation::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        return $observation;
    }

    /** @return array<string, mixed> */
    private function entryAcknowledgement(): array
    {
        return [
            'operationId' => $this->id(1),
            'walletId' => $this->id(2),
            'intentId' => $this->id(3),
            'orderId' => $this->id(4),
            'fillId' => $this->id(5),
            'positionId' => $this->id(6),
            'eventId' => $this->id(7),
            'status' => 'accepted',
            'duplicate' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function observationAcknowledgement(): array
    {
        return [
            'operationId' => $this->id(8),
            'positionId' => $this->id(9),
            'decisionId' => $this->id(10),
            'eventId' => $this->id(11),
            'settlementId' => null,
            'decision' => 'HOLD',
            'duplicate' => false,
        ];
    }

    private function id(int $sequence): string
    {
        return '01M6'.str_pad((string) $sequence, 22, '0', STR_PAD_LEFT);
    }
}
