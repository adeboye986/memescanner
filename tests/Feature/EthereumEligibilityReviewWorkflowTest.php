<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
use App\Models\EthereumAccountingEligibility;
use App\Models\LivePosition;
use App\Models\TokenScan;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\EthereumEligibilityObservationCollector;
use App\Services\EthereumEligibilityReviewGeneration;
use App\Services\EthereumEligibilityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthereumEligibilityReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $code = '0x6000';

    #[DataProvider('unauthorizedRoutes')]
    public function test_all_routes_require_explicit_reviewer_authority(string $role, string $action): void
    {
        Http::preventStrayRequests();
        if ($role !== 'guest') {
            $actor = User::factory()->create(['is_admin' => $role !== 'customer']);
            $this->actingAs($actor);
            config(['services.ethereum.accounting.reviewer_ids' => $role === 'malformed' ? [true] : ($role === 'customer' ? [$actor->id] : [])]);
        }
        $url = route('ethereum-eligibility.'.$action, $action === 'index' ? [] : [self::TOKEN]);
        $response = in_array($action, ['index', 'show', 'confirm']) ? $this->get($url) : $this->post($url, []);
        $role === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
        Http::assertNothingSent();
    }

    public static function unauthorizedRoutes(): array
    {
        $cases = [];
        foreach (['guest', 'customer', 'unlisted', 'malformed'] as $role) {
            foreach (['index', 'show', 'collect', 'preview', 'confirm', 'publish'] as $action) {
                $cases[] = [$role, $action];
            }
        }

        return $cases;
    }

    public function test_queue_deduplicates_sources_hides_customer_data_and_bounds_queries(): void
    {
        $this->reviewer();
        $this->scan();
        $opportunity = TradeOpportunity::factory()->create(['chain' => 'ethereum', 'address' => '0x'.str_repeat('A', 40), 'qualification_data' => ['secret' => 'private-opportunity-value']]);
        $this->position(self::TOKEN, $opportunity);
        $onlyOpportunity = '0x'.str_repeat('b', 40);
        TradeOpportunity::factory()->create(['chain' => 'ethereum', 'address' => $onlyOpportunity]);
        $onlyLive = '0x'.str_repeat('c', 40);
        $this->position($onlyLive, TradeOpportunity::factory()->create());
        DB::enableQueryLog();
        $response = $this->get(route('ethereum-eligibility.index'))->assertOk()->assertSee(self::TOKEN)->assertSee($onlyOpportunity)->assertSee($onlyLive)
            ->assertDontSee('private-opportunity-value')->assertDontSee('0x9999999999');
        $this->assertLessThan(15, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $tokens = $response->viewData('tokens');
        $this->assertSame(3, $tokens->total());
        $row = collect($tokens->items())->firstWhere('token', self::TOKEN);
        $this->assertEquals(1, $row->scanned);
        $this->assertEquals(1, $row->opportunity);
        $this->assertEquals(1, $row->live);
        $this->assertEquals(1, $row->waiting);
        Http::assertNothingSent();
    }

    public function test_queue_paginates_and_does_not_load_all_candidates(): void
    {
        $this->reviewer();
        for ($i = 1; $i <= 30; $i++) {
            TokenScan::query()->create(['chain' => 'ethereum', 'address' => '0x'.str_pad(dechex($i), 40, '0', STR_PAD_LEFT)]);
        }
        $page = $this->get(route('ethereum-eligibility.index'))->assertOk()->viewData('tokens');
        $this->assertSame(30, $page->total());
        $this->assertCount(25, $page->items());
        $this->assertCount(5, $this->get(route('ethereum-eligibility.index', ['page' => 2]))->viewData('tokens')->items());
    }

    public function test_approval_collects_confirms_publishes_idempotently_without_trading(): void
    {
        $actor = $this->reviewer();
        $this->scan();
        $opportunity = TradeOpportunity::factory()->create(['chain' => 'ethereum', 'address' => self::TOKEN]);
        $before = $opportunity->fresh()->getRawOriginal();
        $this->rpc();
        $this->collect();
        $publish = $this->preview('approved');
        $this->get(route('ethereum-eligibility.confirm', self::TOKEN))->assertOk()->assertSee('trusted-review-v1')->assertSee(hash('sha256', hex2bin('6000')))->assertSee($publish['evidence_digest'])->assertSee($actor->email);
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $publish)->assertSessionHasNoErrors()->assertRedirect(route('ethereum-eligibility.show', self::TOKEN));
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $publish)->assertSessionHasNoErrors();
        $review = EthereumAccountingEligibility::query()->sole();
        $this->assertSame('approved', $review->status);
        $this->assertEquals($actor->id, $review->reviewer_user_id);
        $this->assertCount(10, $review->review_evidence['submission']['assertions']['workflow_conclusions']);
        $this->assertSame($before, $opportunity->fresh()->getRawOriginal());
        foreach (['paper_positions', 'live_positions', 'ethereum_swap_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNotSent(fn ($request) => ! in_array($request['method'], ['eth_chainId', 'eth_getBlockByNumber', 'eth_getCode'], true));
    }

    public function test_provider_outage_blocks_approval_but_allows_rejection_and_history(): void
    {
        $this->reviewer();
        $this->scan();
        $legacy = EthereumAccountingEligibility::query()->create(['chain' => 'ethereum', 'token_address' => self::TOKEN, 'policy_version' => EthereumAccountingEligibility::POLICY,
            'status' => 'approved', 'review_source' => 'legacy', 'code_sha256' => str_repeat('a', 64), 'reviewed_at' => now(), 'reason' => 'old review']);
        Http::fake(['https://accounting.test' => Http::response([], 503)]);
        $form = $this->form();
        $this->post(route('ethereum-eligibility.collect', self::TOKEN), $form)->assertSessionHasErrors('review');
        $this->post(route('ethereum-eligibility.preview', self::TOKEN), [...$this->form(), ...$this->approval()])->assertSessionHasErrors('review');
        $publish = $this->preview('rejected');
        Http::fake(['https://accounting.test' => fn () => throw new \RuntimeException('Rejection must not query RPC')]);
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $publish)->assertSessionHasNoErrors();
        $review = EthereumAccountingEligibility::query()->latest('id')->first();
        $this->assertSame('rejected', $review->status);
        $this->assertEquals($legacy->id, $review->supersedes_review_id);
        $this->assertNull($review->code_sha256);
        $this->get(route('ethereum-eligibility.show', self::TOKEN))->assertOk()->assertSee('Current decision: REJECTED')->assertSee('Legacy Phase 4B review')->assertSee('Not recorded (legacy)')->assertSee('trusted-review-v1')->assertSee('Supersedes: #'.$legacy->id);
    }

    #[DataProvider('invalidForms')]
    public function test_invalid_or_forged_review_form_cannot_preview(string $path, mixed $value): void
    {
        $this->reviewer();
        $this->scan();
        $this->rpc();
        $this->collect();
        $input = [...$this->form(), ...$this->approval()];
        data_set($input, $path, $value);
        $error = in_array($path, ['reviewer_user_id', 'code_sha256', 'observation_block_hash', 'evidence_digest', 'expected_version', 'review_generation'], true) ? 'review' : $path;
        $this->post(route('ethereum-eligibility.preview', self::TOKEN), $input)->assertSessionHasErrors($error);
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
    }

    public static function invalidForms(): array
    {
        $cases = [['rationale', str_repeat('a', 256)], ['source_reference', str_repeat('a', 2049)], ['source_sha256', 'bad'],
            ['conclusions.source_runtime', 'false'], ['conclusions.source_runtime', 'unexpected'], ['conclusions.source_runtime', []], ['conclusions.source_runtime', null],
            ['review_generation', (string) Str::uuid()],
            ['historical_applicability', ''], ['reviewer_user_id', 999], ['code_sha256', str_repeat('a', 64)], ['observation_block_hash', '0x'.str_repeat('a', 64)],
            ['evidence_digest', str_repeat('0', 64)], ['expected_version', 99]];
        foreach (array_keys(EthereumEligibilityReviewService::WORKFLOW_CONCLUSIONS) as $key) {
            $cases[] = ['conclusions.'.$key, '0'];
        }

        return $cases;
    }

    public function test_wrong_password_blocks_publication_then_correct_password_succeeds(): void
    {
        $this->reviewer();
        $this->scan();
        $publish = $this->preview('rejected');
        $this->from(route('ethereum-eligibility.confirm', self::TOKEN))->post(route('ethereum-eligibility.publish', self::TOKEN), [...$publish, 'current_password' => 'wrong'])->assertRedirect(route('ethereum-eligibility.confirm', self::TOKEN))->assertSessionHasErrors('current_password');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $publish)->assertSessionHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_stale_head_after_preview_cannot_publish(): void
    {
        $this->reviewer();
        $this->scan();
        $publish = $this->preview('rejected');
        $input = session('ethereum_review_confirmation.input');
        $input['submission_id'] = (string) Str::uuid();
        $input['review_generation'] = app(EthereumEligibilityReviewGeneration::class)->begin(auth()->id(), self::TOKEN, $input['submission_id']);
        $input['evidence_digest'] = EthereumEligibilityReviewService::digest(auth()->id(), $input, null);
        app(EthereumEligibilityReviewService::class)->publish($input);
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $publish)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 1);
    }

    public function test_changed_evidence_after_confirmation_cannot_publish(): void
    {
        $this->reviewer();
        $this->scan();
        $this->rpc();
        $this->collect();
        $publish = $this->preview('approved');
        $this->code = '0x6001';
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $publish)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
    }

    public function test_altered_confirmation_digest_and_other_actor_are_rejected(): void
    {
        $this->reviewer();
        $this->scan();
        $publish = $this->preview('rejected');
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), [...$publish, 'evidence_digest' => str_repeat('f', 64)])->assertSessionHasErrors('review');
        $this->reviewer();
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $publish)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
    }

    public function test_metadata_references_and_reasons_are_escaped(): void
    {
        $this->reviewer();
        $evil = '<script>alert(1)</script>';
        $this->scan(['symbol' => $evil, 'name' => $evil]);
        $this->get(route('ethereum-eligibility.index'))->assertSee(e($evil), false)->assertDontSee($evil, false);
        $form = $this->form();
        $this->post(route('ethereum-eligibility.preview', self::TOKEN), [...$form, 'decision' => 'rejected', 'rationale' => $evil])->assertSessionHasNoErrors();
        $this->get(route('ethereum-eligibility.confirm', self::TOKEN))->assertSee(e($evil), false)->assertDontSee($evil, false);
    }

    public function test_recollection_invalidates_previous_confirmation(): void
    {
        $this->reviewer();
        $this->scan();
        $this->rpc();
        $this->collect();
        $old = $this->preview('approved');
        $this->collect();
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $old)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
    }

    public function test_changed_evidence_before_preview_requires_fresh_collection(): void
    {
        $this->reviewer();
        $this->scan();
        $this->rpc();
        $this->collect();
        $this->code = '0x6001';
        $this->post(route('ethereum-eligibility.preview', self::TOKEN), [...$this->form(), ...$this->approval()])->assertSessionHasErrors('review');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
    }

    public function test_expired_confirmation_and_wrong_token_cannot_publish(): void
    {
        $this->reviewer();
        $this->scan();
        $publish = $this->preview('rejected');
        $this->post(route('ethereum-eligibility.publish', '0x'.str_repeat('b', 40)), $publish)->assertSessionHasErrors('review');
        $this->travel(21)->minutes();
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $publish)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
    }

    public function test_approval_source_and_notes_remain_escaped_in_confirmation_and_history(): void
    {
        $this->reviewer();
        $this->scan();
        $this->rpc();
        $this->collect();
        $evil = '<img src=x onerror=alert(1)>';
        $input = [...$this->form(), ...$this->approval(), 'source_reference' => $evil, 'reviewer_notes' => $evil];
        $this->post(route('ethereum-eligibility.preview', self::TOKEN), $input)->assertSessionHasNoErrors();
        $this->get(route('ethereum-eligibility.confirm', self::TOKEN))->assertSee(e($evil), false)->assertDontSee($evil, false);
        $confirmed = session('ethereum_review_confirmation.input');
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), [
            'submission_id' => $confirmed['submission_id'], 'expected_review_id' => null, 'expected_version' => 0,
            'evidence_digest' => $confirmed['evidence_digest'], 'current_password' => 'review-password',
        ])->assertSessionHasNoErrors();
        $this->get(route('ethereum-eligibility.show', self::TOKEN))->assertSee(e($evil), false)->assertDontSee($evil, false);
    }

    public function test_review_actions_require_csrf_protection(): void
    {
        $this->reviewer();
        $this->scan();
        $form = $this->form();
        $this->app['env'] = 'production';
        $this->post(route('ethereum-eligibility.collect', self::TOKEN), $form)->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_all_history_pages_are_bounded_and_no_edit_or_delete_route_exists(): void
    {
        $this->reviewer();
        $this->scan();
        for ($i = 0; $i < 30; $i++) {
            EthereumAccountingEligibility::query()->create(['chain' => 'ethereum', 'token_address' => self::TOKEN, 'policy_version' => EthereumAccountingEligibility::POLICY,
                'status' => 'unsupported', 'review_source' => 'legacy', 'reviewed_at' => now(), 'reason' => 'unknown semantics']);
        }
        $history = $this->get(route('ethereum-eligibility.show', self::TOKEN))->assertOk()->viewData('history');
        $this->assertSame(30, $history->total());
        $this->assertCount(25, $history->items());
        $this->delete(route('ethereum-eligibility.show', self::TOKEN))->assertStatus(405);
        $this->put(route('ethereum-eligibility.show', self::TOKEN), [])->assertStatus(405);
    }

    public function test_restored_old_session_and_old_form_cannot_publish_after_recollection(): void
    {
        $this->reviewer();
        $this->scan();
        $this->rpc();
        $this->collect();
        $oldFormPost = $this->form();
        $oldPost = $this->preview('approved');
        $oldSession = ['ethereum_review_form' => session('ethereum_review_form'), 'ethereum_review_confirmation' => session('ethereum_review_confirmation')];
        $this->collect();
        $newPost = $this->preview('approved');
        $newSession = ['ethereum_review_form' => session('ethereum_review_form'), 'ethereum_review_confirmation' => session('ethereum_review_confirmation')];

        $this->post(route('ethereum-eligibility.preview', self::TOKEN), [...$oldFormPost, ...$this->approval()])->assertSessionHasErrors('review');
        $this->withSession($oldSession)->post(route('ethereum-eligibility.publish', self::TOKEN), $oldPost)
            ->assertSessionHasErrors(['review' => 'Stale or expired review generation. Open the review again.']);
        $this->withSession($oldSession)->post(route('ethereum-eligibility.preview', self::TOKEN), [...$oldFormPost, ...$this->approval()])->assertSessionHasErrors('review');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
        $this->withSession($newSession)->post(route('ethereum-eligibility.publish', self::TOKEN), $newPost)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 1);
        $this->assertSame($newSession['ethereum_review_form']['review_generation'], EthereumAccountingEligibility::query()->sole()->review_evidence['submission']['review_generation']);
    }

    public function test_old_preview_finishing_after_invalidation_cannot_restore_publishability(): void
    {
        $actor = $this->reviewer();
        $this->scan();
        $this->rpc();
        $this->collect();
        $form = session('ethereum_review_form');
        $calls = 0;
        $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->andReturnUsing(function () use ($actor, $form, &$calls) {
            if ($calls++ === 0) {
                app(EthereumEligibilityReviewGeneration::class)->replace($actor->id, self::TOKEN, $form['submission_id'], $form['review_generation']);
            }

            return $form['observation'];
        });
        $oldPost = $this->preview('approved');

        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $oldPost)
            ->assertSessionHasErrors(['review' => 'Stale or expired review generation. Open the review again.']);
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
    }

    #[DataProvider('generationMismatches')]
    public function test_generation_is_bound_and_expires_independently_of_session(string $case): void
    {
        $actor = $this->reviewer();
        $this->scan();
        $this->preview('rejected');
        $input = session('ethereum_review_confirmation.input');
        if ($case === 'actor') {
            $actor = $this->reviewer();
        } elseif ($case === 'token') {
            $input['token_address'] = '0x'.str_repeat('b', 40);
        } elseif ($case === 'submission') {
            $input['submission_id'] = (string) Str::uuid();
        } else {
            $this->travel(21)->minutes();
        }
        $input['evidence_digest'] = EthereumEligibilityReviewService::digest($actor->id, $input, null);
        try {
            app(EthereumEligibilityReviewService::class)->publish($input);
            $this->fail('Unbound or expired generation was accepted.');
        } catch (\DomainException $exception) {
            $this->assertSame('Stale or expired review generation. Open the review again.', $exception->getMessage());
            $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
        }
        Http::assertNothingSent();
    }

    public static function generationMismatches(): array
    {
        return [['actor'], ['token'], ['submission'], ['expired']];
    }

    public function test_success_retry_survives_authoritative_generation_expiry(): void
    {
        $this->reviewer();
        $this->scan();
        $post = $this->preview('rejected');
        $input = session('ethereum_review_confirmation.input');
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $post)->assertSessionHasNoErrors();
        $review = EthereumAccountingEligibility::query()->sole();
        $this->travel(21)->minutes();

        $this->assertSame($review->id, app(EthereumEligibilityReviewService::class)->publish($input)->id);
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 1);
        $this->assertDatabaseHas('ethereum_accounting_review_heads', ['current_review_id' => $review->id, 'version' => 1]);
    }

    public function test_password_never_enters_session_and_changed_password_is_required(): void
    {
        $actor = $this->reviewer();
        $this->scan();
        $post = $this->preview('rejected');
        $actor->password = 'replacement-review-password';
        $actor->save();
        $this->actingAs($actor->fresh());

        $this->post(route('ethereum-eligibility.publish', self::TOKEN), $post)->assertSessionHasErrors('current_password');
        $this->assertDatabaseCount('ethereum_accounting_eligibilities', 0);
        $this->assertStringNotContainsString('review-password', json_encode(session()->all()));
        $this->post(route('ethereum-eligibility.publish', self::TOKEN), [...$post, 'current_password' => 'replacement-review-password'])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('review-password', json_encode(session()->all()));
        $this->assertStringNotContainsString('review-password', json_encode(EthereumAccountingEligibility::query()->sole()->review_evidence));
    }

    public function test_generation_replacement_cannot_overlap_publication_critical_section(): void
    {
        $actor = $this->reviewer();
        $this->scan();
        $post = $this->preview('rejected');
        $input = session('ethereum_review_confirmation.input');
        $attempted = false;
        $events = clone EthereumAccountingEligibility::getEventDispatcher();
        EthereumAccountingEligibility::setEventDispatcher($events);
        $original = app('events');
        try {
            EthereumAccountingEligibility::creating(function () use ($actor, $input, &$attempted): void {
                $attempted = true;
                try {
                    app(EthereumEligibilityReviewGeneration::class)->replace($actor->id, self::TOKEN, $input['submission_id'], $input['review_generation']);
                    $this->fail('Generation changed inside publication critical section.');
                } catch (\DomainException $exception) {
                    $this->assertSame('Review generation is busy. Retry after the other operation finishes.', $exception->getMessage());
                }
            });
            $this->post(route('ethereum-eligibility.publish', self::TOKEN), $post)->assertSessionHasNoErrors();
            $this->assertTrue($attempted);
            $this->assertDatabaseCount('ethereum_accounting_eligibilities', 1);
        } finally {
            EthereumAccountingEligibility::setEventDispatcher($original);
        }
    }

    public function test_file_cache_generation_replacement_and_locking_are_authoritative(): void
    {
        $actor = $this->reviewer();
        $path = sys_get_temp_dir().'/ethereum-review-generation-'.Str::uuid();
        config(['cache.stores.review_file' => ['driver' => 'file', 'path' => $path, 'lock_path' => $path],
            'services.ethereum.metadata_cache_store' => 'review_file']);
        $generations = app(EthereumEligibilityReviewGeneration::class);
        $submission = (string) Str::uuid();
        try {
            $first = $generations->begin($actor->id, self::TOKEN, $submission);
            $generations->withCurrent($actor->id, self::TOKEN, $submission, $first, null, function () use ($generations, $actor, $submission, $first): void {
                $this->travel(2)->minutes();
                try {
                    $generations->replace($actor->id, self::TOKEN, $submission, $first);
                    $this->fail('Non-expiring publication lock was bypassed.');
                } catch (\DomainException $exception) {
                    $this->assertStringContainsString('generation is busy', $exception->getMessage());
                }
            });
            $second = $generations->replace($actor->id, self::TOKEN, $submission, $first);
            $generations->bind($actor->id, self::TOKEN, $submission, $second, 'bound-reference');
            foreach ([[$first, null], [$second, 'different-reference']] as [$generation, $reference]) {
                try {
                    $generations->withCurrent($actor->id, self::TOKEN, $submission, $generation, $reference, fn () => $this->fail('Stale evidence accepted.'));
                    $this->fail('Stale evidence accepted.');
                } catch (\DomainException $exception) {
                    $this->assertStringContainsString('Stale', $exception->getMessage());
                }
            }
            $this->assertSame('current', $generations->withCurrent($actor->id, self::TOKEN, $submission, $second, 'bound-reference', fn () => 'current'));
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_unsupported_generation_cache_fails_closed(): void
    {
        $actor = $this->reviewer();
        config(['cache.stores.review_unsafe' => ['driver' => 'null'], 'services.ethereum.metadata_cache_store' => 'review_unsafe']);
        $this->expectExceptionMessage('Review generations require a shared file or Redis cache with non-expiring atomic locks.');
        app(EthereumEligibilityReviewGeneration::class)->begin($actor->id, self::TOKEN, (string) Str::uuid());
    }

    private function reviewer(): User
    {
        Http::preventStrayRequests();
        config(['services.ethereum.rpc_url' => 'https://accounting.test', 'services.ethereum.metadata_cache_store' => 'array']);
        $actor = User::factory()->create(['is_admin' => true, 'password' => 'review-password']);
        config(['services.ethereum.accounting.reviewer_ids' => [$actor->id]]);
        $this->actingAs($actor);

        return $actor;
    }

    private function scan(array $attributes = []): void
    {
        TokenScan::query()->create([...['chain' => 'ethereum', 'address' => self::TOKEN, 'symbol' => 'TOKEN', 'name' => 'Token', 'last_scanned_at' => now()], ...$attributes]);
    }

    private function form(): array
    {
        $response = $this->get(route('ethereum-eligibility.show', self::TOKEN))->assertOk();
        $form = $response->viewData('form');

        return ['submission_id' => $form['submission_id'], 'expected_review_id' => $form['expected_review_id'], 'expected_version' => $form['expected_version'], 'evidence_digest' => $response->viewData('digest')];
    }

    private function collect(): void
    {
        $this->post(route('ethereum-eligibility.collect', self::TOKEN), $this->form())->assertSessionHasNoErrors();
    }

    private function approval(): array
    {
        return ['decision' => 'approved', 'rationale' => 'Reviewed standard accounting semantics.', 'source_reference' => 'source-artifact:1', 'source_sha256' => str_repeat('b', 64),
            'historical_applicability' => 'All balance-changing paths reviewed against historical bytecode.', 'reviewer_notes' => 'No mutable dependencies.',
            'conclusions' => array_fill_keys(array_keys(EthereumEligibilityReviewService::WORKFLOW_CONCLUSIONS), '1')];
    }

    private function preview(string $decision): array
    {
        $input = $decision === 'approved' ? $this->approval() : ['decision' => 'rejected', 'rationale' => 'Insufficient source evidence.'];
        $this->post(route('ethereum-eligibility.preview', self::TOKEN), [...$this->form(), ...$input])->assertSessionHasNoErrors()->assertRedirect(route('ethereum-eligibility.confirm', self::TOKEN));
        $input = session('ethereum_review_confirmation.input');

        return ['submission_id' => $input['submission_id'], 'expected_review_id' => $input['expected_review_id'], 'expected_version' => $input['expected_version'],
            'evidence_digest' => $input['evidence_digest'], 'current_password' => 'review-password'];
    }

    private function rpc(): void
    {
        Http::fake(['https://accounting.test' => function ($request) {
            $result = match ($request['method']) {
                'eth_chainId' => '0x1', 'eth_getCode' => $this->code,
                'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('d', 64), 'timestamp' => '0x1'],
                default => throw new \RuntimeException('Unexpected RPC method'),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        }]);
    }

    private function position(string $token, TradeOpportunity $opportunity): void
    {
        $user = User::factory()->create();
        $address = '0x'.str_pad(dechex($user->id), 40, '9', STR_PAD_LEFT);
        $wallet = ConnectedWallet::query()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'address' => $address, 'address_hash' => ConnectedWallet::addressHash('ethereum', $address)]);
        LivePosition::factory()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'network' => 'mainnet', 'wallet_address' => $wallet->address,
            'connected_wallet_id' => $wallet->id, 'trade_opportunity_id' => $opportunity->id, 'token_address' => $token,
            'entry_transaction_hash' => '0x'.str_repeat('e', 64), 'entry_block_number' => '100', 'entry_confirmed_at' => now()]);
    }
}
