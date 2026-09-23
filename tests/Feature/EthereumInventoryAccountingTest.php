<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
use App\Models\EthereumAccountingEligibility;
use App\Models\EthereumAccountingEvidence;
use App\Models\EthereumAccountingReviewHead;
use App\Models\EthereumSwapAttempt;
use App\Models\LivePosition;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\Chains\EthereumChainAdapter;
use App\Services\EthereumAccountingRpc;
use App\Services\EthereumEligibilityObservationCollector;
use App\Services\EthereumEligibilityReviewService;
use App\Services\EthereumInventoryAccounting;
use App\Services\EthereumOpportunityReservationService;
use App\Services\EthereumReceiptReconciliationService;
use App\Services\EthereumTransferExtractor;
use App\Services\PaperTradeEntryService;
use App\Services\PaperWalletService;
use App\Services\UserTelegramNotificationService;
use App\Services\UserTradingPreferenceService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EthereumInventoryAccountingTest extends TestCase
{
    use RefreshDatabase;

    private string $head = '0x64';

    private string $blockHash;

    private ?string $canonicalBlockHash = null;

    private bool $missing = false;

    private bool $metadataUnavailable = false;

    private ?\Closure $onRequest = null;

    private bool $unavailable = false;

    private bool $finalityUnavailable = false;

    private string $decimals = '12';

    private string $amount = '01';

    private string $index = '0x0';

    private string $blockNumber = '0x64';

    private int $receiptCalls = 0;

    private bool $disappearOnRecheck = false;

    public function test_pending_provisional_verified_and_audit_are_idempotent(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->head = '0x63';
        $this->assertSame('provisional', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertNull($position->fresh()->acquired_raw_amount);
        $firstEvidence = EthereumAccountingEvidence::query()->sole()->getRawOriginal();
        $this->travel(2)->hours();
        $this->head = '0x64';
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
        $verified = $position->fresh();
        $this->assertSame('1', $verified->acquired_raw_amount);
        $this->assertSame(18, $verified->token_decimals);
        $this->assertNotNull($verified->accounting_verified_at);
        $this->assertSame($firstEvidence, EthereumAccountingEvidence::query()->oldest('id')->first()->getRawOriginal());
        $this->assertSame('skipped', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id, true));
        $this->assertDatabaseCount('ethereum_accounting_evidence', 2);
        $this->assertSame($verified->accepted_ethereum_evidence_id, $position->fresh()->accepted_ethereum_evidence_id);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('paper_wallets', 0);
    }

    #[DataProvider('retryCases')]
    public function test_retryable_evidence_remains_pending_or_provisional(string $case): void
    {
        $position = $this->position();
        $this->provider($position);
        if ($case === 'metadata') {
            $this->metadataUnavailable = true;
        }
        if ($case === 'missing') {
            $this->missing = true;
        }
        if ($case === 'transport') {
            $this->unavailable = true;
        }
        if ($case === 'finality') {
            $this->finalityUnavailable = true;
        }
        if ($case === 'recheck') {
            $this->disappearOnRecheck = true;
        }
        if ($case === 'unconfigured') {
            config(['services.ethereum.accounting.finality' => 'unknown']);
        }
        $result = app(EthereumInventoryAccounting::class)->process($position->id);
        $this->assertSame(in_array($case, ['finality', 'unconfigured'], true) ? 'provisional' : 'retryable', $result);
        $this->assertNull($position->fresh()->acquired_raw_amount);
        $this->assertNotNull($position->fresh()->accounting_next_attempt_at);
        $this->assertNull($position->fresh()->accounting_lease_token);
    }

    public static function retryCases(): array
    {
        return [['missing'], ['transport'], ['finality'], ['unconfigured'], ['recheck'], ['metadata']];
    }

    public function test_explicit_confirmation_policy_never_uses_an_implicit_depth(): void
    {
        $position = $this->position();
        $this->provider($position);
        config(['services.ethereum.accounting.finality' => 'confirmations', 'services.ethereum.accounting.confirmations' => null]);
        $this->assertSame('provisional', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->travel(2)->hours();
        config(['services.ethereum.accounting.confirmations' => '2']);
        $this->assertSame('provisional', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->travel(2)->hours();
        $this->head = '0x65';
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
    }

    #[DataProvider('reorgCases')]
    public function test_reorg_or_conflicting_verified_evidence_appends_discrepancy(string $case): void
    {
        $position = $this->position();
        $this->provider($position);
        if ($case === 'provisional') {
            $this->head = '0x63';
        }
        app(EthereumInventoryAccounting::class)->process($position->id);
        $accepted = $position->fresh()->accepted_ethereum_evidence_id;
        $old = EthereumAccountingEvidence::findOrFail($accepted)->getRawOriginal();
        $this->travel(2)->hours();
        if ($case === 'quantity') {
            $this->amount = '02';
        } elseif ($case === 'index') {
            $this->index = '0x1';
        } elseif ($case === 'relocated') {
            $this->blockNumber = '0x65';
        } else {
            $this->blockHash = '0x'.str_repeat('c', 64);
        }
        $this->assertSame('discrepancy', app(EthereumInventoryAccounting::class)->process($position->id, true));
        $this->assertSame($old, EthereumAccountingEvidence::findOrFail($accepted)->getRawOriginal());
        $this->assertDatabaseCount('ethereum_accounting_evidence', 2);
        if ($case !== 'provisional') {
            $this->assertSame($accepted, $position->fresh()->accepted_ethereum_evidence_id);
            $this->assertSame('1', $position->fresh()->acquired_raw_amount);
        }
    }

    public static function reorgCases(): array
    {
        return [['block hash'], ['index'], ['relocated'], ['quantity'], ['provisional']];
    }

    #[DataProvider('metadataCases')]
    public function test_metadata_policy_is_historical_and_exact(string $hex, string $state, ?int $decimals): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->decimals = $hex;
        $this->assertSame($state, app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertSame($decimals, $position->fresh()->token_decimals);
    }

    public static function metadataCases(): array
    {
        return [['00', 'verified', 0], ['12', 'verified', 18], ['13', 'unsupported', null], ['zz', 'discrepancy', null]];
    }

    public function test_unknown_token_is_unsupported_without_rpc_and_can_resume_after_review(): void
    {
        $position = $this->position(false);
        Http::preventStrayRequests();
        $this->assertSame('unsupported', app(EthereumInventoryAccounting::class)->process($position->id));
        Http::assertNothingSent();
        $this->review($position);
        $this->provider($position);
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id, false, true));
    }

    public function test_reviewed_code_must_match_execution_block_code(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->review($position, str_repeat('a', 64));
        $this->assertSame('unsupported', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertNull($position->fresh()->acquired_raw_amount);
    }

    public function test_lease_blocks_second_worker_and_expired_lease_is_recoverable(): void
    {
        $position = $this->position();
        $position->accounting_lease_token = 'interrupted';
        $position->accounting_lease_expires_at = now()->addMinute();
        $position->save();
        $this->provider($position);
        $this->assertSame('skipped', app(EthereumInventoryAccounting::class)->process($position->id));
        Http::assertNothingSent();
        $this->travel(2)->minutes();
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
    }

    public function test_worker_is_bounded_and_poison_record_does_not_starve_next_position(): void
    {
        $bad = $this->position(false);
        $good = $this->position();
        DB::table('ethereum_swap_attempts')->where('id', $bad->ethereum_swap_attempt_id)->update(['transaction_payload' => 'corrupt']);
        $later = $this->position(false);
        $this->provider($good);
        config(['services.ethereum.accounting.batch_size' => 2]);
        $this->artisan('ethereum:reconcile-inventory')->expectsOutput("Position {$bad->id}: discrepancy.")
            ->expectsOutput("Position {$good->id}: verified.")->expectsOutput('Processed 2 Ethereum inventory candidate(s).')->assertFailed();
        $this->assertSame('pending', $later->fresh()->accounting_status);
        $this->assertSame('verified', $good->fresh()->accounting_status);
    }

    public function test_accounting_apply_rolls_back_and_unexpected_database_failure_propagates(): void
    {
        $position = $this->position();
        $this->provider($position);
        LivePosition::updating(function (LivePosition $row): void {
            if ($row->accounting_status === 'verified') {
                throw new RuntimeException('Simulated write failure.');
            }
        });
        try {
            app(EthereumInventoryAccounting::class)->process($position->id);
            $this->fail('Unexpected write errors must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated write failure.', $exception->getMessage());
        }
        $this->assertSame('pending', $position->fresh()->accounting_status);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
    }

    public function test_verified_quantities_and_evidence_cannot_be_mutated(): void
    {
        $position = $this->position();
        $this->provider($position);
        app(EthereumInventoryAccounting::class)->process($position->id);
        $position->refresh();
        $position->token_decimals = 0;
        try {
            $position->save();
            $this->fail('Verified decimals must be immutable.');
        } catch (DomainException) {
            $this->assertSame(18, $position->fresh()->token_decimals);
        }
        $this->expectException(DomainException::class);
        EthereumAccountingEvidence::query()->sole()->update(['reason_code' => 'rewritten']);
    }

    public function test_second_worker_cannot_acquire_active_lease_during_rpc(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->onRequest = function () use ($position): void {
            $this->assertSame('skipped', app(EthereumInventoryAccounting::class)->process($position->id));
        };
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
    }

    public function test_execution_changes_during_rpc_cannot_be_applied(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->onRequest = function () use ($position): void {
            TradeOpportunity::findOrFail($position->trade_opportunity_id)->update(['address' => '0x'.str_repeat('9', 40)]);
        };
        $this->assertSame('stale_observation', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertNull($position->fresh()->acquired_raw_amount);
    }

    public function test_expired_claim_cannot_commit_and_next_worker_can_resume(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->onRequest = function (): void {
            $this->travel(10)->minutes();
        };
        $this->assertSame('stale_observation', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertSame('pending', $position->fresh()->accounting_status);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
    }

    public function test_unexpected_rpc_programming_failure_propagates(): void
    {
        $position = $this->position();
        $this->mock(EthereumAccountingRpc::class)->shouldReceive('assertNetwork')->once()->andThrow(new \LogicException('Unexpected bug.'));
        $this->expectException(\LogicException::class);
        app(EthereumInventoryAccounting::class)->process($position->id);
    }

    public function test_paper_tracking_and_virtual_balances_are_independent_of_inventory_worker(): void
    {
        $position = $this->position();
        $paper = app(PaperTradeEntryService::class)->buy(['user_id' => $position->user_id, 'chain' => 'ethereum',
            'address' => '0x'.str_repeat('8', 40), 'symbol' => 'PAPER', 'entry_market_cap' => 100000, 'entry_price' => 1]);
        $wallet = app(PaperWalletService::class)->forUser(User::findOrFail($position->user_id), 'ethereum');
        $before = $wallet->fresh()->getRawOriginal();
        $paperBefore = $paper->fresh()->getRawOriginal();
        $this->provider($position);
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertSame($before, $wallet->fresh()->getRawOriginal());
        $this->assertSame($paperBefore, $paper->fresh()->getRawOriginal());
        $this->assertDatabaseCount('paper_positions', 1);
        $liveBefore = $position->fresh()->getRawOriginal();
        $this->mock(UserTelegramNotificationService::class)->shouldReceive('send')->zeroOrMoreTimes();
        $this->mock(EthereumChainAdapter::class)->shouldReceive('marketDataMany')->once()->andReturn([
            $paper->address => ['available' => true, 'market_cap' => 85000, 'price_usd' => 0.85, 'liquidity_usd' => 10000]]);
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->assertSame('closed', $paper->fresh()->status);
        $this->assertSame($liveBefore, $position->fresh()->getRawOriginal());
    }

    public function test_prior_unsupported_revision_does_not_hide_initial_block_relocation(): void
    {
        $position = $this->position(false);
        $this->assertSame('unsupported', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->review($position);
        $this->provider($position);
        $this->blockNumber = '0x65';
        $this->assertSame('discrepancy', app(EthereumInventoryAccounting::class)->process($position->id, false, true));
        $this->assertSame('initial_block_changed', $position->fresh()->accounting_reason_code);
        $this->assertNull($position->fresh()->acquired_raw_amount);
    }

    public function test_review_history_and_accounting_evidence_survive_parent_deletion_attempts(): void
    {
        $position = $this->position();
        $this->provider($position);
        app(EthereumInventoryAccounting::class)->process($position->id);
        try {
            DB::table('live_positions')->where('id', $position->id)->delete();
            $this->fail('Evidence must prevent direct parent deletion.');
        } catch (QueryException) {
            $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
            $this->assertDatabaseCount('live_positions', 1);
        }
        $this->expectException(DomainException::class);
        EthereumAccountingEligibility::query()->sole()->update(['status' => 'unsupported']);
    }

    public function test_conflicting_provisional_quantity_cannot_be_promoted_to_verified(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->head = '0x63';
        $this->assertSame('provisional', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->travel(2)->hours();
        $this->head = '0x64';
        $this->amount = '02';
        $this->assertSame('discrepancy', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertNull($position->fresh()->acquired_raw_amount);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 2);
    }

    public function test_additive_migration_rolls_back_empty_but_preserves_retained_evidence(): void
    {
        $migration = require database_path('migrations/2026_09_22_144321_add_live_inventory_accounting.php');
        $migration->down();
        $migration->up();
        $position = $this->position();
        $this->provider($position);
        app(EthereumInventoryAccounting::class)->process($position->id);
        try {
            $migration->down();
            $this->fail('Financial evidence must survive rollback.');
        } catch (\LogicException) {
            $this->assertSame('verified', $position->fresh()->accounting_status);
            $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
        }
    }

    #[DataProvider('interveningDecisions')]
    public function test_stale_accounting_observation_preserves_intervening_decision(string $change): void
    {
        $position = $this->position();
        $attemptBefore = EthereumSwapAttempt::findOrFail($position->ethereum_swap_attempt_id)->getRawOriginal();
        $opportunityBefore = TradeOpportunity::findOrFail($position->trade_opportunity_id)->getRawOriginal();
        $this->provider($position);
        $expected = [];
        $this->onRequest = function () use ($position, $change, &$expected): void {
            $row = $position->fresh();
            $this->assertSame(1, (int) $row->accounting_version, 'Snapshot must use the post-claim version.');
            if (in_array($change, ['status and version', 'status only'], true)) {
                $row->accounting_status = 'discrepancy';
                $row->accounting_reason_code = 'operator_quarantine';
            }
            if (in_array($change, ['version only', 'status and version'], true)) {
                $row->accounting_version++;
            }
            if (str_starts_with($change, 'evidence')) {
                $revision = EthereumAccountingEvidence::query()->create(['live_position_id' => $row->id,
                    'ethereum_swap_attempt_id' => $row->ethereum_swap_attempt_id, 'state' => 'provisional',
                    'reason_code' => 'operator_review', 'digest' => hash('sha256', 'operator'),
                    'policy_version' => EthereumInventoryAccounting::POLICY, 'evidence' => ['operator' => 'retained']]);
                if ($change === 'evidence without version') {
                    // Simulate a writer bypassing model hooks to exercise the reference guard independently.
                    DB::table('live_positions')->where('id', $row->id)->update(['accepted_ethereum_evidence_id' => $revision->id]);
                    $row->refresh();
                } else {
                    $row->accepted_ethereum_evidence_id = $revision->id;
                    $row->accounting_reason_code = 'operator_review';
                }
            }
            $row->save();
            $row->refresh();
            $expected = $row->only(['accounting_status', 'accounting_version', 'accounting_reason_code', 'accepted_ethereum_evidence_id']);
        };

        $this->assertSame('stale_observation', app(EthereumInventoryAccounting::class)->process($position->id));

        $after = $position->fresh();
        $this->assertSame($expected, $after->only(array_keys($expected)));
        $this->assertNull($after->acquired_raw_amount);
        $this->assertNull($after->token_decimals);
        $this->assertNull($after->accounting_verified_at);
        $this->assertNull($after->accounting_lease_token);
        $this->assertSame($attemptBefore, EthereumSwapAttempt::findOrFail($position->ethereum_swap_attempt_id)->getRawOriginal());
        $this->assertSame($opportunityBefore, TradeOpportunity::findOrFail($position->trade_opportunity_id)->getRawOriginal());
        $this->assertDatabaseCount('ethereum_accounting_evidence', str_starts_with($change, 'evidence') ? 1 : 0);
    }

    public static function interveningDecisions(): array
    {
        return [['status and version'], ['status only'], ['version only'], ['evidence'], ['evidence without version']];
    }

    public function test_stale_worker_never_releases_another_workers_lease(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->onRequest = function () use ($position): void {
            $row = $position->fresh();
            $row->accounting_lease_token = 'replacement-worker';
            $row->accounting_version++;
            $row->save();
        };
        $this->assertSame('stale_observation', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertSame('replacement-worker', $position->fresh()->accounting_lease_token);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
    }

    public function test_decisions_advance_version_but_timestamps_do_not(): void
    {
        $position = $this->position();
        $position->accounting_last_attempt_at = now();
        $position->save();
        $this->assertSame(0, (int) $position->fresh()->accounting_version);
        $position->accounting_reason_code = 'operator_review';
        $position->save();
        $this->assertSame(1, (int) $position->fresh()->accounting_version);
        $position->accounting_status = 'unsupported';
        $position->save();
        $this->assertSame(2, (int) $position->fresh()->accounting_version);
    }

    public function test_json_storage_key_reordering_does_not_conflict_or_duplicate_evidence(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->head = '0x63';
        $this->assertSame('provisional', app(EthereumInventoryAccounting::class)->process($position->id));
        $original = EthereumAccountingEvidence::query()->sole();
        $reordered = json_decode(json_encode($this->reorderObjects($original->evidence), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($original->digest, EthereumInventoryAccounting::evidenceDigest($original->state, $original->reason_code, $reordered));
        // Emulate a JSON database's storage normalization, not an application evidence edit.
        DB::table('ethereum_accounting_evidence')->where('id', $original->id)->update(['evidence' => json_encode($reordered, JSON_THROW_ON_ERROR)]);
        $this->travel(2)->hours();
        $this->assertSame('provisional', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
        $this->travel(2)->hours();
        $this->head = '0x64';
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
        $verified = EthereumAccountingEvidence::findOrFail($position->fresh()->accepted_ethereum_evidence_id);
        DB::table('ethereum_accounting_evidence')->where('id', $verified->id)->update(['evidence' => json_encode($this->reorderObjects($verified->evidence), JSON_THROW_ON_ERROR)]);
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id, true));
        $this->assertDatabaseCount('ethereum_accounting_evidence', 2);
        $this->assertSame($verified->id, $position->fresh()->accepted_ethereum_evidence_id);
    }

    public function test_canonical_evidence_preserves_scalar_types_objects_and_list_order(): void
    {
        $facts = $this->digestFacts();
        $this->assertSame(EthereumInventoryAccounting::canonicalEvidence($facts), EthereumInventoryAccounting::canonicalEvidence($this->reorderObjects($facts)));
        $this->assertNotSame(EthereumInventoryAccounting::canonicalEvidence('1'), EthereumInventoryAccounting::canonicalEvidence(1));
        $this->assertNotSame(EthereumInventoryAccounting::canonicalEvidence(true), EthereumInventoryAccounting::canonicalEvidence(1));
        $this->assertNotSame(EthereumInventoryAccounting::canonicalEvidence(null), EthereumInventoryAccounting::canonicalEvidence(''));
        $this->assertNotSame(EthereumInventoryAccounting::canonicalEvidence(1.0), EthereumInventoryAccounting::canonicalEvidence(1));
        $this->assertNotSame(EthereumInventoryAccounting::canonicalEvidence([]), EthereumInventoryAccounting::canonicalEvidence((object) []));
        $this->assertNotSame(EthereumInventoryAccounting::canonicalEvidence(['a', 'b']), EthereumInventoryAccounting::canonicalEvidence(['b', 'a']));
        $this->assertNotSame(EthereumInventoryAccounting::canonicalEvidence(['a', 'b']), EthereumInventoryAccounting::canonicalEvidence((object) ['0' => 'a', '1' => 'b']));
    }

    #[DataProvider('materialEvidenceChanges')]
    public function test_digest_distinguishes_material_changes(string $path, mixed $value): void
    {
        $facts = $this->digestFacts();
        $changed = $facts;
        data_set($changed, $path, $value);
        $this->assertNotSame(EthereumInventoryAccounting::evidenceDigest('provisional', null, $facts), EthereumInventoryAccounting::evidenceDigest('provisional', null, $changed));
    }

    public static function materialEvidenceChanges(): array
    {
        return [['transaction_hash', 'other'], ['receipt.block_hash', 'other'], ['receipt.transaction_index', '1'],
            ['wallet', 'other'], ['token', 'other'], ['transaction.value', '2'], ['quantity.incoming', '3'],
            ['quantity.outgoing', '1'], ['quantity.net', '3'], ['quantity.net', 2], ['quantity.transfers.0.amount', '2'],
            ['metadata.decimals', 0], ['metadata.decimals', '18'], ['code_sha256', 'other'], ['eligibility_version', 'v2'],
            ['extraction_version', 'v2'], ['finality.version', 'v2'], ['finality.head.hash', 'other'],
            ['finality.satisfied', true], ['quantity.transfers', [['log_index' => '1', 'amount' => '1'], ['log_index' => '0', 'amount' => '1']]]];
    }

    public function test_finality_progress_appends_observation_without_changing_execution_identity(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->head = '0x62';
        $this->assertSame('provisional', app(EthereumInventoryAccounting::class)->process($position->id));
        $first = EthereumAccountingEvidence::query()->sole();
        $this->travel(2)->hours();
        $this->head = '0x63';
        $this->assertSame('provisional', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertDatabaseCount('ethereum_accounting_evidence', 2);
        $second = EthereumAccountingEvidence::findOrFail($position->fresh()->accepted_ethereum_evidence_id);
        $this->assertSame($first->evidence['quantity'], $second->evidence['quantity']);
        $this->assertNotSame($first->digest, $second->digest);
        $this->assertSame($first->id, $second->evidence['supersedes_evidence_id']);
    }

    public function test_sufficient_finalized_height_cannot_verify_noncanonical_execution_block(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->head = '0x65';
        $this->canonicalBlockHash = '0x'.str_repeat('c', 64);
        $this->assertSame('discrepancy', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertSame('noncanonical_receipt', $position->fresh()->accounting_reason_code);
        $this->assertNull($position->fresh()->accounting_verified_at);
    }

    #[DataProvider('wrongEligibility')]
    public function test_wrong_chain_or_policy_cannot_certify_inventory(string $field, string $value): void
    {
        $position = $this->position(false);
        // Insert foreign/unknown policy data as a future importer might; current model disallows it.
        DB::table('ethereum_accounting_eligibilities')->insert([$field => $value] + ['chain' => 'ethereum', 'token_address' => $position->token_address,
            'policy_version' => EthereumAccountingEligibility::POLICY, 'status' => 'approved', 'review_source' => 'foreign-policy',
            'code_sha256' => hash('sha256', hex2bin('6000')), 'reviewed_at' => now(), 'reason' => 'not this policy']);
        $this->assertSame('unsupported', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertNull($position->fresh()->acquired_raw_amount);
        Http::assertNothingSent();
    }

    public static function wrongEligibility(): array
    {
        return [['chain', 'solana'], ['policy_version', 'unknown-v2']];
    }

    public function test_eligibility_revoked_during_rpc_discards_observation(): void
    {
        $position = $this->position();
        $this->provider($position);
        $this->onRequest = function () use ($position): void {
            $this->publishReview($position, 'rejected');
        };
        $this->assertSame('stale_observation', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertSame('pending', $position->fresh()->accounting_status);
        $this->assertNull($position->fresh()->accepted_ethereum_evidence_id);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 0);
    }

    public function test_verified_receipt_outage_preserves_frozen_evidence_and_exposes_retry(): void
    {
        $position = $this->position();
        $this->provider($position);
        app(EthereumInventoryAccounting::class)->process($position->id);
        $fields = ['acquired_raw_amount', 'token_decimals', 'accepted_ethereum_evidence_id', 'accounting_verified_at', 'accounting_policy_version'];
        $before = array_intersect_key($position->fresh()->getRawOriginal(), array_flip($fields));
        $this->missing = true;
        $this->assertSame('retryable', app(EthereumInventoryAccounting::class)->process($position->id, true));
        $after = $position->fresh();
        $this->assertSame('verified', $after->accounting_status);
        $this->assertSame($before, array_intersect_key($after->getRawOriginal(), array_flip($fields)));
        $this->assertSame('receipt_unavailable', $after->accounting_reason_code);
        $this->assertNotNull($after->accounting_next_attempt_at);
        $this->assertDatabaseCount('ethereum_accounting_evidence', 1);
    }

    #[DataProvider('frozenFields')]
    public function test_each_verified_field_is_protected_by_model_hooks(string $field, mixed $value): void
    {
        $position = $this->position();
        $this->provider($position);
        app(EthereumInventoryAccounting::class)->process($position->id);
        $position->refresh();
        $before = $position->getRawOriginal();
        $position->{$field} = $value;
        try {
            $position->save();
            $this->fail('Model update must reject frozen evidence.');
        } catch (DomainException) {
            $this->assertSame($before, $position->fresh()->getRawOriginal());
        }
    }

    public static function frozenFields(): array
    {
        return [['acquired_raw_amount', '2'], ['token_decimals', 0], ['accepted_ethereum_evidence_id', 999],
            ['accounting_policy_version', 'rewritten'], ['accounting_verified_at', null],
            ['wallet_address', '0x'.str_repeat('9', 40)], ['token_address', '0x'.str_repeat('9', 40)]];
    }

    private function reorderObjects(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->reorderObjects($item);
        }
        if (! $list) {
            krsort($value, SORT_STRING);
        }

        return $value;
    }

    private function digestFacts(): array
    {
        return ['transaction_hash' => 'hash', 'wallet' => 'wallet', 'token' => 'token', 'code_sha256' => 'code',
            'receipt' => ['block_hash' => 'block', 'transaction_index' => '0'], 'transaction' => ['value' => '1'],
            'quantity' => ['incoming' => '2', 'outgoing' => '0', 'net' => '2', 'transfers' => [['log_index' => '0', 'amount' => '1'], ['log_index' => '1', 'amount' => '1']]],
            'metadata' => ['decimals' => 18, 'observed_at' => 'time'], 'eligibility_version' => 'v1', 'extraction_version' => 'v1',
            'finality' => ['version' => 'v1', 'satisfied' => false, 'head' => ['hash' => 'head']]];
    }

    #[DataProvider('storedEvidenceConflicts')]
    public function test_audit_preserves_strict_scalar_types_and_material_transfer_facts(string $path, mixed $value): void
    {
        $position = $this->position();
        $this->provider($position);
        app(EthereumInventoryAccounting::class)->process($position->id);
        $acceptedId = $position->fresh()->accepted_ethereum_evidence_id;
        $evidence = EthereumAccountingEvidence::findOrFail($acceptedId)->evidence;
        data_set($evidence, $path, $value);
        // Deliberately bypass hooks to test detection of inconsistent stored evidence.
        DB::table('ethereum_accounting_evidence')->where('id', $acceptedId)->update(['evidence' => json_encode($evidence, JSON_THROW_ON_ERROR)]);
        $this->assertSame('discrepancy', app(EthereumInventoryAccounting::class)->process($position->id, true));
        $this->assertSame($acceptedId, $position->fresh()->accepted_ethereum_evidence_id);
        $this->assertSame('1', $position->fresh()->acquired_raw_amount);
        $this->assertSame($evidence, EthereumAccountingEvidence::findOrFail($acceptedId)->evidence);
    }

    public static function storedEvidenceConflicts(): array
    {
        return [['quantity.net', 1], ['metadata.decimals', '18'], ['quantity.transfers.0.from', '0x'.str_repeat('4', 40)], ['transaction.value', '2']];
    }

    public function test_accounting_version_cannot_move_backwards(): void
    {
        $position = $this->position();
        $position->accounting_status = 'unsupported';
        $position->save();
        $position->accounting_version = 0;
        $this->expectException(DomainException::class);
        $position->save();
    }

    public function test_trusted_approval_verifies_but_newest_rejection_blocks_retry(): void
    {
        $position = $this->position(false);
        $approval = $this->publishReview($position, 'approved');
        $this->provider($position);
        $this->assertSame('verified', app(EthereumInventoryAccounting::class)->process($position->id));
        $frozen = $position->fresh()->only(['acquired_raw_amount', 'token_decimals', 'accepted_ethereum_evidence_id', 'accounting_verified_at']);
        $rejection = $this->publishReview($position, 'rejected');
        $this->assertSame('discrepancy', app(EthereumInventoryAccounting::class)->process($position->id, true));
        $this->assertEquals($frozen, $position->fresh()->only(array_keys($frozen)));
        $this->assertEquals($approval->id, $rejection->supersedes_review_id);
        $this->assertSame('skipped', app(EthereumInventoryAccounting::class)->process($position->id, true, true));
    }

    public function test_rejection_keeps_unverified_inventory_unsupported_without_rpc(): void
    {
        $position = $this->position(false);
        $this->publishReview($position, 'approved');
        $this->publishReview($position, 'rejected');
        Http::preventStrayRequests();
        $this->assertSame('unsupported', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertNull($position->fresh()->acquired_raw_amount);
        $this->assertNull($position->fresh()->token_decimals);
        Http::assertNothingSent();
    }

    public function test_legacy_unsupported_keeps_original_provenance_and_cannot_verify(): void
    {
        $position = $this->position(false);
        $legacy = EthereumAccountingEligibility::query()->create(['chain' => 'ethereum', 'token_address' => $position->token_address,
            'policy_version' => EthereumAccountingEligibility::POLICY, 'status' => 'unsupported',
            'review_source' => 'legacy', 'reviewed_at' => now(), 'reason' => 'semantics unknown']);
        Http::preventStrayRequests();
        $this->assertSame('unsupported', app(EthereumInventoryAccounting::class)->process($position->id));
        $this->assertNull($legacy->fresh()->reviewer_user_id);
        $this->assertNull($legacy->fresh()->review_format_version);
        $this->assertSame('semantics unknown', $legacy->fresh()->reason);
        Http::assertNothingSent();
    }

    private function publishReview(LivePosition $position, string $decision): EthereumAccountingEligibility
    {
        $actor = User::factory()->create(['is_admin' => true]);
        $this->actingAs($actor);
        config(['services.ethereum.accounting.reviewer_ids' => [(string) $actor->id]]);
        $head = DB::transaction(fn () => EthereumAccountingReviewHead::locked($position->token_address));
        $observation = $decision === 'approved' ? ['chain_id' => 1, 'token_address' => $position->token_address,
            'code_sha256' => hash('sha256', hex2bin('6000')), 'block_number' => '100',
            'block_hash' => '0x'.str_repeat('a', 64), 'collected_at' => now()->toIso8601String()] : null;
        if ($observation) {
            $this->mock(EthereumEligibilityObservationCollector::class)->shouldReceive('collect')->once()->andReturn($observation);
        }
        $input = ['chain' => 'ethereum', 'chain_id' => 1, 'token_address' => $position->token_address,
            'policy_version' => EthereumAccountingEligibility::POLICY, 'decision' => $decision, 'review_source' => 'operator',
            'rationale' => 'Reviewed immutable historical accounting semantics.',
            'expected_review_id' => $head->current_review_id === null ? null : (int) $head->current_review_id,
            'expected_version' => (int) $head->version, 'submission_id' => (string) Str::uuid(),
            'observation_reference' => $observation ? 'server-observation' : null,
            'assertions' => $observation ? ['source_reference' => 'review:1', 'source_sha256' => str_repeat('b', 64),
                'historical_applicability' => 'All historical balance modification paths inspected against runtime source.',
                'non_proxy' => true, 'standard_transfer_accounting' => true, 'no_mutable_balance_behavior' => true] : []];
        $input['evidence_digest'] = EthereumEligibilityReviewService::digest($actor->id, $input, $observation);

        return app(EthereumEligibilityReviewService::class)->publish($input);
    }

    private function review(LivePosition $position, ?string $code = null): void
    {
        EthereumAccountingEligibility::query()->create(['chain' => 'ethereum', 'token_address' => $position->token_address,
            'policy_version' => EthereumAccountingEligibility::POLICY, 'status' => 'approved', 'review_source' => 'test:independent-code-review',
            'code_sha256' => $code ?? hash('sha256', hex2bin('6000')), 'reviewed_at' => now(),
            'reason' => 'Reviewed non-proxy immutable standard Transfer/balance semantics.']);
    }

    private function position(bool $review = true): LivePosition
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        app(EthereumReceiptReconciliationService::class)->apply($attempt, ['transaction_hash' => $attempt->transaction_hash,
            'succeeded' => true, 'block_number' => '100', 'gas_used' => '21000', 'effective_gas_price_wei' => '1', 'actual_network_fee_wei' => '21000']);
        $position = LivePosition::query()->where('ethereum_swap_attempt_id', $attempt->id)->sole();
        if ($review) {
            $this->review($position);
        }

        return $position;
    }

    private function provider(LivePosition $position): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);
        $this->blockHash = '0x'.str_repeat('b', 64);
        $level = DB::transactionLevel();
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($position, $level) {
            $this->assertSame($level, DB::transactionLevel(), 'RPC must not run under an accounting transaction.');
            if ($this->onRequest) {
                $hook = $this->onRequest;
                $this->onRequest = null;
                $hook();
            }
            if ($this->unavailable) {
                return Http::failedConnection();
            }
            $method = $request['method'];
            $params = $request['params'];
            $result = match ($method) {
                'eth_chainId' => '0x1',
                'eth_getTransactionReceipt' => $this->receiptResult($position),
                'eth_getBlockByNumber' => ['number' => in_array($params[0], ['latest', 'finalized'], true) ? $this->head : $this->blockNumber,
                    'hash' => in_array($params[0], ['latest', 'finalized'], true) ? $this->blockHash : ($this->canonicalBlockHash ?? $this->blockHash), 'timestamp' => '0x1234'],
                'eth_getTransactionByHash' => ['hash' => $position->entry_transaction_hash, 'from' => $position->wallet_address,
                    'to' => '0x'.str_repeat('3', 40), 'value' => '0x38d7ea4c68000', 'input' => '0x1234', 'chainId' => '0x1',
                    'blockHash' => $this->blockHash, 'blockNumber' => $this->blockNumber, 'transactionIndex' => $this->index, 'type' => '0x2'],
                'eth_getCode' => '0x6000',
                'eth_call' => '0x'.str_pad($this->decimals, 64, '0', STR_PAD_LEFT),
                default => throw new RuntimeException('Unexpected RPC method.'),
            };
            if ($method === 'eth_call') {
                $this->assertSame('0x313ce567', $params[0]['data'], 'Symbols are not an accounting dependency.');
            }
            if (in_array($method, ['eth_call', 'eth_getCode'], true)) {
                $this->assertSame(['blockHash' => $this->blockHash, 'requireCanonical' => true], $params[1]);
            }
            if ($method === 'eth_call' && $this->metadataUnavailable) {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000]]);
            }
            if ($method === 'eth_getBlockByNumber' && $params[0] === 'finalized' && $this->finalityUnavailable) {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602]]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }

    private function receiptResult(LivePosition $position): ?array
    {
        $this->receiptCalls++;
        if ($this->missing || ($this->disappearOnRecheck && $this->receiptCalls > 1)) {
            return null;
        }

        return ['transactionHash' => $position->entry_transaction_hash, 'blockHash' => $this->blockHash, 'blockNumber' => $this->blockNumber,
            'transactionIndex' => $this->index, 'status' => '0x1', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1',
            'logs' => [['address' => $position->token_address, 'topics' => [EthereumTransferExtractor::TOPIC,
                '0x'.str_repeat('0', 24).str_repeat('3', 40), '0x'.str_repeat('0', 24).substr($position->wallet_address, 2)],
                'data' => '0x'.str_pad($this->amount, 64, '0', STR_PAD_LEFT), 'logIndex' => '0x0']]];
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
