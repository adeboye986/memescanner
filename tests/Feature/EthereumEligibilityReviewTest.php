<?php

namespace Tests\Feature;

use App\Models\EthereumAccountingEligibility;
use App\Models\EthereumAccountingReviewHead;
use App\Models\User;
use App\Services\EthereumAccountingReviewerAllowlist;
use App\Services\EthereumEligibilityObservationCollector;
use App\Services\EthereumEligibilityReviewService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthereumEligibilityReviewTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0x1111111111111111111111111111111111111111';

    #[DataProvider('unauthorizedActors')]
    public function test_unauthorized_actor_cannot_publish(string $kind): void
    {
        Http::preventStrayRequests();
        if ($kind !== 'guest') {
            $user = User::factory()->create(['is_admin' => $kind !== 'customer']);
            $this->actingAs($user);
            config(['services.ethereum.accounting.reviewer_ids' => $kind === 'customer' ? [(string) $user->id] : ($kind === 'unlisted' ? ['999999'] : [])]);
        }
        $this->expectException(AuthorizationException::class);
        app(EthereumEligibilityReviewService::class)->publish([]);
    }

    public static function unauthorizedActors(): array
    {
        return [['guest'], ['customer'], ['unlisted'], ['empty']];
    }

    public function test_authorized_approval_records_attributed_bound_evidence(): void
    {
        $actor = $this->reviewer();
        $observation = $this->observation();
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->once()->with(self::TOKEN, 'server-observation-1')->andReturn($observation);
        $input = $this->input($actor, 'approved', $observation);
        $review = app(EthereumEligibilityReviewService::class)->publish($input);
        $this->assertSame('approved', $review->status);
        $this->assertEquals($actor->id, $review->reviewer_user_id);
        $this->assertSame(['id' => (string) $actor->id, 'name' => $actor->name, 'email' => $actor->email], $review->reviewer_identity);
        $this->assertSame($observation['code_sha256'], $review->code_sha256);
        $this->assertSame($observation, $review->fresh()->review_evidence['observation']);
        $this->assertSame($input['evidence_digest'], $review->evidence_digest);
        $this->assertSame(EthereumEligibilityReviewService::FORMAT, $review->review_format_version);
        $this->assertEquals($review->id, EthereumAccountingReviewHead::query()->sole()->current_review_id);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidSubmissions')]
    public function test_invalid_or_forged_submission_is_rejected(string $path, mixed $value): void
    {
        $actor = $this->reviewer();
        $input = $this->input($actor, 'approved', $this->observation());
        data_set($input, $path, $value);
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldNotReceive('collect');
        try {
            app(EthereumEligibilityReviewService::class)->publish($input);
            $this->fail('Malformed review accepted.');
        } catch (DomainException) {
            $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
            $this->assertDatabaseCount('ethereum_accounting_review_heads', 0);
        }
    }

    public static function invalidSubmissions(): array
    {
        return [
            ['reviewer_user_id', 123], ['code_sha256', str_repeat('a', 64)], ['chain', 'solana'], ['chain_id', 137],
            ['chain_id', '1'], ['token_address', '0xABC'], ['policy_version', 'unknown'], ['decision', 'unsupported'],
            ['assertions.source_reference', ''], ['assertions.source_sha256', 'bad'], ['assertions.historical_applicability', ''],
            ['assertions.non_proxy', false], ['assertions.non_proxy', 'true'], ['assertions.standard_transfer_accounting', false],
            ['assertions.no_mutable_balance_behavior', false], ['rationale', str_repeat('x', 256)], ['observation_reference', null],
            ['expected_review_id', '1'], ['expected_version', -1], ['submission_id', 'bad'], ['evidence_digest', 'bad'],
        ];
    }

    #[DataProvider('invalidObservations')]
    public function test_invalid_trusted_binding_fails_closed(string $path, mixed $value): void
    {
        $actor = $this->reviewer();
        $observation = $this->observation();
        data_set($observation, $path, $value);
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->once()->andReturn($observation);
        $this->expectException(DomainException::class);
        app(EthereumEligibilityReviewService::class)->publish($this->input($actor, 'approved', $observation));
    }

    public static function invalidObservations(): array
    {
        return [['chain_id', 137], ['token_address', '0x'.str_repeat('2', 40)], ['code_sha256', 'bad'],
            ['block_number', null], ['block_hash', null], ['collected_at', '2999-01-01T00:00:00Z']];
    }

    public function test_missing_collector_fails_closed_but_rejection_needs_no_provider(): void
    {
        $actor = $this->reviewer();
        try {
            app(EthereumEligibilityReviewService::class)->publish($this->input($actor, 'approved', $this->observation()));
            $this->fail('Unavailable collector approved a token.');
        } catch (DomainException) {
            $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
        }
        $review = app(EthereumEligibilityReviewService::class)->publish($this->input($actor));
        $this->assertSame('rejected', $review->status);
        $this->assertNull($review->code_sha256);
        Http::assertNothingSent();
    }

    public function test_approval_rejection_and_new_approval_append_history(): void
    {
        $actor = $this->reviewer();
        $observation = $this->observation();
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->twice()->andReturn($observation);
        $service = app(EthereumEligibilityReviewService::class);
        $first = $service->publish($this->input($actor, 'approved', $observation));
        $original = $first->fresh()->getRawOriginal();
        $second = $service->publish($this->input($actor, 'rejected', null, $first->id, 1));
        $third = $service->publish($this->input($actor, 'approved', $observation, $second->id, 2));
        $this->assertEquals($first->id, $second->supersedes_review_id);
        $this->assertEquals($second->id, $third->supersedes_review_id);
        $this->assertSame($original, $first->fresh()->getRawOriginal());
        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertEquals(3, EthereumAccountingReviewHead::query()->sole()->version);
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 3);
    }

    public function test_duplicate_retry_is_idempotent_even_after_supersession_and_provider_outage(): void
    {
        $actor = $this->reviewer();
        $observation = $this->observation();
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->once()->andReturn($observation);
        $input = $this->input($actor, 'approved', $observation);
        $service = app(EthereumEligibilityReviewService::class);
        $first = $service->publish($input);
        $second = $service->publish($this->input($actor, 'rejected', null, $first->id, 1));
        $this->assertSame($first->id, $service->publish($input)->id);
        $this->assertEquals($second->id, EthereumAccountingReviewHead::query()->sole()->current_review_id);
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 2);
    }

    #[DataProvider('duplicateChanges')]
    public function test_conflicting_duplicate_cannot_reuse_submission(string $change): void
    {
        $actor = $this->reviewer();
        $input = $this->input($actor);
        app(EthereumEligibilityReviewService::class)->publish($input);
        if ($change === 'actor') {
            $actor = $this->reviewer();
        } else {
            $input[$change] = match ($change) {
                'token_address' => '0x'.str_repeat('2', 40), 'expected_version' => 1,
                default => 'changed rationale',
            };
        }
        $input['evidence_digest'] = EthereumEligibilityReviewService::digest($actor->id, $input, null);
        $this->expectException(DomainException::class);
        app(EthereumEligibilityReviewService::class)->publish($input);
    }

    public static function duplicateChanges(): array
    {
        return [['actor'], ['token_address'], ['rationale'], ['expected_version']];
    }

    public function test_wrong_confirmation_digest_cannot_publish(): void
    {
        $actor = $this->reviewer();
        $input = $this->input($actor);
        $input['evidence_digest'] = str_repeat('0', 64);
        $this->expectException(DomainException::class);
        app(EthereumEligibilityReviewService::class)->publish($input);
    }

    public function test_competing_first_reviews_cannot_both_commit_from_empty_snapshot(): void
    {
        $actor = $this->reviewer();
        $observation = $this->observation();
        $loser = $this->input($actor, 'approved', $observation);
        $winner = $this->input($actor);
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->once()->andReturnUsing(function () use ($winner, $observation): array {
            app(EthereumEligibilityReviewService::class)->publish($winner);

            return $observation;
        });
        try {
            app(EthereumEligibilityReviewService::class)->publish($loser);
            $this->fail('Stale first review committed.');
        } catch (DomainException) {
            $this->assertDatabaseCount('ethereum_accounting_review_heads', 1);
            $this->assertDatabaseCount('ethereum_accounting_eligibilities', 1);
            $this->assertSame('rejected', EthereumAccountingEligibility::query()->sole()->status);
        }
    }

    public function test_unique_head_prevents_duplicate_token_coordination_rows(): void
    {
        DB::transaction(fn () => EthereumAccountingReviewHead::locked(self::TOKEN));
        $this->expectException(QueryException::class);
        EthereumAccountingReviewHead::query()->create(['chain' => 'ethereum', 'token_address' => self::TOKEN]);
    }

    public function test_legacy_latest_review_is_adopted_without_fabricated_provenance(): void
    {
        $actor = $this->reviewer();
        $legacy = EthereumAccountingEligibility::query()->create(['chain' => 'ethereum', 'token_address' => self::TOKEN,
            'policy_version' => EthereumAccountingEligibility::POLICY, 'status' => 'unsupported',
            'review_source' => 'legacy-review', 'reviewed_at' => now(), 'reason' => 'unreviewed']);
        $before = $legacy->fresh()->getRawOriginal();
        $head = DB::transaction(fn () => EthereumAccountingReviewHead::locked(self::TOKEN));
        $this->assertEquals($legacy->id, $head->current_review_id);
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->assertNull($legacy->fresh()->reviewer_user_id);
        $this->assertNull($legacy->fresh()->review_format_version);
        $new = app(EthereumEligibilityReviewService::class)->publish($this->input($actor, 'rejected', null, $legacy->id, 1));
        $this->assertEquals($legacy->id, $new->supersedes_review_id);
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
    }

    #[DataProvider('immutableActions')]
    public function test_published_reviews_are_immutable(string $action): void
    {
        $actor = $this->reviewer();
        $review = app(EthereumEligibilityReviewService::class)->publish($this->input($actor));
        $this->expectException(DomainException::class);
        if ($action === 'delete') {
            $review->delete();
        } else {
            $review->update(['status' => 'approved']);
        }
    }

    public static function immutableActions(): array
    {
        return [['delete'], ['update']];
    }

    #[DataProvider('staleHeads')]
    public function test_existing_review_requires_exact_expected_identity_and_version(bool $wrongId): void
    {
        $actor = $this->reviewer();
        $service = app(EthereumEligibilityReviewService::class);
        $first = $service->publish($this->input($actor));
        $input = $this->input($actor, 'rejected', null, $wrongId ? null : $first->id, $wrongId ? 1 : 0);
        try {
            $service->publish($input);
            $this->fail('Stale decision committed.');
        } catch (DomainException) {
            $this->assertDatabaseCount('ethereum_accounting_eligibilities', 1);
            $this->assertEquals($first->id, EthereumAccountingReviewHead::query()->sole()->current_review_id);
        }
    }

    public static function staleHeads(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('changedApprovalContent')]
    public function test_duplicate_approval_requires_exact_decision_and_assertions(string $change): void
    {
        $actor = $this->reviewer();
        $observation = $this->observation();
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->once()->andReturn($observation);
        $input = $this->input($actor, 'approved', $observation);
        app(EthereumEligibilityReviewService::class)->publish($input);
        if ($change === 'decision') {
            $replacement = $this->input($actor);
            $replacement['submission_id'] = $input['submission_id'];
            $input = $replacement;
            $observation = null;
        } else {
            $input['assertions']['historical_applicability'] = 'Different historical conclusion.';
        }
        $input['evidence_digest'] = EthereumEligibilityReviewService::digest($actor->id, $input, $observation);
        $this->expectException(DomainException::class);
        app(EthereumEligibilityReviewService::class)->publish($input);
    }

    public static function changedApprovalContent(): array
    {
        return [['decision'], ['assertions']];
    }

    public function test_reviewer_revoked_during_collection_cannot_publish(): void
    {
        $actor = $this->reviewer();
        $observation = $this->observation();
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->once()->andReturnUsing(function () use ($actor, $observation): array {
            $actor->update(['is_admin' => false]);

            return $observation;
        });
        $this->expectException(AuthorizationException::class);
        app(EthereumEligibilityReviewService::class)->publish($this->input($actor, 'approved', $observation));
    }

    public function test_failed_append_rolls_back_head_and_preserves_previous_review(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This storage-failure injection uses SQLite triggers.');
        }
        $actor = $this->reviewer();
        $service = app(EthereumEligibilityReviewService::class);
        $first = $service->publish($this->input($actor));
        $before = EthereumAccountingReviewHead::query()->sole()->getRawOriginal();
        // Exercise a database failure after successful validation and head acquisition.
        DB::statement("CREATE TRIGGER reject_review_insert BEFORE INSERT ON ethereum_accounting_eligibilities BEGIN SELECT RAISE(ABORT, 'simulated storage failure'); END");
        try {
            $service->publish($this->input($actor, 'rejected', null, $first->id, 1));
            $this->fail('Storage failure was hidden.');
        } catch (QueryException) {
            $this->assertDatabaseCount('ethereum_accounting_eligibilities', 1);
            $this->assertSame($before, EthereumAccountingReviewHead::query()->sole()->getRawOriginal());
        } finally {
            DB::statement('DROP TRIGGER reject_review_insert');
        }
    }

    public function test_reviewer_cannot_be_deleted_while_provenance_is_retained(): void
    {
        $actor = $this->reviewer();
        app(EthereumEligibilityReviewService::class)->publish($this->input($actor));
        $this->expectException(QueryException::class);
        $actor->delete();
    }

    public function test_additive_migration_preserves_actual_preexisting_legacy_row(): void
    {
        $migration = require database_path('migrations/2026_09_22_162500_add_trusted_ethereum_accounting_reviews.php');
        $migration->down();
        $attributes = ['chain' => 'ethereum', 'token_address' => self::TOKEN,
            'policy_version' => EthereumAccountingEligibility::POLICY, 'status' => 'approved',
            'review_source' => 'legacy-source', 'code_sha256' => str_repeat('a', 64),
            'reviewed_at' => '2026-01-01 00:00:00', 'reason' => 'legacy review'];
        $id = DB::table('ethereum_accounting_eligibilities')->insertGetId($attributes);
        $before = (array) DB::table('ethereum_accounting_eligibilities')->find($id);
        $migration->up();
        $after = EthereumAccountingEligibility::query()->findOrFail($id);
        $this->assertSame($before, array_intersect_key($after->getRawOriginal(), $before));
        $this->assertNull($after->reviewer_user_id);
        $this->assertNull($after->review_evidence);
        $this->assertNull($after->review_format_version);
        $head = DB::transaction(fn () => EthereumAccountingReviewHead::locked(self::TOKEN));
        $this->assertEquals($id, $head->current_review_id);
        $this->assertSame('approved', $head->currentReview()->status);
    }

    public function test_rollback_cannot_discard_trusted_provenance(): void
    {
        $actor = $this->reviewer();
        app(EthereumEligibilityReviewService::class)->publish($this->input($actor));
        $migration = require database_path('migrations/2026_09_22_162500_add_trusted_ethereum_accounting_reviews.php');
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    #[DataProvider('validAllowlists')]
    public function test_valid_explicit_allowlist_authorizes_only_listed_administrators(mixed $configured, array $expected): void
    {
        config(['services.ethereum.accounting.reviewer_ids' => $configured]);
        $this->assertSame($expected, EthereumAccountingReviewerAllowlist::normalize($configured));
        foreach ([1, 2, 3, 4] as $id) {
            $admin = new User(['is_admin' => true]);
            $admin->id = $id;
            $this->assertSame(in_array((string) $id, $expected, true), Gate::forUser($admin)->allows('review-ethereum-accounting'));
            $admin->is_admin = false;
            $this->assertFalse(Gate::forUser($admin)->allows('review-ethereum-accounting'));
        }
    }

    public static function validAllowlists(): array
    {
        return [[1, ['1']], ['1', ['1']], ['1,2,3', ['1', '2', '3']], [' 1, 2, 3 ', ['1', '2', '3']],
            [['1', '2'], ['1', '2']], [[1, 2], ['1', '2']], [[1, ' 2 '], ['1', '2']],
            ['1,1,2,1', ['1', '2']], [[1, '1', 2, '2'], ['1', '2']]];
    }

    #[DataProvider('malformedAllowlists')]
    public function test_malformed_allowlist_denies_direct_service_before_collection(mixed $configured): void
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create(['id' => 1, 'is_admin' => true]);
        $this->actingAs($actor);
        config(['services.ethereum.accounting.reviewer_ids' => $configured]);
        $this->assertSame([], EthereumAccountingReviewerAllowlist::normalize($configured));
        $this->assertFalse(Gate::allows('review-ethereum-accounting'));
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldNotReceive('collect');
        try {
            app(EthereumEligibilityReviewService::class)->publish($this->input($actor, 'approved', $this->observation()));
            $this->fail('Malformed configuration authorized a reviewer.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
            $this->assertDatabaseCount('ethereum_accounting_review_heads', 0);
            Http::assertNothingSent();
        }
    }

    public static function malformedAllowlists(): array
    {
        return [[null], [[]], [''], ['   '], [true], [false], [1.0], [1.5], ['1.5'], ['1e3'], [0], ['0'],
            [-1], ['-1'], ['*'], ['anything'], ['true'], ['01'], ['+1'], [['1', true]], [['1', false]],
            [['1', null]], [['1', 1.0]], [['1', 'bad']], [['1', ['2']]], [['1', new \stdClass]],
            [new \stdClass], [['reviewer' => '1']], ['1,0'], ['1,*'], ['1,,2'], ['1,'], [['1,2']]];
    }

    public function test_missing_allowlist_denies_direct_service(): void
    {
        $actor = User::factory()->create(['id' => 1, 'is_admin' => true]);
        $this->actingAs($actor);
        config(['services.ethereum.accounting' => []]);
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldNotReceive('collect');
        $this->expectException(AuthorizationException::class);
        app(EthereumEligibilityReviewService::class)->publish([]);
    }

    #[DataProvider('environmentAllowlists')]
    public function test_actual_environment_configuration_parsing_is_safe(string $raw, array $expected): void
    {
        $key = 'ETHEREUM_ACCOUNTING_REVIEWER_IDS';
        $oldEnv = $_ENV;
        $oldServer = $_SERVER;
        try {
            $_ENV[$key] = $raw;
            $_SERVER[$key] = $raw;
            $services = require config_path('services.php');
            $parsed = $services['ethereum']['accounting']['reviewer_ids'];
            $this->assertSame($expected, $parsed);
            // Use the parsed configuration, as a cached configuration would, independently of env().
            $_ENV[$key] = '999';
            $_SERVER[$key] = '999';
            config(['services.ethereum.accounting.reviewer_ids' => $parsed]);
            $actor = User::factory()->create(['id' => 1, 'is_admin' => true]);
            $this->actingAs($actor);
            $this->assertSame(in_array('1', $expected, true), Gate::allows('review-ethereum-accounting'));
            if ($expected === []) {
                $this->mock(EthereumEligibilityObservationCollector::class)->shouldNotReceive('collect');
                try {
                    app(EthereumEligibilityReviewService::class)->publish($this->input($actor, 'approved', $this->observation()));
                    $this->fail('Malformed environment granted publication authority.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
                }
            }
        } finally {
            $_ENV = $oldEnv;
            $_SERVER = $oldServer;
        }
    }

    public static function environmentAllowlists(): array
    {
        return [['true', []], ['(true)', []], ['false', []], ['(false)', []], ['null', []], ['(null)', []],
            ['empty', []], ['1.0', []], ['1e3', []], ['1,true', []], ['1', ['1']], ['"1"', ['1']], [' 1, 2, 3 ', ['1', '2', '3']]];
    }

    private function reviewer(): User
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        $actor = User::factory()->create(['is_admin' => true]);
        config(['services.ethereum.accounting.reviewer_ids' => [(string) $actor->id]]);
        $this->actingAs($actor);

        return $actor;
    }

    private function observation(): array
    {
        return ['chain_id' => 1, 'token_address' => self::TOKEN, 'code_sha256' => hash('sha256', hex2bin('6000')),
            'block_number' => '100', 'block_hash' => '0x'.str_repeat('a', 64), 'collected_at' => now()->toIso8601String()];
    }

    private function input(User $actor, string $decision = 'rejected', ?array $observation = null, ?int $expected = null, int $version = 0): array
    {
        $input = ['chain' => 'ethereum', 'chain_id' => 1, 'token_address' => self::TOKEN,
            'policy_version' => EthereumAccountingEligibility::POLICY, 'decision' => $decision,
            'review_source' => 'manual source review', 'rationale' => 'Reviewed accounting semantics.',
            'expected_review_id' => $expected, 'expected_version' => $version, 'submission_id' => (string) Str::uuid(),
            'observation_reference' => $observation ? 'server-observation-1' : null,
            'assertions' => $observation ? ['source_reference' => 'review-artifact:1', 'source_sha256' => str_repeat('b', 64),
                'historical_applicability' => 'Reviewed source matches immutable runtime at block 100; no state-dependent accounting paths.',
                'non_proxy' => true, 'standard_transfer_accounting' => true, 'no_mutable_balance_behavior' => true] : []];
        $input['evidence_digest'] = EthereumEligibilityReviewService::digest($actor->id, $input, $observation);

        return $input;
    }
}
