<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
use App\Models\EthereumAccountingEligibility;
use App\Models\EthereumAccountingEvidence;
use App\Models\EthereumAccountingReconsideration as Audit;
use App\Models\EthereumAccountingReviewHead;
use App\Models\LivePosition;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\EthereumAccountingReconsideration;
use App\Services\EthereumInventoryAccounting;
use App\Services\EthereumOpportunityReservationService;
use App\Services\EthereumReceiptReconciliationService;
use App\Services\EthereumTransferExtractor;
use App\Services\PaperTradeEntryService;
use App\Services\UserTradingPreferenceService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthereumAccountingReconsiderationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0x2222222222222222222222222222222222222222';

    private ?\Closure $onRpc = null;

    #[DataProvider('deniedActors')]
    public function test_service_and_routes_require_reviewer_authority(string $role): void
    {
        Http::preventStrayRequests();
        if ($role !== 'guest') {
            $actor = User::factory()->create(['is_admin' => $role !== 'customer']);
            $this->actingAs($actor);
            config(['services.ethereum.accounting.reviewer_ids' => $role === 'malformed' ? [true] : ($role === 'customer' ? [$actor->id] : [])]);
        }
        $uuid = (string) Str::uuid();
        foreach (['prepare', 'show', 'execute'] as $action) {
            $url = route('ethereum-eligibility.reconsideration.'.$action, $action === 'prepare' ? [self::TOKEN] : [self::TOKEN, $uuid]);
            $response = $action === 'show' ? $this->get($url) : $this->post($url);
            $role === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
        }
        foreach (['prepare', 'execute'] as $method) {
            try {
                $method === 'prepare' ? app(EthereumAccountingReconsideration::class)->prepare(self::TOKEN, 1, 1, $uuid)
                    : app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $uuid);
                $this->fail('Unauthorized reconsideration accepted.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('ethereum_accounting_reconsiderations', 0);
            }
        }
        Http::assertNothingSent();
    }

    public static function deniedActors(): array
    {
        return [['guest'], ['customer'], ['unlisted'], ['malformed']];
    }

    public function test_approved_reconsideration_reuses_accounting_and_is_idempotent(): void
    {
        $position = $this->position();
        $position->update(['accounting_status' => 'unsupported']);
        app(PaperTradeEntryService::class)->buy(['user_id' => $position->user_id, 'chain' => 'ethereum',
            'address' => '0x'.str_repeat('8', 40), 'symbol' => 'PAPER', 'entry_market_cap' => 100000, 'entry_price' => 1]);
        $this->assertDatabaseCount('paper_positions', 1);
        $this->reviewer();
        $review = $this->review();
        $audit = $this->prepare($review);
        $before = $this->tradingSnapshot();
        $this->provider($position);

        $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame('completed', $result->status);
        $this->assertSame(1, $result->succeeded_count);
        $this->assertSame('verified', $position->fresh()->accounting_status);
        $this->assertSame('1', $position->fresh()->acquired_raw_amount);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
        $verified = $position->fresh()->getRawOriginal();
        Http::fake(fn () => throw new \RuntimeException('Duplicate execution queried RPC'));
        $retry = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame($result->getRawOriginal(), $retry->getRawOriginal());
        $this->assertSame($verified, $position->fresh()->getRawOriginal());
        $this->assertSame($before, $this->tradingSnapshot());
        $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
    }

    public function test_rejected_review_records_noop_without_rpc_or_evidence(): void
    {
        $position = $this->position();
        $position->update(['accounting_status' => 'unsupported']);
        $this->reviewer();
        $audit = $this->prepare($this->review('rejected'));
        $before = $position->fresh()->getRawOriginal();

        $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame('completed', $result->status);
        $this->assertSame([['position_id' => $position->id, 'code' => 'skipped_rejected']], $result->outcomes);
        $this->assertSame($before, $position->fresh()->getRawOriginal());
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
        $this->assertSame($result->id, app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid)->id);
        Http::assertNothingSent();
    }

    public function test_changed_review_before_execution_is_durably_stale(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        $this->review('rejected');
        $before = $position->fresh()->getRawOriginal();

        $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame('stale', $result->status);
        $this->assertSame(1, $result->stale_count);
        $this->assertNull($result->started_at);
        $this->assertSame($before, $position->fresh()->getRawOriginal());
        $this->assertSame('stale', app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid)->status);
        Http::assertNothingSent();
    }

    public function test_review_change_during_rpc_does_not_apply_old_evidence(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        $this->provider($position);
        $this->onRpc = fn () => $this->review('rejected');

        $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame('stale', $result->status);
        $this->assertSame('pending', $position->fresh()->accounting_status);
        $this->assertNull($position->fresh()->accounting_lease_token);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
        $this->assertSame('stale_review', $result->outcomes[0]['code']);
    }

    #[DataProvider('changedCandidates')]
    public function test_changed_candidates_are_skipped_without_rpc(string $field, mixed $value, string $code): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        DB::table('live_positions')->where('id', $position->id)->update([$field => $value === 'future' ? now()->addHour() : $value]);
        $before = $position->fresh()->getRawOriginal();

        $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame($code, $result->outcomes[0]['code']);
        $this->assertSame($before, $position->fresh()->getRawOriginal());
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
        Http::assertNothingSent();
    }

    public static function changedCandidates(): array
    {
        return [['accounting_status', 'verified', 'skipped_verified'], ['accounting_status', 'discrepancy', 'skipped_discrepancy'],
            ['accounting_status', 'provisional', 'skipped_provisional'], ['accounting_status', 'unsupported', 'skipped_state_changed'],
            ['accounting_version', 42, 'skipped_state_changed'], ['network', 'other', 'skipped_identity'],
            ['token_address', '0x'.str_repeat('3', 40), 'skipped_identity'], ['chain', 'solana', 'skipped_identity'],
            ['accounting_lease_expires_at', 'future', 'skipped_active_lease'], ['accounting_next_attempt_at', 'future', 'skipped_backoff'],
            ['entry_block_number', '101', 'skipped_identity']];
    }

    public function test_expired_lease_can_be_reclaimed_and_normal_worker_cannot_double_apply(): void
    {
        $position = $this->position();
        $position->forceFill(['accounting_lease_token' => 'expired', 'accounting_lease_expires_at' => now()->subSecond()])->save();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        $this->provider($position);
        $this->onRpc = function () use ($position, $audit): void {
            $this->assertSame('skipped', app(EthereumInventoryAccounting::class)->process($position->id));
            $this->assertSame('running', app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid)->status);
        };

        $this->assertSame('completed', app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid)->status);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
        $this->assertSame(1, $audit->fresh()->considered_count);
    }

    public function test_scope_counts_and_batch_limit_exclude_ineligible_inventory(): void
    {
        $pending = $this->position();
        $unsupported = $this->position();
        $unsupported->update(['accounting_status' => 'unsupported']);
        foreach ([['accounting_status', 'verified'], ['accounting_status', 'discrepancy'], ['accounting_status', 'provisional'],
            ['accounting_lease_expires_at', now()->addHour()], ['accounting_next_attempt_at', now()->addHour()],
            ['network', 'wrong'], ['entry_transaction_hash', '0x'.str_repeat('a', 64)],
            ['chain', 'solana'], ['token_address', '0x'.str_repeat('4', 40)]] as [$field, $value]) {
            $excluded = $this->position();
            DB::table('live_positions')->where('id', $excluded->id)->update([$field => $value]);
        }
        $this->reviewer();
        config(['services.ethereum.accounting.reconsideration_batch_size' => 1]);
        $review = $this->review('rejected');
        $service = app(EthereumAccountingReconsideration::class);
        $this->assertSame(['eligible' => 2, 'verified' => 1, 'discrepancy' => 1, 'other' => 5, 'limit' => 1], $service->summary(self::TOKEN));
        $audit = $this->prepare($review);
        $this->assertCount(1, $audit->candidate_snapshot);
        $this->assertSame($pending->id, $audit->candidate_snapshot[0]['id']);
        $this->assertSame(1, $audit->remaining_count);
        $result = $service->execute(self::TOKEN, $audit->request_uuid);
        $this->assertCount(1, $result->outcomes);
        $this->assertSame(2, $result->remaining_count);
        $this->assertSame('unsupported', $unsupported->fresh()->accounting_status);
        config(['services.ethereum.accounting.reconsideration_batch_size' => 999999]);
        $this->assertSame(10, $service->summary(self::TOKEN)['limit']);
        Http::assertNothingSent();
    }

    public function test_preview_and_execution_are_bound_to_actor_token_request_and_password(): void
    {
        $position = $this->position();
        $actor = $this->reviewer();
        $review = $this->review('rejected');
        $show = $this->get(route('ethereum-eligibility.show', self::TOKEN))->assertOk()->assertDontSee($position->wallet_address);
        $form = $show->viewData('reconsiderationForm');
        $url = route('ethereum-eligibility.reconsideration.prepare', self::TOKEN);
        $input = ['review_id' => $review->id, 'review_version' => $form['version']];
        $this->post($url, $input)->assertSessionHasNoErrors();
        $this->post($url, $input)->assertSessionHasNoErrors();
        $audit = Audit::query()->sole();
        $confirm = route('ethereum-eligibility.reconsideration.show', [self::TOKEN, $audit->request_uuid]);
        $execute = route('ethereum-eligibility.reconsideration.execute', [self::TOKEN, $audit->request_uuid]);
        $this->get($confirm)->assertOk()->assertSee('REJECTED')->assertSee('No trades')->assertDontSee($position->wallet_address);
        $this->post($execute, ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertSame('awaiting_confirmation', $audit->fresh()->status);
        $this->assertSame('pending', $position->fresh()->accounting_status);
        $this->assertStringNotContainsString('wrong', json_encode(session()->all()));
        $actor->password = 'new-review-password';
        $actor->save();
        $this->actingAs($actor->fresh());
        $this->post($execute, ['current_password' => 'review-password'])->assertSessionHasErrors('current_password');
        $this->post($execute, ['current_password' => 'new-review-password'])->assertSessionHasNoErrors();
        $this->post($execute, ['current_password' => 'new-review-password'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $audit->fresh()->status);
        $this->assertStringNotContainsString('review-password', json_encode(session()->all()));
        $this->assertStringNotContainsString('password', $audit->fresh()->toJson());
        $this->get(route('ethereum-eligibility.show', self::TOKEN))->assertSee($audit->request_uuid)->assertDontSee($position->wallet_address);
        $this->get(route('ethereum-eligibility.reconsideration.show', ['0x'.str_repeat('4', 40), $audit->request_uuid]))->assertNotFound();
        $this->reviewer();
        $this->get($confirm)->assertNotFound();
        $this->post($execute, ['current_password' => 'review-password'])->assertNotFound();
        $this->assertDatabaseCount('ethereum_accounting_reconsiderations', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('bindingFields')]
    public function test_binding_fields_cannot_be_rewritten(string $field): void
    {
        $this->reviewer();
        $audit = $this->prepare($this->review('rejected'));
        $value = match ($field) {
            'candidate_snapshot' => [['id' => 123, 'state' => 'pending', 'version' => '0', 'source' => str_repeat('a', 64)]],
            'reviewer_identity' => ['id' => '999', 'name' => 'Forged', 'email' => 'forged@example.test'],
            'expires_at' => now()->addHour(),
            'batch_limit' => 4,
            'requested_by_user_id', 'ethereum_accounting_eligibility_id', 'review_head_version' => 999,
            default => 'changed',
        };
        $audit->forceFill([$field => $value]);
        $this->expectExceptionMessage('Reconsideration binding is immutable.');
        $audit->save();
    }

    public static function bindingFields(): array
    {
        return array_map(fn ($field) => [$field], [...Audit::BINDING_FIELDS, 'binding_digest']);
    }

    public function test_digest_tampering_fails_without_accounting_work(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        DB::table('ethereum_accounting_reconsiderations')->where('id', $audit->id)->update(['binding_digest' => str_repeat('f', 64)]);
        $before = $position->fresh()->getRawOriginal();
        $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame('failed', $result->status);
        $this->assertNull($result->started_at);
        $this->assertSame($before, $position->fresh()->getRawOriginal());
        $this->assertSame('failed', app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid)->status);
        Http::assertNothingSent();
    }

    public function test_expired_confirmation_cannot_start_but_in_progress_expiry_does_not_interrupt(): void
    {
        $position = $this->position();
        $this->reviewer();
        $review = $this->review();
        $expired = $this->prepare($review);
        $this->travelTo($expired->expires_at);
        $this->assertSame('failed', app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $expired->request_uuid)->status);
        Http::assertNothingSent();
        $active = $this->prepare($review);
        $this->travel(19)->minutes();
        $this->provider($position);
        $this->onRpc = fn () => $this->travel(2)->minutes();
        $this->assertSame('completed', app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $active->request_uuid)->status);
        $this->assertSame('verified', $position->fresh()->accounting_status);
    }

    public function test_snapshot_and_outcomes_are_bounded_and_membership_checked(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review('rejected'));
        $bad = $audit->replicate();
        $bad->forceFill(['request_uuid' => (string) Str::uuid(), 'candidate_snapshot' => array_fill(0, 11, $audit->candidate_snapshot[0])]);
        try {
            $bad->save();
            $this->fail('Oversized candidate snapshot accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('Reconsideration snapshots and outcomes must be bounded.', $exception->getMessage());
        }
        foreach ([[['position_id' => $position->id + 999, 'code' => 'skipped_rejected']],
            [['position_id' => $position->id, 'code' => 'unbounded arbitrary reason']],
            [['position_id' => $position->id, 'code' => 'skipped_rejected'], ['position_id' => $position->id, 'code' => 'skipped_rejected']]] as $outcomes) {
            $invalid = $audit->fresh();
            $invalid->forceFill(['status' => 'running', 'started_at' => now(), 'outcomes' => $outcomes, ...Audit::counts($outcomes)]);
            try {
                $invalid->save();
                $this->fail('Invalid outcomes accepted.');
            } catch (DomainException) {
                $this->assertSame([], $audit->fresh()->outcomes);
            }
        }
    }

    public function test_terminal_states_are_final_and_bindings_are_not_mass_assignable(): void
    {
        $this->reviewer();
        $audit = $this->prepare($this->review('rejected'));
        try {
            $audit->fill(['token_address' => '0x'.str_repeat('4', 40)]);
            $this->fail('Audit binding mass assignment accepted.');
        } catch (MassAssignmentException) {
            $this->assertSame(self::TOKEN, $audit->fresh()->token_address);
        }
        foreach (['completed', 'stale', 'failed'] as $status) {
            $candidate = $this->prepare($this->review('rejected'));
            if ($status === 'completed') {
                $candidate->forceFill(['status' => 'running', 'started_at' => now()])->save();
            }
            $candidate->forceFill(['status' => $status, 'finished_at' => now()])->save();
            $candidate->forceFill(['status' => 'running', 'started_at' => now(), 'finished_at' => null]);
            try {
                $candidate->save();
                $this->fail('Terminal request restarted.');
            } catch (DomainException $exception) {
                $this->assertSame('Invalid reconsideration state transition.', $exception->getMessage());
            }
        }
    }

    public function test_preparation_requires_exact_head_and_request_identity(): void
    {
        $this->reviewer();
        $review = $this->review('rejected');
        foreach ([[$review->id + 1, 1], [$review->id, 2]] as [$id, $version]) {
            try {
                app(EthereumAccountingReconsideration::class)->prepare(self::TOKEN, $id, $version, (string) Str::uuid());
                $this->fail('Stale review accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('The current review changed. Open the review again.', $exception->getMessage());
            }
        }
        $audit = $this->prepare($review);
        $this->reviewer();
        $this->expectExceptionMessage('Reconsideration request identity is already bound to different content.');
        app(EthereumAccountingReconsideration::class)->prepare(self::TOKEN, $review->id, 1, $audit->request_uuid);
    }

    public function test_existing_accounting_evidence_is_preserved_and_publication_does_not_start_work(): void
    {
        $position = $this->position();
        $this->reviewer();
        $this->assertSame('unsupported', app(EthereumInventoryAccounting::class)->process($position->id));
        $old = EthereumAccountingEvidence::query()->sole()->getRawOriginal();
        $review = $this->review();
        $this->assertDatabaseCount('ethereum_accounting_reconsiderations', 0);
        $this->assertSame('unsupported', $position->fresh()->accounting_status);
        Http::assertNothingSent();
        $audit = $this->prepare($review);
        $this->provider($position);
        app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame($old, EthereumAccountingEvidence::query()->findOrFail($old['id'])->getRawOriginal());
        $this->assertDatabaseCount('ethereum_accounting_evidence', 2);
    }

    public function test_failure_is_terminal_and_never_records_raw_exception_secrets(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        $this->provider($position);
        $this->onRpc = fn () => throw new \RuntimeException('private-password-provider-secret');
        $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame('failed', $result->status);
        $this->assertSame(1, $result->failed_count);
        $this->assertStringNotContainsString('private-password-provider-secret', $result->toJson());
        $this->assertSame('pending', $position->fresh()->accounting_status);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
        $this->assertSame($result->getRawOriginal(), app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid)->getRawOriginal());
    }

    public function test_failed_audit_write_rolls_back_the_accounting_application(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        $this->provider($position);
        $original = Audit::getEventDispatcher();
        Audit::setEventDispatcher(clone $original);
        $failedOnce = false;
        try {
            Audit::updating(function (Audit $model) use (&$failedOnce): void {
                if (! $failedOnce && $model->outcomes !== []) {
                    $failedOnce = true;
                    throw new \RuntimeException('Simulated audit write failure.');
                }
            });
            $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
            $this->assertTrue($failedOnce);
            $this->assertSame('failed', $result->status);
            $this->assertSame('pending', $position->fresh()->accounting_status);
            $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
        } finally {
            Audit::setEventDispatcher($original);
        }
    }

    public function test_normal_worker_claim_wins_over_reconsideration_without_double_application(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        $this->provider($position);
        $this->onRpc = function () use ($audit): void {
            $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
            $this->assertSame('skipped_active_lease', $result->outcomes[0]['code']);
        };
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
        $this->assertSame(1, $audit->fresh()->skipped_count);
    }

    public function test_source_mutation_during_rpc_is_not_applied(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        $this->provider($position);
        $this->onRpc = fn () => DB::table('live_positions')->where('id', $position->id)->update(['entry_block_number' => '101']);
        $result = app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid);
        $this->assertSame('skipped_identity', $result->outcomes[0]['code']);
        $this->assertSame('pending', $position->fresh()->accounting_status);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
    }

    public function test_revoked_reviewer_cannot_apply_inflight_accounting(): void
    {
        $position = $this->position();
        $this->reviewer();
        $audit = $this->prepare($this->review());
        $this->provider($position);
        $this->onRpc = fn () => config(['services.ethereum.accounting.reviewer_ids' => []]);
        $this->assertSame('failed', app(EthereumAccountingReconsideration::class)->execute(self::TOKEN, $audit->request_uuid)->status);
        $this->assertSame('pending', $position->fresh()->accounting_status);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
    }

    public function test_csrf_and_server_controlled_fields_cannot_be_bypassed(): void
    {
        $this->position();
        $this->reviewer();
        $review = $this->review('rejected');
        $this->get(route('ethereum-eligibility.show', self::TOKEN))->assertOk();
        $url = route('ethereum-eligibility.reconsideration.prepare', self::TOKEN);
        $input = ['review_id' => $review->id, 'review_version' => 1];
        foreach (['binding_digest', 'reviewer_identity', 'candidate_snapshot', 'batch_limit', 'request_uuid'] as $field) {
            $this->post($url, [...$input, $field => 'forged'])->assertSessionHasErrors('reconsideration');
        }
        $this->assertDatabaseCount('ethereum_accounting_reconsiderations', 0);
        $this->app['env'] = 'production';
        $this->post($url, $input)->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_audit_foreign_keys_and_rollback_preserve_retained_requests(): void
    {
        $actor = $this->reviewer();
        $review = $this->review('rejected');
        $audit = $this->prepare($review);
        foreach ([['users', $actor->id], ['ethereum_accounting_eligibilities', $review->id]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('Audit foreign key allowed source deletion.');
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
        }
        $migration = require database_path('migrations/2026_09_23_111742_create_ethereum_accounting_reconsiderations_table.php');
        try {
            $migration->down();
            $this->fail('Retained audit migration rolled back.');
        } catch (\LogicException $exception) {
            $this->assertSame('Cannot remove retained Ethereum reconsideration audit records.', $exception->getMessage());
            $this->assertDatabaseHas('ethereum_accounting_reconsiderations', ['id' => $audit->id]);
        }
    }

    private function reviewer(): User
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create(['is_admin' => true, 'password' => 'review-password']);
        $this->actingAs($actor);
        config(['services.ethereum.accounting.reviewer_ids' => [$actor->id], 'services.ethereum.metadata_cache_store' => 'array']);

        return $actor;
    }

    private function review(string $decision = 'approved'): EthereumAccountingEligibility
    {
        return DB::transaction(function () use ($decision): EthereumAccountingEligibility {
            $head = EthereumAccountingReviewHead::locked(self::TOKEN);
            $review = EthereumAccountingEligibility::query()->create(['chain' => 'ethereum', 'token_address' => self::TOKEN,
                'policy_version' => EthereumAccountingEligibility::POLICY, 'status' => $decision, 'review_source' => 'test-source',
                'code_sha256' => $decision === 'approved' ? hash('sha256', hex2bin('6000')) : null, 'reviewed_at' => now(), 'reason' => 'Reviewed test source.']);
            $head->current_review_id = $review->id;
            $head->version++;
            $head->save();

            return $review;
        });
    }

    private function prepare(EthereumAccountingEligibility $review): Audit
    {
        return app(EthereumAccountingReconsideration::class)->prepare(self::TOKEN, $review->id,
            (int) EthereumAccountingReviewHead::query()->where('token_address', self::TOKEN)->sole()->version, (string) Str::uuid());
    }

    private function position(): LivePosition
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        app(EthereumReceiptReconciliationService::class)->apply($attempt, ['transaction_hash' => $attempt->transaction_hash,
            'succeeded' => true, 'block_number' => '100', 'gas_used' => '21000', 'effective_gas_price_wei' => '1', 'actual_network_fee_wei' => '21000']);

        return LivePosition::query()->where('ethereum_swap_attempt_id', $attempt->id)->sole();
    }

    private function tradingSnapshot(): array
    {
        $snapshot = [];
        foreach (['trade_opportunities', 'ethereum_swap_attempts', 'paper_positions', 'paper_wallets', 'user_trading_preferences'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $snapshot['live_positions'] = LivePosition::query()->count();

        return $snapshot;
    }

    private function provider(LivePosition $position): void
    {
        config(['services.ethereum.rpc_url' => 'https://reconsideration.test', 'services.ethereum.accounting.finality' => 'finalized']);
        $hash = '0x'.str_repeat('b', 64);
        $level = DB::transactionLevel();
        Http::preventStrayRequests();
        Http::fake(['https://reconsideration.test' => function ($request) use ($position, $hash, $level) {
            $this->assertSame($level, DB::transactionLevel(), 'RPC ran under a database lock transaction.');
            if ($this->onRpc) {
                $hook = $this->onRpc;
                $this->onRpc = null;
                $hook();
            }
            $receipt = ['transactionHash' => $position->entry_transaction_hash, 'blockHash' => $hash, 'blockNumber' => '0x64',
                'transactionIndex' => '0x0', 'status' => '0x1', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1',
                'logs' => [['address' => $position->token_address, 'topics' => [EthereumTransferExtractor::TOPIC,
                    '0x'.str_repeat('0', 24).str_repeat('3', 40), '0x'.str_repeat('0', 24).substr($position->wallet_address, 2)],
                    'data' => '0x'.str_pad('1', 64, '0', STR_PAD_LEFT), 'logIndex' => '0x0']]];
            $result = match ($request['method']) {
                'eth_chainId' => '0x1', 'eth_getTransactionReceipt' => $receipt,
                'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => $hash, 'timestamp' => '0x1234'],
                'eth_getTransactionByHash' => ['hash' => $position->entry_transaction_hash, 'from' => $position->wallet_address,
                    'to' => '0x'.str_repeat('3', 40), 'value' => '0x38d7ea4c68000', 'input' => '0x1234', 'chainId' => '0x1',
                    'blockHash' => $hash, 'blockNumber' => '0x64', 'transactionIndex' => '0x0', 'type' => '0x2'],
                'eth_getCode' => '0x6000', 'eth_call' => '0x'.str_pad('12', 64, '0', STR_PAD_LEFT),
                default => throw new \RuntimeException('Unexpected RPC or trading method.'),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        }]);
    }

    private function submitted(): array
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => false, 'risk.max_trade_amount' => '0.1', 'risk.max_slippage_percent' => 1]);
        app(UserTradingPreferenceService::class)->forUser($user)->update(['execution_mode' => 'live', 'entry_mode' => 'confirm', 'trading_enabled' => true]);
        $address = '0x'.str_pad(dechex($user->id), 40, '0', STR_PAD_LEFT);
        $wallet = ConnectedWallet::query()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'address' => $address,
            'address_hash' => ConnectedWallet::addressHash('ethereum', $address), 'verified_at' => now()]);
        $opportunity = TradeOpportunity::factory()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'address' => '0x'.str_repeat('2', 40),
            'scanner' => 'new-token', 'execution_mode' => 'live', 'entry_mode' => 'confirm', 'status' => 'pending_confirmation', 'qualified_at' => now()]);
        $attempt = app(EthereumOpportunityReservationService::class)->reserve($opportunity, $user, ['sell_amount_wei' => '1000000000000000', 'slippage_bps' => 100]);
        $attempt->update(['status' => 'submitted', 'transaction_hash' => '0x'.str_pad(dechex($attempt->id), 64, '0', STR_PAD_LEFT), 'submitted_at' => now(), 'expires_at' => now()->addMinute(), 'transaction_payload' => [
            'from' => $address, 'to' => '0x'.str_repeat('3', 40), 'value' => '1000000000000000', 'data' => '0x1234', 'gas' => '21000', 'gasPrice' => '1', 'chainId' => '1']]);

        return [$user, $opportunity, $attempt, $wallet];
    }
}
