<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\EntryPolicy;
use App\Services\EthereumOpportunityReservationService;
use App\Services\OpportunityActionService;
use App\Services\TradeExecutionManager;
use App\Services\UserTradingPreferenceService;
use Closure;
use DomainException;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EthereumOpportunityReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_confirmation_only_reserves_explicit_inputs_without_external_calls(): void
    {
        Http::preventStrayRequests();
        [$user, $opportunity, $wallet] = $this->pending();
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())
            ->assertRedirect(route('opportunities.show', $opportunity))->assertSessionHas('success');

        $attempt = EthereumSwapAttempt::query()->sole();
        $this->assertSame($opportunity->id, $attempt->trade_opportunity_id);
        $this->assertSame($user->id, $attempt->user_id);
        $this->assertSame($wallet->id, $attempt->connected_wallet_id);
        $this->assertSame(strtolower($wallet->address), $attempt->wallet_address);
        $this->assertSame(strtolower($opportunity->address), $attempt->buy_token);
        $this->assertSame('1000000000000000', $attempt->sell_amount_wei);
        $this->assertEquals(100, $attempt->slippage_bps);
        $this->assertSame('reserved', $attempt->status);
        $this->assertNull($attempt->transaction_payload);
        $this->assertNull($attempt->expires_at);
        $this->assertNull($attempt->transaction_hash);
        $this->assertSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        $this->assertNull($opportunity->fresh()->executed_at);
        $this->assertNull($opportunity->fresh()->paper_position_id);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseHas('trade_opportunity_events', ['action' => 'live_execution_reserved', 'to_status' => 'executing']);
        Http::assertNothingSent();
    }

    public function test_retry_with_stale_model_and_later_expired_qualification_returns_same_reservation(): void
    {
        [$user, $opportunity] = $this->pending();
        $first = $this->reserve($opportunity, $user);
        $this->travel(10)->minutes();
        $retry = $this->reserve($opportunity, $user);
        $this->assertTrue($first->is($retry));
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertDatabaseCount('trade_opportunity_events', 1);
    }

    #[DataProvider('changedInputs')]
    public function test_retry_cannot_change_approved_inputs(string $field, mixed $value): void
    {
        [$user, $opportunity] = $this->pending();
        $first = $this->reserve($opportunity, $user)->fresh();
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), [$field => $value] + $this->input())->assertSessionHas('error');
        $this->assertSame($first->getAttributes(), $first->fresh()->getAttributes());
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public static function changedInputs(): array
    {
        return [['sell_amount_wei', '2000000000000000'], ['slippage_bps', 50]];
    }

    public function test_reconnected_wallet_cannot_take_over_existing_reservation(): void
    {
        [$user, $opportunity, $wallet] = $this->pending();
        $first = $this->reserve($opportunity, $user);
        $wallet->update(['address' => '0x'.str_repeat('3', 40)]);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('error');
        $this->assertSame($first->wallet_address, $first->fresh()->wallet_address);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public function test_other_user_cannot_approve_opportunity(): void
    {
        [, $opportunity] = $this->pending();
        $this->actingAs(User::factory()->create())->post(route('opportunities.approve', $opportunity), $this->input())->assertNotFound();
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
    }

    #[DataProvider('ineligibleOpportunities')]
    public function test_ineligible_opportunity_cannot_enter_ethereum_reservation(array $changes): void
    {
        [$user, $opportunity] = $this->pending();
        $opportunity->update($changes);
        try {
            $this->reserve($opportunity, $user);
            $this->fail('Ineligible opportunity was accepted.');
        } catch (DomainException) {
            $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        }
    }

    public static function ineligibleOpportunities(): array
    {
        return [
            'solana' => [['chain' => Chain::Solana]],
            'signal' => [['entry_mode' => EntryMode::Signal]],
            'auto' => [['entry_mode' => EntryMode::Auto]],
            'qualified' => [['status' => TradeOpportunityStatus::Qualified]],
            'ignored' => [['status' => TradeOpportunityStatus::Ignored]],
            'executed' => [['status' => TradeOpportunityStatus::Executed]],
            'expired' => [['status' => TradeOpportunityStatus::Expired]],
            'failed' => [['status' => TradeOpportunityStatus::Failed]],
            'already reserved without attempt' => [['status' => TradeOpportunityStatus::Executing]],
            'unowned' => [['user_id' => null]],
        ];
    }

    #[DataProvider('unavailableWallets')]
    public function test_unavailable_wallet_is_rejected(string $condition): void
    {
        [$user, $opportunity, $wallet] = $this->pending();
        match ($condition) {
            'missing' => $wallet->delete(),
            'unverified' => $wallet->update(['verified_at' => null]),
            'disconnected' => $wallet->update(['disconnected_at' => now()]),
            'wrong chain' => $wallet->update(['chain' => Chain::Solana]),
            'wrong owner' => $wallet->update(['user_id' => User::factory()->create()->id]),
        };
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('error');
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
    }

    public static function unavailableWallets(): array
    {
        return array_map(fn ($value) => [$value], ['missing', 'unverified', 'disconnected', 'wrong chain', 'wrong owner']);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_missing_explicit_inputs_are_rejected(string $field, mixed $value): void
    {
        [$user, $opportunity] = $this->pending();
        $this->actingAs($user)->postJson(route('opportunities.approve', $opportunity), [$field => $value] + $this->input())
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
    }

    public static function invalidInputs(): array
    {
        return [
            ['sell_amount_wei', null], ['sell_amount_wei', '0'], ['sell_amount_wei', '-1'],
            ['sell_amount_wei', '100000000000000001'], ['sell_amount_wei', 1000], ['sell_amount_wei', '1e15'],
            ['slippage_bps', null], ['slippage_bps', 0], ['slippage_bps', -1],
            ['slippage_bps', 151], ['slippage_bps', 501], ['slippage_bps', 1.5],
        ];
    }

    #[DataProvider('disabledPreferences')]
    public function test_trading_controls_prevent_reservation(array $changes, bool $killSwitch): void
    {
        [$user, $opportunity] = $this->pending();
        $user->tradingPreference()->update($changes);
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => $killSwitch]);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('error');
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
    }

    public static function disabledPreferences(): array
    {
        return [
            [['trading_enabled' => false], false],
            [['trading_enabled' => true], true],
            [['entry_mode' => EntryMode::Auto], false],
            [['entry_mode' => EntryMode::Signal], false],
        ];
    }

    #[DataProvider('qualificationAges')]
    public function test_freshness_uses_configured_qualification_age(int $age, bool $accepted): void
    {
        $this->freezeTime();
        config(['services.ethereum.opportunity_max_age_seconds' => 60]);
        [$user, $opportunity] = $this->pending();
        $opportunity->update(['qualified_at' => now()->subSeconds($age)]);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())
            ->assertSessionHas($accepted ? 'success' : 'error');
        $this->assertDatabaseCount('ethereum_swap_attempts', $accepted ? 1 : 0);
    }

    public static function qualificationAges(): array
    {
        return [[59, true], [60, true], [61, false], [-1, false]];
    }

    public function test_auto_ethereum_live_executor_remains_disabled(): void
    {
        [$user, $opportunity] = $this->pending();
        $opportunity->update(['entry_mode' => EntryMode::Auto]);
        try {
            app(TradeExecutionManager::class)->execute($opportunity, false);
            $this->fail('AUTO LIVE was enabled.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Live execution is not enabled yet.', $exception->getMessage());
            $this->assertDatabaseCount('ethereum_swap_attempts', 0);
            $this->assertDatabaseCount('paper_positions', 0);
        }
    }

    public function test_telegram_style_approval_without_inputs_requires_web_handoff(): void
    {
        [$user, $opportunity] = $this->pending();
        try {
            app(OpportunityActionService::class)->approve($opportunity, $user);
            $this->fail('Approval without explicit inputs was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('authenticated web flow', $exception->getMessage());
            $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
            $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        }
    }

    public function test_approval_winning_ignore_race_prevents_ignore(): void
    {
        [$user, $opportunity] = $this->pending();
        $this->reserve($opportunity, $user);
        $this->actingAs($user)->post(route('opportunities.ignore', $opportunity))->assertSessionHas('error');
        $this->assertSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public function test_ignore_winning_approval_race_prevents_reservation(): void
    {
        [$user, $opportunity] = $this->pending();
        app(OpportunityActionService::class)->ignore($opportunity, $user);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('error');
        $this->assertSame(TradeOpportunityStatus::Ignored, $opportunity->fresh()->status);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
    }

    public function test_database_unique_constraint_prevents_second_intent_even_outside_service(): void
    {
        [$user, $opportunity] = $this->pending();
        $attempt = $this->reserve($opportunity, $user);
        $attributes = $attempt->getAttributes();
        unset($attributes['id']);
        $this->expectException(QueryException::class);
        DB::table('ethereum_swap_attempts')->insert($attributes);
    }

    #[DataProvider('invalidBindings')]
    public function test_model_rejects_invalid_opportunity_wallet_and_owner_binding(string $condition): void
    {
        [$user, $opportunity, $wallet] = $this->pending();
        $opportunity->update(['status' => TradeOpportunityStatus::Executing]);
        $attributes = [
            'trade_opportunity_id' => $opportunity->id, 'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id, 'wallet_address' => strtolower($wallet->address),
            'buy_token' => strtolower($opportunity->address), ...$this->input(),
            'status' => 'reserved', 'transaction_payload' => null, 'expires_at' => null,
        ];
        match ($condition) {
            'attempt owner' => $attributes['user_id'] = User::factory()->create()->id,
            'wallet owner' => $wallet->update(['user_id' => User::factory()->create()->id]),
            'opportunity chain' => $opportunity->update(['chain' => Chain::Solana]),
            'wallet chain' => $wallet->update(['chain' => Chain::Solana]),
            'wallet address' => $attributes['wallet_address'] = '0x'.str_repeat('4', 40),
            'token' => $attributes['buy_token'] = '0x'.str_repeat('4', 40),
            'unverified' => $wallet->update(['verified_at' => null]),
            'mode' => $opportunity->update(['execution_mode' => ExecutionMode::Paper]),
            'status' => $opportunity->update(['status' => TradeOpportunityStatus::PendingConfirmation]),
        };
        $this->expectException(DomainException::class);
        EthereumSwapAttempt::query()->create($attributes);
    }

    public static function invalidBindings(): array
    {
        return array_map(fn ($value) => [$value], ['attempt owner', 'wallet owner', 'opportunity chain', 'wallet chain', 'wallet address', 'token', 'unverified', 'mode', 'status']);
    }

    #[DataProvider('immutableBindings')]
    public function test_existing_execution_binding_is_immutable(string $field, mixed $value): void
    {
        [$user, $opportunity] = $this->pending();
        $attempt = $this->reserve($opportunity, $user);
        $this->expectException(DomainException::class);
        $attempt->update([$field => $value]);
    }

    public static function immutableBindings(): array
    {
        return [['trade_opportunity_id', null], ['user_id', 999], ['connected_wallet_id', 999], ['wallet_address', 'different'], ['buy_token', 'different'], ['sell_amount_wei', '2'], ['slippage_bps', 2]];
    }

    public function test_reserved_attempt_cannot_be_submitted_cancelled_expired_or_reconciled(): void
    {
        Http::preventStrayRequests();
        [$user, $opportunity] = $this->pending();
        $attempt = $this->reserve($opportunity, $user);
        $this->actingAs($user)->postJson(route('wallets.ethereum.submitted'), ['attempt_id' => $attempt->id, 'transaction_hash' => '0x'.str_repeat('a', 64)])->assertUnprocessable();
        $this->postJson(route('wallets.ethereum.cancelled'), ['attempt_id' => $attempt->id])->assertUnprocessable();
        $this->artisan('ethereum:expire-prepared-swaps')->assertSuccessful();
        $this->artisan('ethereum:reconcile-submitted-swaps')->assertSuccessful();
        $this->assertSame('reserved', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_hash);
        Http::assertNothingSent();
    }

    public function test_guest_and_unverified_user_cannot_reserve(): void
    {
        [$user, $opportunity] = $this->pending();
        $this->post(route('opportunities.approve', $opportunity), $this->input())->assertRedirect(route('login'));
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
    }

    public function test_request_cannot_override_owner_wallet_token_or_status(): void
    {
        [$user, $opportunity, $wallet] = $this->pending();
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input() + [
            'buy_token' => '0x'.str_repeat('4', 40), 'user_id' => 999,
            'connected_wallet_id' => 999, 'status' => 'executed', 'trade_opportunity_id' => 999,
        ])->assertSessionHas('success');
        $attempt = EthereumSwapAttempt::query()->sole();
        $this->assertSame($user->id, $attempt->user_id);
        $this->assertSame($wallet->id, $attempt->connected_wallet_id);
        $this->assertSame($opportunity->id, $attempt->trade_opportunity_id);
        $this->assertSame(strtolower($opportunity->address), $attempt->buy_token);
        $this->assertSame('reserved', $attempt->status);
    }

    public function test_signal_policy_does_not_reserve_or_execute(): void
    {
        [$user, $opportunity] = $this->pending();
        $opportunity->update(['entry_mode' => EntryMode::Signal, 'status' => TradeOpportunityStatus::Qualified]);
        $this->assertNull(app(EntryPolicy::class)->apply($opportunity));
        $this->assertSame(TradeOpportunityStatus::Qualified, $opportunity->fresh()->status);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_existing_reservation_is_not_recreated_if_opportunity_state_is_reset(): void
    {
        [$user, $opportunity] = $this->pending();
        $attempt = $this->reserve($opportunity, $user);
        $opportunity->refresh()->update(['status' => TradeOpportunityStatus::PendingConfirmation]);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('error');
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertSame('reserved', $attempt->fresh()->status);
    }

    public function test_reservation_is_excluded_from_transaction_history(): void
    {
        [$user, $opportunity] = $this->pending();
        $this->reserve($opportunity, $user);
        $this->actingAs($user)->getJson(route('wallets.ethereum.history'))->assertOk()->assertJsonCount(0, 'transactions');
        $this->actingAs(User::factory()->create())->getJson(route('wallets.ethereum.history'))->assertOk()->assertJsonCount(0, 'transactions');
    }

    public function test_many_reservations_cannot_displace_real_transaction_history(): void
    {
        [$user, $opportunity, $wallet] = $this->pending();
        $ids = [];
        foreach (['prepared', 'submitted', 'confirmed', 'failed', 'expired', 'cancelled'] as $index => $status) {
            $ids[] = EthereumSwapAttempt::query()->create([
                'user_id' => $user->id, 'connected_wallet_id' => $wallet->id,
                'buy_token' => $opportunity->address, ...$this->input(),
                'transaction_payload' => ['data' => '0x1234'], 'expires_at' => now()->addMinute(),
                'status' => $status, 'transaction_hash' => in_array($status, ['submitted', 'confirmed', 'failed'], true)
                    ? '0x'.str_repeat((string) ($index + 1), 64) : null,
            ])->id;
        }
        for ($index = 0; $index < 12; $index++) {
            $next = $opportunity->replicate();
            $next->discovery_key = 'history-reservation-'.$index;
            $next->save();
            $this->reserve($next, $user);
        }

        $response = $this->actingAs($user)->getJson(route('wallets.ethereum.history'))->assertOk()->assertJsonCount(6, 'transactions');
        $this->assertSame(array_reverse($ids), array_column($response->json('transactions'), 'id'));
        $this->assertNotContains('reserved', array_column($response->json('transactions'), 'status'));
    }

    #[DataProvider('invalidFreshnessConfiguration')]
    public function test_invalid_freshness_configuration_rejects_even_new_opportunities(mixed $configured): void
    {
        [$user, $opportunity] = $this->pending();
        config(['services.ethereum.opportunity_max_age_seconds' => $configured]);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())
            ->assertSessionHas('error', 'Live opportunity freshness configuration is invalid. Contact an administrator.');
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
    }

    public static function invalidFreshnessConfiguration(): array
    {
        return [[0], [-1], ['abc'], ['300abc'], ['999999999999999999999999999'], [901], [null], [true], [300.5], [''], [' 300'], ['3e2'], [[]]];
    }

    public function test_default_freshness_is_300_seconds_and_maximum_is_900(): void
    {
        $this->freezeTime();
        $this->assertSame(300, config('services.ethereum.opportunity_max_age_seconds'));
        [$user, $opportunity] = $this->pending();
        $opportunity->update(['qualified_at' => now()->subSeconds(301)]);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('error');
        $opportunity->update(['qualified_at' => now()->subSeconds(300)]);
        $this->post(route('opportunities.approve', $opportunity), $this->input())->assertSessionHas('success');

        [$otherUser, $other] = $this->pending();
        config(['services.ethereum.opportunity_max_age_seconds' => '900']);
        $other->update(['qualified_at' => now()->subSeconds(901)]);
        $this->actingAs($otherUser)->post(route('opportunities.approve', $other), $this->input())->assertSessionHas('error');
        $other->update(['qualified_at' => now()->subSeconds(900)]);
        $this->post(route('opportunities.approve', $other), $this->input())->assertSessionHas('success');
    }

    #[DataProvider('uniqueDrivers')]
    public function test_duplicate_insert_recovers_matching_competing_reservation(string $driver): void
    {
        [$user, $opportunity] = $this->pending();
        $winner = null;
        $this->failNextReservationInsert($this->duplicate($driver), function () use ($opportunity, $user, &$winner): void {
            $winner = $this->reserve($opportunity, $user);
        });

        $result = $this->reserve($opportunity, $user);

        $this->assertSame($winner->id, $result->id);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertDatabaseCount('trade_opportunity_events', 1);
    }

    public static function uniqueDrivers(): array
    {
        return [['mysql'], ['sqlite']];
    }

    #[DataProvider('conflictingBindings')]
    public function test_duplicate_insert_with_conflicting_binding_fails_closed(string $field, mixed $value): void
    {
        [$user, $opportunity] = $this->pending();
        $this->failNextReservationInsert($this->duplicate('mysql'), function () use ($opportunity, $user, $field, $value): void {
            $winner = $this->reserve($opportunity, $user);
            if ($field === 'user_id') {
                $value = User::factory()->create()->id;
            } elseif ($field === 'connected_wallet_id') {
                [, , $otherWallet] = $this->pending();
                $value = $otherWallet->id;
            } elseif ($field === 'trade_opportunity_id') {
                $value = TradeOpportunity::factory()->create()->id;
            }
            DB::table('ethereum_swap_attempts')->where('id', $winner->id)->update([$field => $value]);
        });

        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())
            ->assertSessionHas('error', 'This opportunity has a conflicting live execution reservation. Refresh the opportunity before retrying.');
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public static function conflictingBindings(): array
    {
        return [['trade_opportunity_id', null], ['user_id', null], ['connected_wallet_id', null], ['wallet_address', '0x'.str_repeat('4', 40)], ['buy_token', '0x'.str_repeat('4', 40)], ['sell_amount_wei', '2'], ['slippage_bps', 2]];
    }

    #[DataProvider('databaseFailureTypes')]
    public function test_database_failure_is_reported_and_sanitized_without_success(string $type): void
    {
        [$user, $opportunity] = $this->pending();
        Exceptions::fake();
        $failure = $type === 'unrelated unique'
            ? $this->duplicate('mysql')->setIndex('ethereum_swap_attempts_transaction_hash_unique')
            : new QueryException('mysql', 'insert into ethereum_swap_attempts (secret_column) values (?)', ['secret'], new PDOException($type.' SQLSTATE[40001]: database driver secret details'));
        $this->failNextReservationInsert($failure);

        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())
            ->assertSessionHas('error', 'Approval could not be completed because of a temporary database problem. Please retry.')
            ->assertSessionMissing('success');
        Exceptions::assertReported($failure::class);
        $this->assertSame($failure, Exceptions::reported()[0]);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
    }

    public static function databaseFailureTypes(): array
    {
        return [['deadlock'], ['unrelated query'], ['unrelated unique']];
    }

    public function test_safe_domain_error_message_is_preserved(): void
    {
        [$user, $opportunity, $wallet] = $this->pending();
        $wallet->update(['disconnected_at' => now()]);
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())
            ->assertSessionHas('error', 'An active verified Ethereum wallet is required.');
    }

    public function test_recognized_duplicate_without_a_winner_fails_closed(): void
    {
        [$user, $opportunity] = $this->pending();
        $this->failNextReservationInsert($this->duplicate('mysql'));
        $this->actingAs($user)->post(route('opportunities.approve', $opportunity), $this->input())
            ->assertSessionHas('error', 'This opportunity has a conflicting live execution reservation. Refresh the opportunity before retrying.');
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
    }

    public function test_unrelated_unique_failure_is_not_recovered_even_when_a_matching_winner_exists(): void
    {
        [$user, $opportunity] = $this->pending();
        $failure = $this->duplicate('mysql')->setIndex('ethereum_swap_attempts_transaction_hash_unique');
        $this->failNextReservationInsert($failure, function () use ($opportunity, $user): void {
            $this->reserve($opportunity, $user);
        });

        try {
            $this->reserve($opportunity, $user);
            $this->fail('An unrelated database failure was converted into success.');
        } catch (UniqueConstraintViolationException $actual) {
            $this->assertSame($failure, $actual);
            $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        }
    }

    private function duplicate(string $driver): UniqueConstraintViolationException
    {
        $exception = new UniqueConstraintViolationException($driver, 'insert into "ethereum_swap_attempts" (...) values (...)', [], new PDOException('Duplicate entry SQL constraint details'));

        return $driver === 'mysql' ? $exception->setIndex('ethereum_swap_attempts_trade_opportunity_id_unique') : $exception->setColumns(['trade_opportunity_id']);
    }

    /** Simulate a competing commit after our failed insert rolls back, using real rows. */
    private function failNextReservationInsert(QueryException $failure, ?Closure $afterRollback = null): void
    {
        $armed = true;
        $failed = false;
        EthereumSwapAttempt::creating(function () use ($failure, &$armed, &$failed): void {
            if ($armed) {
                $armed = false;
                $failed = true;
                throw $failure;
            }
        });
        Event::listen(TransactionRolledBack::class, function () use (&$failed, $afterRollback): void {
            if ($failed) {
                $failed = false;
                $afterRollback?->__invoke();
            }
        });
    }

    /** @return array{User, TradeOpportunity, ConnectedWallet} */
    private function pending(): array
    {
        app(ApplicationSettingsService::class)->update(['risk.max_trade_amount' => '0.1', 'risk.max_slippage_percent' => 1.5, 'risk.kill_switch' => false]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        app(UserTradingPreferenceService::class)->forUser($user)->update(['execution_mode' => ExecutionMode::Live, 'entry_mode' => EntryMode::Confirm, 'trading_enabled' => true]);
        $opportunity = TradeOpportunity::factory()->create([
            'user_id' => $user->id, 'chain' => Chain::Ethereum, 'address' => '0x'.str_repeat('A', 40),
            'entry_mode' => EntryMode::Confirm, 'execution_mode' => ExecutionMode::Live,
            'status' => TradeOpportunityStatus::PendingConfirmation, 'qualified_at' => now(),
        ]);
        $address = '0x'.str_pad(strtoupper(dechex($user->id)), 40, 'B', STR_PAD_LEFT);
        $wallet = ConnectedWallet::query()->create([
            'user_id' => $user->id, 'chain' => Chain::Ethereum, 'address' => $address,
            'address_hash' => ConnectedWallet::addressHash(Chain::Ethereum, $address),
            'provider' => 'metamask', 'verified_at' => now(),
        ]);

        return [$user, $opportunity, $wallet];
    }

    /** @return array{sell_amount_wei: string, slippage_bps: int} */
    private function input(): array
    {
        return ['sell_amount_wei' => '1000000000000000', 'slippage_bps' => 100];
    }

    private function reserve(TradeOpportunity $opportunity, User $user): EthereumSwapAttempt
    {
        return app(EthereumOpportunityReservationService::class)->reserve($opportunity, $user, $this->input());
    }
}
