<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\LivePosition;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\Chains\EthereumChainAdapter;
use App\Services\EthereumOpportunityReservationService;
use App\Services\EthereumReceiptReconciliationService;
use App\Services\EthereumService;
use App\Services\EthereumWalletConnectionService;
use App\Services\LivePositionService;
use App\Services\PaperTradeEntryService;
use App\Services\PaperWalletService;
use App\Services\UserTelegramNotificationService;
use App\Services\UserTradingPreferenceService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LivePositionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_atomically_creates_one_pending_lot_without_touching_paper_balances(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $paperWallet = app(PaperWalletService::class)->forUser($user, 'ethereum');
        $before = $paperWallet->fresh()->getRawOriginal();
        $this->assertSame('confirmed', app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt)));
        $position = LivePosition::query()->sole();
        $this->assertSame('pending', $position->accounting_status);
        $this->assertNull($position->acquired_raw_amount);
        $this->assertNull($position->token_decimals);
        $this->assertSame($attempt->id, $position->ethereum_swap_attempt_id);
        $this->assertSame($opportunity->id, $position->trade_opportunity_id);
        $this->assertSame($attempt->wallet_address, $position->wallet_address);
        $this->assertSame('confirmed', $attempt->fresh()->status);
        $this->assertSame('executed', $opportunity->fresh()->status->value);
        $this->assertSame($before, $paperWallet->fresh()->getRawOriginal());
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertNull(app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt)));
        $this->assertSame($position->id, app(LivePositionService::class)->backfill($attempt)->id);
        $this->assertDatabaseCount('live_positions', 1);
        Http::assertNothingSent();
    }

    public function test_position_write_failure_rolls_back_confirmation_opportunity_and_event(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $this->mock(LivePositionService::class)->shouldReceive('ensureConfirmedEthereum')->once()->andReturnUsing(function ($lockedOpportunity, $lockedAttempt): void {
            $this->assertSame('confirmed', $lockedAttempt->status);
            $this->assertSame('executed', $lockedOpportunity->status->value);
            throw new RuntimeException('Simulated accounting write failure.');
        });
        try {
            app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt));
            $this->fail('Write failure must abort the whole receipt transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated accounting write failure.', $exception->getMessage());
        }
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame('executing', $opportunity->fresh()->status->value);
        $this->assertNull($opportunity->fresh()->executed_at);
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertSame(0, $opportunity->events()->where('action', 'live_execution_confirmed')->count());
    }

    #[DataProvider('excludedStates')]
    public function test_nonqualifying_executions_never_create_live_positions(string $state): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        if ($state === 'submitted') {
            $this->artisan('ethereum:backfill-live-positions')->assertSuccessful();
        } else {
            if (in_array($state, ['failed', 'cancelled', 'expired'], true)) {
                $attempt->update(['status' => $state]);
            } elseif ($state === 'auto') {
                $opportunity->update(['entry_mode' => 'auto']);
            } elseif ($state === 'paper') {
                $opportunity->update(['execution_mode' => 'paper']);
            } elseif ($state === 'signal') {
                $opportunity->update(['entry_mode' => 'signal']);
            } elseif ($state === 'manual') {
                DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->update(['trade_opportunity_id' => null]);
                $attempt->refresh();
            }
            app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt, $state !== 'reverted'));
        }
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public static function excludedStates(): array
    {
        return [['submitted'], ['failed'], ['cancelled'], ['expired'], ['reverted'], ['manual'], ['paper'], ['signal'], ['auto']];
    }

    public function test_backfill_creates_once_without_changing_execution_evidence(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $this->historicallyConfirmed($opportunity, $attempt);
        $before = $attempt->fresh()->getRawOriginal();
        $this->artisan('ethereum:backfill-live-positions')->expectsOutput('Created 1, existing 0, rejected 0 LIVE entry records.')->assertSuccessful();
        $first = LivePosition::query()->sole()->getRawOriginal();
        $this->artisan('ethereum:backfill-live-positions')->expectsOutput('Created 0, existing 1, rejected 0 LIVE entry records.')->assertSuccessful();
        $this->assertSame($first, LivePosition::query()->sole()->getRawOriginal());
        $this->assertSame($before, $attempt->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    #[DataProvider('conflictingEvidence')]
    public function test_backfill_reports_missing_or_conflicting_evidence(string $field): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $this->historicallyConfirmed($opportunity, $attempt);
        if ($field === 'link') {
            $opportunity->update(['execution_data' => ['ethereum_swap_attempt_id' => 999, 'transaction_hash' => $attempt->transaction_hash]]);
        } elseif ($field === 'existing') {
            LivePosition::factory()->forEthereumAttempt($attempt)->create(['token_address' => '0x'.str_repeat('9', 40)]);
        } elseif ($field === 'owner') {
            $opportunity->update(['user_id' => User::factory()->create()->id]);
        } elseif ($field === 'token') {
            $opportunity->update(['address' => '0x'.str_repeat('9', 40)]);
        } elseif ($field === 'hash') {
            $opportunity->update(['execution_data' => ['ethereum_swap_attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('a', 64)]]);
        } elseif ($field === 'fee') {
            $attempt->update(['actual_network_fee_wei' => '1']);
        } elseif ($field === 'corrupt_payload') {
            DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->update(['transaction_payload' => 'unreadable']);
        } elseif ($field === 'wallet') {
            DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->update(['wallet_address' => '0x'.str_repeat('9', 40)]);
        } else {
            $attempt->update([$field => null]);
        }
        $this->artisan('ethereum:backfill-live-positions')->assertFailed();
        $this->assertDatabaseCount('live_positions', $field === 'existing' ? 1 : 0);
        $this->assertSame('confirmed', $attempt->fresh()->status);
        $this->assertSame('executed', $opportunity->fresh()->status->value);
    }

    public static function conflictingEvidence(): array
    {
        return [['owner'], ['token'], ['hash'], ['fee'], ['corrupt_payload'], ['link'], ['existing'], ['wallet'], ['confirmed_at'], ['block_number'], ['actual_network_fee_wei'], ['transaction_payload']];
    }

    public function test_wallet_changes_and_disconnect_preserve_immutable_historical_identity(): void
    {
        [$user, $opportunity, $attempt, $wallet] = $this->submitted();
        $this->historicallyConfirmed($opportunity, $attempt);
        $wallet->update(['address' => '0x'.str_repeat('9', 40)]);
        $position = app(LivePositionService::class)->backfill($attempt);
        app(EthereumWalletConnectionService::class)->disconnect($user);
        $this->assertSame($attempt->wallet_address, $position->fresh()->wallet_address);
        $this->assertSame($position->id, app(LivePositionService::class)->backfill($attempt)->id);
        $this->expectException(DomainException::class);
        $position->update(['wallet_address' => $wallet->address]);
    }

    #[DataProvider('parents')]
    public function test_foreign_keys_block_cascades_that_would_erase_financial_history(string $parent): void
    {
        [$user, $opportunity, $attempt, $wallet] = $this->submitted();
        app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt));
        $model = match ($parent) {
            'user' => $user, 'wallet' => $wallet, 'opportunity' => $opportunity, 'attempt' => $attempt
        };
        try {
            DB::table($model->getTable())->where('id', $model->id)->delete();
            $this->fail('Referenced financial history must block deletion.');
        } catch (QueryException) {
            $this->assertDatabaseCount('live_positions', 1);
            $this->assertNotNull($attempt->fresh());
            $this->assertNotNull($opportunity->fresh());
        }
    }

    public static function parents(): array
    {
        return [['user'], ['wallet'], ['opportunity'], ['attempt']];
    }

    public function test_database_uniqueness_and_duplicate_workers_preserve_one_entry(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $worker = $attempt->fresh();
        app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt));
        $this->assertNull(app(EthereumReceiptReconciliationService::class)->apply($worker, $this->receipt($worker)));
        $position = LivePosition::query()->sole();
        foreach (['attempt', 'opportunity'] as $key) {
            $row = $position->getRawOriginal();
            unset($row['id']);
            if ($key === 'attempt') {
                $row['trade_opportunity_id'] = TradeOpportunity::factory()->create(['user_id' => $user->id])->id;
            }
            if ($key === 'opportunity') {
                $row['ethereum_swap_attempt_id'] = null;
            }
            try {
                DB::table('live_positions')->insert($row);
                $this->fail('Database uniqueness must reject a duplicate entry.');
            } catch (QueryException) {
                $this->assertDatabaseCount('live_positions', 1);
            }
        }
    }

    public function test_paper_tracker_continues_for_user_with_live_position(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt));
        $position = app(PaperTradeEntryService::class)->buy(['user_id' => $user->id, 'chain' => 'ethereum',
            'address' => '0x'.str_repeat('8', 40), 'symbol' => 'PAPER', 'entry_market_cap' => 100000, 'entry_price' => 1]);
        $liveBefore = LivePosition::query()->sole()->getRawOriginal();
        $this->mock(UserTelegramNotificationService::class)->shouldReceive('send')->zeroOrMoreTimes();
        $this->mock(EthereumChainAdapter::class)->shouldReceive('marketDataMany')->once()->andReturn([
            $position->address => ['available' => true, 'market_cap' => 85000, 'price_usd' => 0.85, 'liquidity_usd' => 10000]]);
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertLessThan(0, (float) $position->fresh()->trade_pnl_sol);
        $this->assertSame($liveBefore, LivePosition::query()->sole()->getRawOriginal());
    }

    public function test_conflicting_existing_lot_prevents_partial_receipt_commit(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $position = LivePosition::factory()->forEthereumAttempt($attempt)->create([
            'entry_block_number' => '100', 'entry_confirmed_at' => now(), 'wallet_address' => '0x'.str_repeat('9', 40)]);
        $before = $position->fresh()->getRawOriginal();
        try {
            app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt));
            $this->fail('Conflicting position identity must reject receipt application.');
        } catch (DomainException) {
            $this->assertSame('submitted', $attempt->fresh()->status);
            $this->assertSame('executing', $opportunity->fresh()->status->value);
            $this->assertNull($opportunity->fresh()->executed_at);
            $this->assertEquals($before, $position->fresh()->getRawOriginal());
        }
    }

    public function test_migration_round_trip_refuses_to_remove_financial_history(): void
    {
        $migration = require database_path('migrations/2026_09_22_114741_create_live_positions_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('live_positions'));
        $migration->up();
        [$user, $opportunity, $attempt] = $this->submitted();
        app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt));
        try {
            $migration->down();
            $this->fail('Rollback must preserve financial history.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('live_positions', 1);
        }
    }

    public function test_pending_lot_cannot_be_deleted_or_coerce_raw_amounts_to_floats(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        app(EthereumReceiptReconciliationService::class)->apply($attempt, $this->receipt($attempt));
        $position = LivePosition::query()->sole();
        $raw = '115792089237316195423570985008687907853269984665640564039457584007913129639935';
        DB::table('live_positions')->where('id', $position->id)->update(['acquired_raw_amount' => $raw]);
        $this->assertSame($raw, $position->fresh()->acquired_raw_amount);
        $this->expectException(DomainException::class);
        $position->delete();
    }

    public function test_reconciliation_rejects_conflicting_entry_and_continues_to_next_attempt(): void
    {
        [$user, $badOpportunity, $badAttempt] = $this->submitted();
        [$otherUser, $goodOpportunity, $goodAttempt] = $this->submitted();
        $conflict = LivePosition::factory()->forEthereumAttempt($badAttempt)->create([
            'entry_block_number' => '100', 'entry_confirmed_at' => now(), 'wallet_address' => '0x'.str_repeat('9', 40)]);
        $before = $conflict->fresh()->getRawOriginal();
        $this->assertLessThan($goodAttempt->id, $badAttempt->id);
        $rpc = $this->mock(EthereumService::class);
        $rpc->shouldReceive('getTransactionReceipt')->once()->ordered()->with($badAttempt->transaction_hash)->andReturn($this->receipt($badAttempt));
        $rpc->shouldReceive('getTransactionReceipt')->once()->ordered()->with($goodAttempt->transaction_hash)->andReturn($this->receipt($goodAttempt));

        $this->artisan('ethereum:reconcile-submitted-swaps')
            ->expectsOutput("Attempt {$badAttempt->id}: LIVE accounting evidence rejected; confirmation rolled back. Manual review required.")
            ->expectsOutput('Checked 2 submitted Ethereum swap attempt(s): 1 confirmed, 0 failed.')
            ->expectsOutput('Rejected 1 attempt(s) due to LIVE accounting evidence; other attempts were processed.')
            ->assertFailed();

        $this->assertSame('submitted', $badAttempt->fresh()->status);
        $this->assertNull($badAttempt->fresh()->confirmed_at);
        $this->assertNull($badAttempt->fresh()->block_number);
        $this->assertSame('executing', $badOpportunity->fresh()->status->value);
        $this->assertNull($badOpportunity->fresh()->executed_at);
        $this->assertSame(0, $badOpportunity->events()->where('action', 'live_execution_confirmed')->count());
        $this->assertSame($before, $conflict->fresh()->getRawOriginal());
        $this->assertSame('confirmed', $goodAttempt->fresh()->status);
        $this->assertSame('executed', $goodOpportunity->fresh()->status->value);
        $this->assertSame(1, $goodOpportunity->events()->where('action', 'live_execution_confirmed')->count());
        $position = LivePosition::query()->where('ethereum_swap_attempt_id', $goodAttempt->id)->sole();
        $this->assertSame($goodOpportunity->id, $position->trade_opportunity_id);
        $this->assertSame($otherUser->id, $position->user_id);
        $this->assertSame($goodAttempt->transaction_hash, $position->entry_transaction_hash);
        $this->assertSame('pending', $position->accounting_status);
        $this->assertNull($position->acquired_raw_amount);
        $this->assertDatabaseCount('live_positions', 2);
        Http::assertNothingSent();
    }

    public function test_reconciliation_command_propagates_unexpected_accounting_failure(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $this->mock(EthereumService::class)->shouldReceive('getTransactionReceipt')->once()
            ->with($attempt->transaction_hash)->andReturn($this->receipt($attempt));
        $this->mock(LivePositionService::class)->shouldReceive('ensureConfirmedEthereum')->once()
            ->andThrow(new RuntimeException('Unexpected accounting infrastructure failure.'));

        try {
            $this->artisan('ethereum:reconcile-submitted-swaps')->run();
            $this->fail('Unexpected failures must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unexpected accounting infrastructure failure.', $exception->getMessage());
        }
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame('executing', $opportunity->fresh()->status->value);
        $this->assertDatabaseCount('live_positions', 0);
    }

    #[DataProvider('canonicalIds')]
    public function test_backfill_accepts_canonical_attempt_id(bool $asString): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $this->historicallyConfirmed($opportunity, $attempt);
        $opportunity->update(['execution_data' => ['ethereum_swap_attempt_id' => $asString ? (string) $attempt->id : $attempt->id,
            'transaction_hash' => $attempt->transaction_hash]]);

        $this->artisan('ethereum:backfill-live-positions')->assertSuccessful();

        $this->assertSame($attempt->id, LivePosition::query()->sole()->ethereum_swap_attempt_id);
    }

    public static function canonicalIds(): array
    {
        return ['integer' => [false], 'digit string' => [true]];
    }

    #[DataProvider('malformedIds')]
    public function test_backfill_rejects_malformed_attempt_id(mixed $value): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $this->historicallyConfirmed($opportunity, $attempt);
        if (is_string($value)) {
            $value = str_replace('{id}', (string) $attempt->id, $value);
        }
        $opportunity->update(['execution_data' => ['ethereum_swap_attempt_id' => $value, 'transaction_hash' => $attempt->transaction_hash]]);
        $before = $opportunity->fresh()->getRawOriginal();

        $this->artisan('ethereum:backfill-live-positions')
            ->expectsOutput("Attempt {$attempt->id}: Insufficient or conflicting confirmed Ethereum entry evidence.")
            ->expectsOutput('Created 0, existing 0, rejected 1 LIVE entry records.')->assertFailed();

        $this->assertDatabaseCount('live_positions', 0);
        $this->assertSame($before, $opportunity->fresh()->getRawOriginal());
    }

    public static function malformedIds(): array
    {
        return [
            'partial numeric' => ['12wrong'], 'matching prefix' => ['{id}wrong'],
            'decimal string' => ['12.9'], 'matching decimal prefix' => ['{id}.9'],
            'float' => [12.9], 'matching fractional prefix' => [1.5], 'true' => [true], 'false' => [false],
            'zero' => [0], 'negative' => [-1], 'empty' => [''], 'whitespace' => ['  '],
            'padded' => [' {id} '], 'leading zero' => ['0{id}'], 'scientific' => ['{id}e0'],
            'null' => [null], 'array' => [[1]], 'object' => [(object) ['id' => 1]],
            'oversized' => ['184467440737095516160000000000000000000'],
        ];
    }

    #[DataProvider('invalidWalletBindings')]
    public function test_backfill_rejects_wrong_wallet_owner_or_chain(string $field): void
    {
        [$user, $opportunity, $attempt, $wallet] = $this->submitted();
        $this->historicallyConfirmed($opportunity, $attempt);
        DB::table('connected_wallets')->where('id', $wallet->id)->update([
            $field => $field === 'user_id' ? User::factory()->create()->id : 'solana']);

        $this->artisan('ethereum:backfill-live-positions')->assertFailed();

        $this->assertDatabaseCount('live_positions', 0);
    }

    public static function invalidWalletBindings(): array
    {
        return [['user_id'], ['chain']];
    }

    public function test_idempotent_backfill_preserves_accounting_enrichment(): void
    {
        [$user, $opportunity, $attempt] = $this->submitted();
        $this->historicallyConfirmed($opportunity, $attempt);
        $position = app(LivePositionService::class)->backfill($attempt);
        $position->acquired_raw_amount = '1000000000000000000';
        $position->token_decimals = 18;
        $position->save();
        $before = $position->fresh()->getRawOriginal();

        $repeated = app(LivePositionService::class)->backfill($attempt);

        $this->assertSame($before, $repeated->getRawOriginal());
        $this->assertDatabaseCount('live_positions', 1);
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

    private function receipt(EthereumSwapAttempt $attempt, bool $success = true): array
    {
        return ['transaction_hash' => $attempt->transaction_hash, 'succeeded' => $success, 'block_number' => '100', 'gas_used' => '21000',
            'effective_gas_price_wei' => '1', 'actual_network_fee_wei' => '21000'];
    }

    private function historicallyConfirmed(TradeOpportunity $opportunity, EthereumSwapAttempt $attempt): void
    {
        $attempt->update(['status' => 'confirmed', 'confirmed_at' => now(), 'block_number' => '100', 'gas_used' => '21000', 'effective_gas_price_wei' => '1', 'actual_network_fee_wei' => '21000']);
        $opportunity->update(['status' => 'executed', 'executed_at' => now(), 'execution_data' => ['ethereum_swap_attempt_id' => $attempt->id, 'transaction_hash' => $attempt->transaction_hash]]);
    }
}
