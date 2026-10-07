<?php

namespace Tests\Feature;

use App\Chain;
use App\Exceptions\TradingEngineException;
use App\Jobs\SubmitTradingEnginePaperObservation;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEnginePaperObservation;
use App\Models\TradingEnginePaperPositionLink;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEnginePaperLifecycleIntegration;
use App\Services\TradingEngine\TradingEnginePaperLifecycleProjector;
use App\Services\TradingEngine\TradingEnginePaperObservationRecovery;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class TradingEnginePaperObservationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
        ]);
    }

    public function test_command_recovers_only_the_explicit_failed_observation_with_its_immutable_identity(): void
    {
        [$position, $link, $observation] = $this->failedObservation();
        $originalPayload = $observation->payload;
        $originalHash = $observation->payload_sha256;
        $originalIdempotencyKey = $observation->idempotency_key;

        $this->artisan('trading-engine:recover-paper-observation', [
            'observation-id' => $observation->getKey(),
            '--expected-error' => 'UNEXPECTED_RESPONSE',
        ])->assertSuccessful()
            ->expectsOutputToContain('The existing PAPER observation was authorized and dispatched');

        $observation->refresh();
        $this->assertSame('pending', $observation->status);
        $this->assertNull($observation->error_code);
        $this->assertSame('UNEXPECTED_RESPONSE', $observation->recovery_previous_error_code);
        $this->assertNotNull($observation->recovery_token);
        $this->assertNotNull($observation->recovery_requested_at);
        $this->assertSame($originalPayload, $observation->payload);
        $this->assertSame($originalHash, $observation->payload_sha256);
        $this->assertSame($originalIdempotencyKey, $observation->idempotency_key);
        $this->assertSame(172, $link->fresh()->next_observation_sequence);
        $this->assertDatabaseCount('trading_engine_paper_observations', 1);

        Queue::assertPushed(SubmitTradingEnginePaperObservation::class, function (SubmitTradingEnginePaperObservation $job) use ($observation, $originalPayload, $originalIdempotencyKey): bool {
            return $job->observationId === $observation->getKey()
                && $job->payload === $originalPayload
                && $job->idempotencyKey === $originalIdempotencyKey
                && $job->recoveryToken === $observation->recovery_token;
        });
        $this->assertSame($position->getKey(), $observation->paper_position_id);
    }

    public function test_recovery_resumes_after_committed_state_loses_dispatch_and_rotates_its_fencing_token(): void
    {
        [, $link, $observation] = $this->failedObservation();
        $immutable = $observation->only([
            'id',
            'observation_id',
            'observation_sequence',
            'payload',
            'payload_sha256',
            'idempotency_key',
        ]);
        $lostJob = null;
        $lostDispatcher = Mockery::mock(Dispatcher::class);
        $lostDispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (SubmitTradingEnginePaperObservation $job) use (&$lostJob): bool {
                $lostJob = $job;

                return true;
            })
            ->andReturnUsing(fn (SubmitTradingEnginePaperObservation $job): SubmitTradingEnginePaperObservation => $job);
        $recovery = new TradingEnginePaperObservationRecovery(
            $lostDispatcher,
            app(TradingEngineCanonicalJson::class),
        );

        $first = $recovery->recover($observation->getKey(), 'UNEXPECTED_RESPONSE');
        $observation->refresh();
        $firstToken = $observation->recovery_token;

        $this->assertSame('dispatched', $first['status']);
        $this->assertNotNull($firstToken);
        $this->assertInstanceOf(SubmitTradingEnginePaperObservation::class, $lostJob);
        Queue::assertNothingPushed();

        $this->artisan('trading-engine:recover-paper-observation', [
            'observation-id' => $observation->getKey(),
            '--expected-error' => 'UNEXPECTED_RESPONSE',
        ])->assertSuccessful()
            ->expectsOutputToContain('The existing PAPER observation was authorized and dispatched');

        $observation->refresh();
        $this->assertNotNull($observation->recovery_token);
        $this->assertNotSame($firstToken, $observation->recovery_token);
        $this->assertSame($immutable, $observation->only(array_keys($immutable)));
        $this->assertDatabaseCount('trading_engine_paper_observations', 1);
        $this->assertSame(172, $link->fresh()->next_observation_sequence);
        Queue::assertPushed(SubmitTradingEnginePaperObservation::class, function (SubmitTradingEnginePaperObservation $job) use ($observation): bool {
            return $job->observationId === $observation->getKey()
                && $job->payload === $observation->payload
                && $job->idempotencyKey === $observation->idempotency_key
                && $job->recoveryToken === $observation->recovery_token;
        });

        $client = $this->mock(TradingEngineClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('observePaperPosition');
        });
        $lostJob->handle($client, app(TradingEngineCanonicalJson::class));
        $lostJob->failed(new RuntimeException('stale fenced job exhausted after recovery resumed'));
        $this->assertSame(0, $observation->fresh()->submission_attempt_count);
        $this->assertSame($observation->recovery_token, $observation->fresh()->recovery_token);
    }

    public function test_normal_pending_observation_without_recovery_provenance_is_not_recoverable(): void
    {
        [, , $observation] = $this->failedObservation();
        $observation->forceFill([
            'status' => 'pending',
            'error_code' => null,
            'recovery_token' => null,
            'recovery_previous_error_code' => null,
            'recovery_requested_at' => null,
        ])->save();

        $result = app(TradingEnginePaperObservationRecovery::class)->recover(
            $observation->getKey(),
            'UNEXPECTED_RESPONSE',
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('PAPER_OBSERVATION_RECOVERY_PROVENANCE_INVALID', $result['error_code']);
        $this->assertSame('pending', $observation->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_matching_submitted_recovery_reports_already_processed_without_dispatch_or_mutation(): void
    {
        [, , $observation] = $this->failedObservation();
        app(TradingEnginePaperObservationRecovery::class)->recover(
            $observation->getKey(),
            'UNEXPECTED_RESPONSE',
        );
        $observation->forceFill([
            'status' => 'submitted',
            'engine_decision_id' => (string) Str::ulid(),
            'engine_decision' => 'HOLD',
            'submitted_at' => now(),
            'recovered_at' => now(),
            'recovery_token' => null,
        ])->save();
        Queue::fake();
        $before = $observation->fresh()->getAttributes();

        $this->artisan('trading-engine:recover-paper-observation', [
            'observation-id' => $observation->getKey(),
            '--expected-error' => 'UNEXPECTED_RESPONSE',
        ])->assertSuccessful()
            ->expectsOutputToContain('already submitted or evaluated; no job was dispatched');

        $this->assertSame($before, $observation->fresh()->getAttributes());
        Queue::assertNothingPushed();
    }

    public function test_inbound_evaluation_completes_recovery_and_a_later_command_is_an_idempotent_noop(): void
    {
        [$position, $link, $observation] = $this->failedObservation(1);
        app(TradingEnginePaperObservationRecovery::class)->recover(
            $observation->getKey(),
            'UNEXPECTED_RESPONSE',
        );
        $observation->refresh();
        $this->assertNotNull($observation->recovery_token);

        app(TradingEnginePaperLifecycleProjector::class)->project(
            $this->evaluatedEvent($position, $link, $observation),
        );

        $observation->refresh();
        $this->assertSame('evaluated', $observation->status);
        $this->assertNull($observation->recovery_token);
        $this->assertNotNull($observation->recovered_at);
        $this->assertNotNull($observation->engine_decision_id);
        $this->assertSame('HOLD', $observation->engine_decision);
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 1);
        Queue::fake();
        $before = $observation->getAttributes();

        $this->artisan('trading-engine:recover-paper-observation', [
            'observation-id' => $observation->getKey(),
            '--expected-error' => 'UNEXPECTED_RESPONSE',
        ])->assertSuccessful()
            ->expectsOutputToContain('already submitted or evaluated; no job was dispatched');

        $this->assertSame($before, $observation->fresh()->getAttributes());
        $this->assertDatabaseCount('trading_engine_paper_lifecycle_decisions', 1);
        Queue::assertNothingPushed();
    }

    public function test_stale_generic_queue_retry_cannot_mutate_failed_or_authorized_recovery_rows(): void
    {
        [, , $observation] = $this->failedObservation();
        $client = $this->mock(TradingEngineClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('observePaperPosition');
        });
        $canonicalJson = app(TradingEngineCanonicalJson::class);
        $staleJob = new SubmitTradingEnginePaperObservation(
            $observation->getKey(),
            $observation->payload,
            $observation->idempotency_key,
        );

        $staleJob->handle($client, $canonicalJson);
        $this->assertSame('failed', $observation->fresh()->status);

        app(TradingEnginePaperObservationRecovery::class)->recover(
            $observation->getKey(),
            'UNEXPECTED_RESPONSE',
        );
        $authorized = $observation->fresh();
        $this->assertSame('pending', $authorized->status);
        $this->assertNotNull($authorized->recovery_token);

        $staleJob->handle($client, $canonicalJson);
        $staleJob->failed(new TradingEngineException('UNEXPECTED_RESPONSE', 'stale retry'));

        $authorized->refresh();
        $this->assertSame('pending', $authorized->status);
        $this->assertNotNull($authorized->recovery_token);
        $this->assertSame(0, $authorized->submission_attempt_count);
    }

    public function test_out_of_order_later_sequence_payload_tampering_and_ownership_mismatch_fail_closed(): void
    {
        [, $link, $outOfOrder] = $this->failedObservation();
        $link->forceFill(['next_observation_sequence' => 173])->save();
        $this->assertBlocked($outOfOrder, 'PAPER_OBSERVATION_SEQUENCE_NOT_RECOVERABLE');

        [, , $tampered] = $this->failedObservation();
        $payload = $tampered->payload;
        $payload['market']['market_cap_usd'] = '999999';
        $tampered->forceFill(['payload' => $payload])->save();
        $this->assertBlocked($tampered, 'PAPER_OBSERVATION_PAYLOAD_HASH_MISMATCH');

        [, $wrongOwnerLink, $wrongOwner] = $this->failedObservation();
        $wrongOwnerLink->forceFill([
            'ownership_snapshot' => ['owner' => 'laravel', 'authoritative' => false],
        ])->save();
        $this->assertBlocked($wrongOwner, 'PAPER_OBSERVATION_IDENTITY_MISMATCH');

        [$terminalPosition, , $terminalObservation] = $this->failedObservation();
        $terminalPosition->forceFill(['status' => 'closed'])->save();
        $this->assertBlocked($terminalObservation, 'PAPER_OBSERVATION_IDENTITY_MISMATCH');

        Queue::assertNothingPushed();
    }

    public function test_later_observation_prevents_recovery(): void
    {
        [$position, $link, $observation] = $this->failedObservation();
        $laterPayload = $this->observationPayload($position, $link, 172);
        TradingEnginePaperObservation::query()->create([
            'position_link_id' => $link->getKey(),
            'paper_position_id' => $position->getKey(),
            'observation_id' => $laterPayload['source']['observation_id'],
            'observation_sequence' => 172,
            'payload' => $laterPayload,
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($laterPayload),
            'idempotency_key' => 'paper:position:observe:laravel:'.$position->getKey().':172:v1',
            'status' => 'submitted',
        ]);

        $this->assertBlocked($observation, 'PAPER_OBSERVATION_LATER_SEQUENCE_EXISTS');
        Queue::assertNothingPushed();
    }

    public function test_dispatch_failure_restores_a_failed_recoverable_state(): void
    {
        [, , $observation] = $this->failedObservation();
        $dispatcher = $this->mock(Dispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('queue connection detail must not persist'));
        });
        $recovery = new TradingEnginePaperObservationRecovery(
            $dispatcher,
            app(TradingEngineCanonicalJson::class),
        );

        $result = $recovery->recover($observation->getKey(), 'UNEXPECTED_RESPONSE');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('PAPER_OBSERVATION_RECOVERY_DISPATCH_FAILED', $result['error_code']);
        $observation->refresh();
        $this->assertSame('failed', $observation->status);
        $this->assertSame('OBSERVATION_DISPATCH_FAILED', $observation->error_code);
        $this->assertNull($observation->recovery_token);
        $this->assertStringNotContainsString(
            'queue connection detail must not persist',
            json_encode($observation->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_recovery_requires_an_exact_recoverable_error_and_all_authority_gates(): void
    {
        [, , $observation] = $this->failedObservation();
        $recovery = app(TradingEnginePaperObservationRecovery::class);

        $mismatch = $recovery->recover($observation->getKey(), 'TRANSPORT_TIMEOUT');
        $this->assertSame('PAPER_OBSERVATION_EXPECTED_ERROR_MISMATCH', $mismatch['error_code']);

        $observation->forceFill(['error_code' => 'VALIDATION_FAILED'])->save();
        $unsupported = $recovery->recover($observation->getKey(), 'VALIDATION_FAILED');
        $this->assertSame('PAPER_OBSERVATION_ERROR_NOT_RECOVERABLE', $unsupported['error_code']);

        $observation->forceFill(['error_code' => 'UNEXPECTED_RESPONSE'])->save();
        config()->set('services.trading_engine.paper_lifecycle_authoritative_enabled', false);
        $disabled = $recovery->recover($observation->getKey(), 'UNEXPECTED_RESPONSE');
        $this->assertSame('PAPER_OBSERVATION_RECOVERY_DISABLED', $disabled['error_code']);

        $this->assertSame('failed', $observation->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_successful_recovery_converges_the_same_row_for_new_and_duplicate_engine_acceptance(): void
    {
        foreach ([false, true] as $duplicate) {
            Queue::fake();
            [, $link, $observation] = $this->failedObservation();
            $originalPayload = $observation->payload;
            $originalHash = $observation->payload_sha256;
            $originalKey = $observation->idempotency_key;
            $job = $this->authorizedRecoveryJob($observation);
            $decisionId = (string) Str::ulid();
            $client = $this->mock(TradingEngineClient::class, function (MockInterface $mock) use ($observation, $decisionId, $duplicate): void {
                $mock->shouldReceive('observePaperPosition')
                    ->once()
                    ->withArgs(function (string $key, array $payload, string $correlationId) use ($observation): bool {
                        return $key === $observation->idempotency_key
                            && $payload === $observation->payload
                            && Str::isUuid($correlationId);
                    })
                    ->andReturn([
                        'operationId' => (string) Str::ulid(),
                        'positionId' => $observation->link->engine_position_id,
                        'decisionId' => $decisionId,
                        'eventId' => (string) Str::ulid(),
                        'decision' => 'HOLD',
                        'duplicate' => $duplicate,
                    ]);
            });

            $job->handle($client, app(TradingEngineCanonicalJson::class));

            $observation->refresh();
            $this->assertSame('submitted', $observation->status);
            $this->assertSame($decisionId, $observation->engine_decision_id);
            $this->assertSame('HOLD', $observation->engine_decision);
            $this->assertSame(1, $observation->submission_attempt_count);
            $this->assertSame(202, $observation->last_response_status);
            $this->assertNull($observation->last_engine_error_code);
            $this->assertNull($observation->recovery_token);
            $this->assertNotNull($observation->last_correlation_id);
            $this->assertNotNull($observation->last_attempted_at);
            $this->assertNotNull($observation->submitted_at);
            $this->assertNotNull($observation->recovered_at);
            $this->assertSame($originalPayload, $observation->payload);
            $this->assertSame($originalHash, $observation->payload_sha256);
            $this->assertSame($originalKey, $observation->idempotency_key);
            $this->assertSame(172, $link->fresh()->next_observation_sequence);
            $this->assertDatabaseCount('trading_engine_paper_observations', $duplicate ? 2 : 1);
        }
    }

    public function test_failure_diagnostics_store_only_safe_structured_metadata(): void
    {
        [, , $observation] = $this->failedObservation();
        $job = $this->authorizedRecoveryJob($observation);
        $client = $this->mock(TradingEngineClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('observePaperPosition')
                ->once()
                ->andThrow(new TradingEngineException(
                    'REQUEST_CONFLICT',
                    'remote secret must not be persisted',
                    false,
                    409,
                    'PAPER_OBSERVATION_OUT_OF_ORDER',
                ));
        });

        $job->handle($client, app(TradingEngineCanonicalJson::class));

        $observation->refresh();
        $this->assertSame('failed', $observation->status);
        $this->assertSame('REQUEST_CONFLICT', $observation->error_code);
        $this->assertSame(409, $observation->last_response_status);
        $this->assertSame('PAPER_OBSERVATION_OUT_OF_ORDER', $observation->last_engine_error_code);
        $this->assertSame(1, $observation->submission_attempt_count);
        $this->assertNotNull($observation->last_correlation_id);
        $this->assertNotNull($observation->last_attempted_at);
        $this->assertNull($observation->recovery_token);
        $this->assertStringNotContainsString(
            'remote secret must not be persisted',
            json_encode($observation->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_each_authorized_attempt_clears_stale_response_diagnostics_before_calling_the_client(): void
    {
        [, , $observation] = $this->failedObservation();
        $job = $this->authorizedRecoveryJob($observation);
        $firstClient = $this->mock(TradingEngineClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('observePaperPosition')
                ->once()
                ->andThrow(new TradingEngineException(
                    'DEPENDENCY_UNAVAILABLE',
                    'temporary dependency failure',
                    true,
                    503,
                    'DATABASE_UNAVAILABLE',
                ));
        });

        try {
            $job->handle($firstClient, app(TradingEngineCanonicalJson::class));
            $this->fail('A retryable engine failure must be rethrown.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('DEPENDENCY_UNAVAILABLE', $exception->errorCode);
        }

        $observation->refresh();
        $firstCorrelationId = $observation->last_correlation_id;
        $this->assertSame(1, $observation->submission_attempt_count);
        $this->assertSame(503, $observation->last_response_status);
        $this->assertSame('DATABASE_UNAVAILABLE', $observation->last_engine_error_code);

        $secondClient = $this->mock(TradingEngineClient::class, function (MockInterface $mock) use ($observation): void {
            $mock->shouldReceive('observePaperPosition')
                ->once()
                ->withArgs(function () use ($observation): bool {
                    $current = $observation->fresh();
                    $this->assertSame(2, $current->submission_attempt_count);
                    $this->assertNull($current->last_response_status);
                    $this->assertNull($current->last_engine_error_code);

                    return true;
                })
                ->andThrow(new RuntimeException('connection terminated before an HTTP response'));
        });

        try {
            $job->handle($secondClient, app(TradingEngineCanonicalJson::class));
            $this->fail('The unexpected transport failure must remain visible to the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('connection terminated before an HTTP response', $exception->getMessage());
        }

        $observation->refresh();
        $this->assertSame(2, $observation->submission_attempt_count);
        $this->assertNotSame($firstCorrelationId, $observation->last_correlation_id);
        $this->assertNull($observation->last_response_status);
        $this->assertNull($observation->last_engine_error_code);
        $this->assertNotNull($observation->recovery_token);
    }

    public function test_normal_pending_observation_submission_remains_unchanged(): void
    {
        [, , $observation] = $this->failedObservation();
        $observation->forceFill([
            'status' => 'pending',
            'error_code' => null,
        ])->save();
        $decisionId = (string) Str::ulid();
        $client = $this->mock(TradingEngineClient::class, function (MockInterface $mock) use ($observation, $decisionId): void {
            $mock->shouldReceive('observePaperPosition')
                ->once()
                ->andReturn([
                    'operationId' => (string) Str::ulid(),
                    'positionId' => $observation->link->engine_position_id,
                    'decisionId' => $decisionId,
                    'eventId' => (string) Str::ulid(),
                    'decision' => 'HOLD',
                    'duplicate' => false,
                ]);
        });

        (new SubmitTradingEnginePaperObservation(
            $observation->getKey(),
            $observation->payload,
            $observation->idempotency_key,
        ))->handle($client, app(TradingEngineCanonicalJson::class));

        $observation->refresh();
        $this->assertSame('submitted', $observation->status);
        $this->assertSame($decisionId, $observation->engine_decision_id);
        $this->assertSame(1, $observation->submission_attempt_count);
        $this->assertNull($observation->recovered_at);
    }

    public function test_failed_observation_continues_to_block_the_next_sequence(): void
    {
        [$position, $link] = $this->failedObservation();
        $nextMarketObservation = [
            'market_cap' => 11000,
            'price_usd' => 0.0011,
            'liquidity_usd' => 1000,
            'checked_at' => now()->toISOString(),
            'provider_observed_at' => now()->toISOString(),
            'fetched_at' => now()->toISOString(),
            'provider' => 'dexscreener',
        ];

        app(TradingEnginePaperLifecycleIntegration::class)->submitValidated(
            $position,
            $link,
            $nextMarketObservation,
        );

        $this->assertDatabaseCount('trading_engine_paper_observations', 1);
        $this->assertSame(172, $link->fresh()->next_observation_sequence);
        Queue::assertNothingPushed();
    }

    public function test_recovery_diagnostics_migration_preserves_existing_sqlite_rows_across_rollback_and_forward(): void
    {
        [, , $observation] = $this->failedObservation();
        $before = (array) DB::table('trading_engine_paper_observations')
            ->where('id', $observation->getKey())
            ->first();
        $migration = require database_path('migrations/2026_10_07_120000_add_recovery_diagnostics_to_trading_engine_paper_observations_table.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('trading_engine_paper_observations', 'recovery_token'));
        $afterRollback = (array) DB::table('trading_engine_paper_observations')
            ->where('id', $observation->getKey())
            ->first();
        $this->assertSame($before['status'], $afterRollback['status']);
        $this->assertSame($before['error_code'], $afterRollback['error_code']);
        $this->assertSame($before['payload'], $afterRollback['payload']);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('trading_engine_paper_observations', 'recovery_token'));
        $afterForward = (array) DB::table('trading_engine_paper_observations')
            ->where('id', $observation->getKey())
            ->first();
        $this->assertSame($before['status'], $afterForward['status']);
        $this->assertSame($before['error_code'], $afterForward['error_code']);
        $this->assertSame($before['payload'], $afterForward['payload']);
        $this->assertSame(0, $afterForward['submission_attempt_count']);
    }

    private function assertBlocked(TradingEnginePaperObservation $observation, string $errorCode): void
    {
        $result = app(TradingEnginePaperObservationRecovery::class)->recover(
            $observation->getKey(),
            'UNEXPECTED_RESPONSE',
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame($errorCode, $result['error_code']);
        $this->assertSame('failed', $observation->fresh()->status);
    }

    private function authorizedRecoveryJob(TradingEnginePaperObservation $observation): SubmitTradingEnginePaperObservation
    {
        app(TradingEnginePaperObservationRecovery::class)->recover(
            $observation->getKey(),
            'UNEXPECTED_RESPONSE',
        );
        $observation->refresh();

        return new SubmitTradingEnginePaperObservation(
            $observation->getKey(),
            $observation->payload,
            $observation->idempotency_key,
            $observation->recovery_token,
        );
    }

    /** @return array{PaperPosition, TradingEnginePaperPositionLink, TradingEnginePaperObservation} */
    private function failedObservation(int $sequence = 171): array
    {
        $user = User::factory()->create();
        $opportunity = TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'address' => 'So'.Str::lower(Str::random(42)),
            'scanner' => 'new-token',
        ]);
        $position = PaperPosition::query()->create([
            'user_id' => $user->getKey(),
            'chain' => Chain::Solana,
            'address' => $opportunity->address,
            'symbol' => 'RECOVER',
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
        $link = TradingEnginePaperPositionLink::query()->create([
            'paper_position_id' => $position->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'user_id' => $user->getKey(),
            'engine_opportunity_id' => (string) Str::ulid(),
            'engine_position_id' => (string) Str::ulid(),
            'chain' => Chain::Solana->value,
            'asset_address' => $position->address,
            'ownership_state' => 'registered',
            'next_observation_sequence' => $sequence + 1,
            'ownership_snapshot' => ['owner' => 'trading-engine', 'authoritative' => true],
            'registration_payload' => ['immutable' => true],
            'registration_payload_sha256' => app(TradingEngineCanonicalJson::class)->hash(['immutable' => true]),
            'registration_idempotency_key' => 'paper:position:record:laravel:'.$position->getKey().':v1',
            'registered_at' => now(),
        ]);
        $payload = $this->observationPayload($position, $link, $sequence);
        $observation = TradingEnginePaperObservation::query()->create([
            'position_link_id' => $link->getKey(),
            'paper_position_id' => $position->getKey(),
            'observation_id' => $payload['source']['observation_id'],
            'observation_sequence' => $sequence,
            'payload' => $payload,
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($payload),
            'idempotency_key' => 'paper:position:observe:laravel:'.$position->getKey().':'.$sequence.':v1',
            'status' => 'failed',
            'error_code' => 'UNEXPECTED_RESPONSE',
        ]);

        return [$position, $link, $observation];
    }

    private function evaluatedEvent(
        PaperPosition $position,
        TradingEnginePaperPositionLink $link,
        TradingEnginePaperObservation $observation,
    ): TradingEngineEvent {
        $payload = [
            'position_id' => $link->engine_position_id,
            'decision_id' => (string) Str::ulid(),
            'source' => $observation->payload['source'],
            'subject' => $observation->payload['subject'],
            'network' => $observation->payload['network'],
            'asset' => $observation->payload['asset'],
            'policy' => ['key' => 'laravel-paper-protection', 'version' => 1],
            'lifecycle_version' => $observation->observation_sequence,
            'decision' => 'HOLD',
            'exit_type' => null,
            'observed_multiple' => '1',
            'trigger_multiple' => null,
            'market' => $observation->payload['market'],
            'peak_market_cap_usd' => '10000',
            'peak_multiple' => '1',
            'drawdown_percent' => '0',
            'protection_before' => 'none',
            'protection_after' => 'none',
            'transitions' => [],
        ];
        $eventId = (string) Str::ulid();

        return TradingEngineEvent::query()->create([
            'event_id' => $eventId,
            'event_type' => 'paper.position.evaluated.v1',
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => 'paper_position',
            'aggregate_id' => $link->engine_position_id,
            'aggregate_version' => $observation->observation_sequence,
            'correlation_id' => 'paper-observation-recovery-test',
            'causation_id' => (string) Str::ulid(),
            'idempotency_key' => $observation->idempotency_key,
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'payload_sha256' => app(TradingEngineCanonicalJson::class)->hash($payload),
            'raw_body_sha256' => hash('sha256', $eventId),
            'event_envelope' => [],
            'payload' => $payload,
            'handling_status' => TradingEngineEvent::STATUS_STORED,
            'handling_attempts' => 0,
            'received_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function observationPayload(
        PaperPosition $position,
        TradingEnginePaperPositionLink $link,
        int $sequence,
    ): array {
        $timestamp = now()->toISOString();

        return [
            'schema_version' => 1,
            'position_id' => $link->engine_position_id,
            'source' => [
                'paper_position_id' => (string) $position->getKey(),
                'observation_id' => 'paper-position-'.$position->getKey().'-observation-'.$sequence,
                'sequence' => $sequence,
            ],
            'subject' => ['control_plane_user_id' => (string) $position->user_id],
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'asset' => ['address' => $position->address],
            'market' => [
                'market_cap_usd' => '10000',
                'price_usd' => '0.001',
                'liquidity_usd' => '1000',
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
}
