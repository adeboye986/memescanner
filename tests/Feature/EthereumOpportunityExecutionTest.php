<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\EthereumOpportunityReservationService;
use App\Services\EthereumReceiptReconciliationService;
use App\Services\EthereumService;
use App\Services\EthereumSwapPreparationService;
use App\Services\UserTradingPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EthereumOpportunityExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    private const HASH = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_handoff_is_owned_one_time_and_cannot_replace_trade_parameters(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertNotFound();
        $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity), ['attempt_id' => 999, 'value' => '999'])
            ->assertOk()->assertJsonPath('order.attempt_id', $attempt->id)->assertJsonPath('order.transaction.value', '1000000000000000');
        $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertStatus(409);
        $this->assertNotNull($attempt->fresh()->signing_requested_at);
        $this->assertNull($attempt->fresh()->transaction_hash);
        $this->assertNull($opportunity->fresh()->executed_at);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidHandoffs')]
    public function test_handoff_rechecks_authorization_and_expiry(string $change): void
    {
        [$user, $opportunity, $attempt, $wallet] = $this->prepared();
        match ($change) {
            'expiry' => $attempt->update(['expires_at' => now()->subSecond()]),
            'wallet' => $wallet->update(['verified_at' => null]),
            'account' => $wallet->update(['address' => '0x'.str_repeat('9', 40)]),
            'kill' => app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]),
            'mode' => $user->tradingPreference()->update(['entry_mode' => 'auto']),
            'ignored' => $opportunity->update(['status' => 'ignored']),
        };
        $response = $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity));
        $this->assertContains($response->status(), [409, 422]);
        $this->assertNull($attempt->fresh()->signing_requested_at);
        Http::assertNothingSent();
    }

    public static function invalidHandoffs(): array
    {
        return [['expiry'], ['wallet'], ['account'], ['kill'], ['mode'], ['ignored']];
    }

    #[DataProvider('transactionChanges')]
    public function test_linked_submission_uses_existing_rpc_binding(string $field): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $transaction = ['chain_id' => '1', 'hash' => self::HASH, 'from' => self::WALLET,
            'to' => $attempt->transaction_payload['to'], 'value' => '1000000000000000', 'input' => '0x1234'];
        if ($field !== 'valid') {
            $transaction[$field] = $field === 'chain_id' ? '137' : ($field === 'value' ? '1' : '0x'.str_repeat('9', $field === 'input' ? 4 : 40));
        }
        $level = DB::transactionLevel();
        $this->partialMock(EthereumService::class)->shouldReceive('getTransactionByHash')->once()->with(self::HASH)->andReturnUsing(function () use ($transaction, $level): array {
            $this->assertSame($level, DB::transactionLevel());

            return $transaction;
        });
        $report = ['attempt_id' => $attempt->id, 'transaction_hash' => self::HASH, 'opportunity_id' => 999];
        $this->actingAs(User::factory()->create())->postJson(route('wallets.ethereum.submitted'), $report)->assertNotFound();
        $this->actingAs($user)->postJson(route('wallets.ethereum.submitted'), $report)->assertStatus($field === 'valid' ? 200 : 422);
        if ($field === 'valid') {
            $this->postJson(route('wallets.ethereum.submitted'), $report)->assertOk();
            $this->postJson(route('wallets.ethereum.submitted'), [...$report, 'transaction_hash' => '0x'.str_repeat('b', 64)])->assertUnprocessable();
        }
        $this->assertSame('executing', $opportunity->fresh()->status->value);
        $this->assertNull($opportunity->fresh()->executed_at);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public static function transactionChanges(): array
    {
        return [['valid'], ['from'], ['to'], ['value'], ['input'], ['chain_id']];
    }

    #[DataProvider('outcomes')]
    public function test_receipt_is_the_only_success_transition(string $outcome): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $attempt->update(['status' => 'submitted', 'transaction_hash' => self::HASH, 'submitted_at' => now()]);
        $level = DB::transactionLevel();
        $mock = $this->mock(EthereumService::class)->shouldReceive('getTransactionReceipt')->once()->with(self::HASH);
        $mock->andReturnUsing(function () use ($outcome, $level): ?array {
            $this->assertSame($level, DB::transactionLevel());
            if ($outcome === 'error') {
                throw new RuntimeException('private RPC information');
            }

            return $outcome === 'pending' ? null : $this->receipt($outcome === 'success');
        });
        $this->assertNull($opportunity->fresh()->executed_at);
        $this->artisan('ethereum:reconcile-submitted-swaps')->assertSuccessful();
        $this->assertSame(match ($outcome) {
            'success' => 'executed', 'revert' => 'failed', default => 'executing'
        }, $opportunity->fresh()->status->value);
        $this->assertSame(match ($outcome) {
            'success' => 'confirmed', 'revert' => 'failed', default => 'submitted'
        }, $attempt->fresh()->status);
        $this->assertSame($outcome === 'success', $opportunity->fresh()->executed_at !== null);
        if (in_array($outcome, ['success', 'revert'], true)) {
            $before = $opportunity->fresh()->getRawOriginal();
            $count = $opportunity->events()->count();
            $this->artisan('ethereum:reconcile-submitted-swaps')->assertSuccessful();
            $this->assertSame($before, $opportunity->fresh()->getRawOriginal());
            $this->assertSame($count, $opportunity->events()->count());
            $this->assertSame('21000', $attempt->fresh()->gas_used);
        }
        $this->assertDatabaseCount('paper_positions', 0);
        Http::assertNothingSent();
    }

    public static function outcomes(): array
    {
        return [['success'], ['revert'], ['pending'], ['error']];
    }

    #[DataProvider('receiptRaces')]
    public function test_reconciler_rechecks_state_after_rpc(string $race): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $attempt->update(['status' => 'submitted', 'transaction_hash' => self::HASH]);
        $this->mock(EthereumService::class)->shouldReceive('getTransactionReceipt')->once()->andReturnUsing(function () use ($race, $opportunity, $attempt, $user): array {
            if ($race === 'terminal') {
                $opportunity->update(['status' => 'ignored']);
            }
            if ($race === 'link') {
                $opportunity->update(['execution_data' => ['ethereum_swap_attempt_id' => 999]]);
            }
            if ($race === 'hash') {
                $attempt->update(['transaction_hash' => '0x'.str_repeat('b', 64)]);
            }
            if ($race === 'worker') {
                app(EthereumReceiptReconciliationService::class)->apply($attempt->fresh(), $this->receipt(true));
            }
            if ($race === 'cleanup') {
                $this->actingAs($user)->postJson(route('wallets.ethereum.cancelled'), ['attempt_id' => $attempt->id])->assertUnprocessable();
                $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
            }

            return $this->receipt(true);
        });
        $this->artisan('ethereum:reconcile-submitted-swaps')->assertSuccessful();
        $success = in_array($race, ['worker', 'cleanup'], true);
        $this->assertSame($success ? 'confirmed' : 'submitted', $attempt->fresh()->status);
        $this->assertSame($success, $opportunity->fresh()->executed_at !== null);
        $this->assertSame($success ? 1 : 0, $opportunity->events()->where('action', 'live_execution_confirmed')->count());
    }

    public static function receiptRaces(): array
    {
        return [['terminal'], ['link'], ['hash'], ['worker'], ['cleanup']];
    }

    public function test_detail_exposes_controls_but_never_payload_or_lease_and_submitted_is_not_success(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $this->actingAs($user)->get(route('opportunities.show', $opportunity))->assertOk()->assertSee('Confirm &amp; Buy', false)->assertDontSee('0x1234');
        $attempt->update(['status' => 'submitted', 'transaction_hash' => self::HASH]);
        $this->get(route('opportunities.show', $opportunity))->assertOk()->assertSee('awaiting blockchain confirmation')->assertDontSee('Executed / confirmed.')->assertDontSee('Confirm &amp; Buy', false);
        $this->actingAs(User::factory()->create())->get(route('opportunities.show', $opportunity))->assertNotFound();
    }

    #[DataProvider('submissionRaces')]
    public function test_reporting_rechecks_link_and_terminal_state_but_recovers_expiry(string $race): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk();
        $this->partialMock(EthereumService::class)->shouldReceive('getTransactionByHash')->once()->andReturnUsing(function () use ($race, $opportunity, $attempt): array {
            if ($race === 'expired') {
                $attempt->update(['expires_at' => now()->subSecond()]);
                $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
            } elseif ($race === 'link') {
                $opportunity->update(['execution_data' => ['ethereum_swap_attempt_id' => 999]]);
            } else {
                $opportunity->update(['status' => $race]);
            }

            return ['chain_id' => '1', 'hash' => self::HASH, 'from' => self::WALLET,
                'to' => $attempt->transaction_payload['to'], 'value' => '1000000000000000', 'input' => '0x1234'];
        });
        $this->postJson(route('wallets.ethereum.submitted'), ['attempt_id' => $attempt->id, 'transaction_hash' => self::HASH])->assertStatus($race === 'expired' ? 200 : 422);
        $this->assertSame($race === 'expired' ? self::HASH : null, $attempt->fresh()->transaction_hash);
        $this->assertSame($race === 'expired' || $race === 'link' ? 'executing' : $race, $opportunity->fresh()->status->value);
        $this->assertNull($opportunity->fresh()->executed_at);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public static function submissionRaces(): array
    {
        return [['ignored'], ['failed'], ['executed'], ['link'], ['expired']];
    }

    public function test_known_rejection_cancels_without_resurrection_or_new_handoff(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $claim = $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk()->json('order.signing_claim_token');
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'arm']), ['signing_claim_token' => $claim])->assertOk();
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'rejected']), ['signing_claim_token' => $claim, 'rejection_code' => 4001])->assertOk();
        $this->assertSame('ignored', $opportunity->fresh()->status->value);
        $this->assertSame('cancelled', $attempt->fresh()->status);
        $this->postJson(route('wallets.ethereum.submitted'), ['attempt_id' => $attempt->id, 'transaction_hash' => self::HASH])->assertUnprocessable();
        $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertStatus(409);
        $this->assertNull($opportunity->fresh()->executed_at);
        Http::assertNothingSent();
    }

    public function test_already_executed_authoritative_receipt_preserves_timestamp_and_events(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $attempt->update(['status' => 'submitted', 'transaction_hash' => self::HASH]);
        $opportunity->update(['status' => 'executed', 'executed_at' => now()->subMinute(),
            'execution_data' => ['ethereum_swap_attempt_id' => $attempt->id, 'transaction_hash' => self::HASH]]);
        $before = $opportunity->fresh()->getRawOriginal();
        $count = $opportunity->events()->count();
        $service = app(EthereumReceiptReconciliationService::class);
        $this->assertNull($service->apply($attempt, [...$this->receipt(true), 'transaction_hash' => '0x'.str_repeat('b', 64)]));
        $this->assertSame('confirmed', $service->apply($attempt, $this->receipt(true)));
        $this->assertNull($service->apply($attempt, $this->receipt(true)));
        $this->assertSame($before, $opportunity->fresh()->getRawOriginal());
        $this->assertSame($count, $opportunity->events()->count());
    }

    public function test_signing_guard_migration_round_trip_and_unresolved_rollback_refusal(): void
    {
        $migration = require database_path('migrations/2026_09_22_104552_add_signing_requested_at_to_ethereum_swap_attempts_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('ethereum_swap_attempts', 'signing_requested_at'));
        $migration->up();
        [$user, $opportunity, $attempt] = $this->prepared();
        $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk();
        foreach (['prepared', 'expired'] as $status) {
            $attempt->update(['status' => $status]);
            try {
                $migration->down();
                $this->fail('Unresolved signing guard must survive rollback.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('Resolve signing outcomes', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasColumn('ethereum_swap_attempts', 'signing_requested_at'));
        }
        $attempt->update(['status' => 'submitted', 'transaction_hash' => self::HASH]);
        $before = $attempt->fresh()->getRawOriginal();
        unset($before['signing_requested_at'], $before['signing_claim_hash'], $before['signing_armed_at']);
        $migration->down();
        $this->assertSame($before, $attempt->fresh()->getRawOriginal());
        $migration->up();
    }

    public function test_claim_release_is_owned_exact_unarmed_and_allows_only_a_new_valid_claim(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $claim = $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk()->json('order.signing_claim_token');
        $this->assertSame(hash('sha256', $claim), $attempt->fresh()->signing_claim_hash);
        $this->assertArrayNotHasKey('signing_claim_hash', $attempt->fresh()->toArray());
        $url = route('opportunities.ethereum.signing', [$opportunity, 'release']);
        $this->postJson($url, ['signing_claim_token' => str_repeat('0', 64)])->assertStatus(409);
        $this->actingAs(User::factory()->create())->postJson($url, ['signing_claim_token' => $claim])->assertNotFound();
        $this->actingAs($user)->postJson($url, ['signing_claim_token' => $claim])->assertOk()->assertJsonPath('armed', false);
        $this->assertNull($attempt->fresh()->signing_requested_at);
        $next = $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk()->json('order.signing_claim_token');
        $this->assertNotSame($claim, $next);
        $this->postJson($url, ['signing_claim_token' => $claim])->assertStatus(409);
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'arm']), ['signing_claim_token' => $claim])->assertStatus(409);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_arm_is_exact_once_and_ordinary_cancellation_never_discards_a_possible_broadcast(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $claim = $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk()->json('order.signing_claim_token');
        $this->postJson(route('wallets.ethereum.cancelled'), ['attempt_id' => $attempt->id])->assertUnprocessable();
        $arm = route('opportunities.ethereum.signing', [$opportunity, 'arm']);
        $this->postJson($arm, ['signing_claim_token' => str_repeat('0', 64)])->assertStatus(409);
        $this->postJson($arm, ['signing_claim_token' => $claim])->assertOk()->assertJsonPath('armed', true);
        $this->postJson($arm, ['signing_claim_token' => $claim])->assertStatus(409);
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'release']), ['signing_claim_token' => $claim])->assertStatus(409);
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'rejected']), ['signing_claim_token' => $claim, 'rejection_code' => -32000])->assertUnprocessable();
        $this->postJson(route('wallets.ethereum.cancelled'), ['attempt_id' => $attempt->id])->assertUnprocessable();
        $this->assertSame('prepared', $attempt->fresh()->status);
        $this->assertSame('executing', $opportunity->fresh()->status->value);
        $attempt->update(['expires_at' => now()->subSecond()]);
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->assertNotNull($attempt->fresh()->signing_armed_at);
        $this->assertSame(hash('sha256', $claim), $attempt->fresh()->signing_claim_hash);
        $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertStatus(409);
        $this->partialMock(EthereumService::class)->shouldReceive('getTransactionByHash')->once()->andReturn([
            'chain_id' => '1', 'hash' => self::HASH, 'from' => self::WALLET, 'to' => $attempt->transaction_payload['to'], 'value' => '1000000000000000', 'input' => '0x1234']);
        $this->postJson(route('wallets.ethereum.submitted'), ['attempt_id' => $attempt->id, 'transaction_hash' => self::HASH])->assertOk();
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame('executing', $opportunity->fresh()->status->value);
        $this->assertNull($opportunity->fresh()->executed_at);
    }

    public function test_arm_checks_server_expiry_and_released_expired_claim_cannot_be_reissued(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $claim = $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk()->json('order.signing_claim_token');
        $attempt->update(['expires_at' => now()->subSecond()]);
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'arm']), ['signing_claim_token' => $claim])->assertStatus(409);
        $this->assertNull($attempt->fresh()->signing_armed_at);
        $this->assertSame('expired', $opportunity->fresh()->status->value);
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'release']), ['signing_claim_token' => $claim])->assertOk();
        $this->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertStatus(409);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidReceiptEnvelopes')]
    public function test_invalid_receipt_envelope_preserves_submitted_and_executing(string $change): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $attempt->update(['status' => 'submitted', 'transaction_hash' => self::HASH]);
        config(['services.ethereum.rpc_url' => 'https://rpc.test']);
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['transactionHash' => self::HASH, 'status' => '0x1', 'blockNumber' => '0x10', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1']];
        if ($change === 'error') {
            $body['error'] = ['code' => -32000, 'message' => 'private provider failure'];
        } elseif ($change === 'version') {
            $body['jsonrpc'] = '1.0';
        } elseif ($change === 'id') {
            $body['id'] = 999;
        } else {
            unset($body['result']);
        }
        Http::fake(['https://rpc.test' => Http::response($body)]);
        $this->artisan('ethereum:reconcile-submitted-swaps')->assertSuccessful();
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame('executing', $opportunity->fresh()->status->value);
        $this->assertNull($opportunity->fresh()->executed_at);
    }

    public static function invalidReceiptEnvelopes(): array
    {
        return [['error'], ['version'], ['id'], ['missing']];
    }

    #[DataProvider('invalidHandoffs')]
    public function test_arm_rechecks_current_controls_after_claim(string $change): void
    {
        [$user, $opportunity, $attempt, $wallet] = $this->prepared();
        $claim = $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk()->json('order.signing_claim_token');
        match ($change) {
            'expiry' => $attempt->update(['expires_at' => now()->subSecond()]),
            'wallet' => $wallet->update(['verified_at' => null]),
            'account' => $wallet->update(['address' => '0x'.str_repeat('9', 40)]),
            'kill' => app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]),
            'mode' => $user->tradingPreference()->update(['entry_mode' => 'auto']),
            'ignored' => $opportunity->update(['status' => 'ignored']),
        };
        $response = $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'arm']), ['signing_claim_token' => $claim]);
        $this->assertContains($response->status(), [409, 422]);
        $this->assertNull($attempt->fresh()->signing_armed_at);
        Http::assertNothingSent();
    }

    public function test_arm_checks_expiry_after_blocking_validation_and_rollback_preserves_armed_claim(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $claim = $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk()->json('order.signing_claim_token');
        $this->partialMock(EthereumSwapPreparationService::class)->shouldReceive('assertWallet')->once()->andReturnUsing(function (): void {
            $this->travel(2)->minutes();
        });
        $this->postJson(route('opportunities.ethereum.signing', [$opportunity, 'arm']), ['signing_claim_token' => $claim])->assertStatus(409);
        $this->assertNull($attempt->fresh()->signing_armed_at);
        $attempt->update(['signing_armed_at' => now()]);
        $migration = require database_path('migrations/2026_09_22_104552_add_signing_requested_at_to_ethereum_swap_attempts_table.php');
        try {
            $migration->down();
            $this->fail('Armed claim must block rollback.');
        } catch (\LogicException) {
            $this->assertNotNull($attempt->fresh()->signing_armed_at);
            $this->assertSame(hash('sha256', $claim), $attempt->fresh()->signing_claim_hash);
        }
        $this->travelBack();
    }

    public function test_foreign_or_stale_claim_cannot_arm_or_reject(): void
    {
        [$user, $opportunity, $attempt] = $this->prepared();
        $claim = $this->actingAs($user)->postJson(route('opportunities.ethereum.confirm', $opportunity))->assertOk()->json('order.signing_claim_token');
        foreach (['arm', 'rejected'] as $action) {
            $payload = ['signing_claim_token' => $claim];
            if ($action === 'rejected') {
                $payload['rejection_code'] = 4001;
            }
            $url = route('opportunities.ethereum.signing', [$opportunity, $action]);
            $this->actingAs(User::factory()->create())->postJson($url, $payload)->assertNotFound();
            $this->actingAs($user)->postJson($url, [...$payload, 'signing_claim_token' => str_repeat('0', 64)])->assertStatus(409);
        }
        $this->assertSame('prepared', $attempt->fresh()->status);
        $this->assertSame('executing', $opportunity->fresh()->status->value);
    }

    private function prepared(): array
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => false, 'risk.max_trade_amount' => '0.1', 'risk.max_slippage_percent' => 1]);
        app(UserTradingPreferenceService::class)->forUser($user)->update(['execution_mode' => 'live', 'entry_mode' => 'confirm', 'trading_enabled' => true]);
        $wallet = ConnectedWallet::query()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'address' => self::WALLET,
            'address_hash' => ConnectedWallet::addressHash('ethereum', self::WALLET), 'verified_at' => now()]);
        $opportunity = TradeOpportunity::factory()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'address' => '0x'.str_repeat('2', 40),
            'scanner' => 'new-token', 'execution_mode' => 'live', 'entry_mode' => 'confirm', 'status' => 'pending_confirmation', 'qualified_at' => now()]);
        $attempt = app(EthereumOpportunityReservationService::class)->reserve($opportunity, $user, ['sell_amount_wei' => '1000000000000000', 'slippage_bps' => 100]);
        $attempt->update(['status' => 'prepared', 'expires_at' => now()->addMinute(), 'transaction_payload' => [
            'from' => self::WALLET, 'to' => '0x'.str_repeat('3', 40), 'value' => '1000000000000000', 'data' => '0x1234', 'gas' => '21000', 'gasPrice' => '1', 'chainId' => '1']]);

        return [$user, $opportunity, $attempt, $wallet];
    }

    private function receipt(bool $success): array
    {
        return ['transaction_hash' => self::HASH, 'succeeded' => $success, 'block_number' => '100', 'gas_used' => '21000',
            'effective_gas_price_wei' => '1', 'actual_network_fee_wei' => '21000'];
    }
}
