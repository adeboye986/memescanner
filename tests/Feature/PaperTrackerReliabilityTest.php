<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Services\DatabaseLockRetryService;
use App\Services\PaperMarketObservation;
use App\Services\PaperTrackerHealthService;
use App\Services\TelegramService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaperTrackerReliabilityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('unverifiedFacts')]
    public function test_unverified_observation_records_risk_without_closing_or_crediting(string $field, mixed $value, string $reason): void
    {
        [$position, $wallet] = $this->position();
        $pair = $this->pair(0.1);
        data_set($pair, $field, $value);
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$pair]), 'api.geckoterminal.com/*' => Http::response([], 503)]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame([], $position->fresh()->exit_events);
        $this->assertEqualsWithDelta(4.9, $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertContains($reason, data_get($position->fresh()->meta, 'market_observation.reasons'));
        $this->assertSame(100000.0, $position->fresh()->last_market_cap);
        $this->assertTrue(data_get($position->fresh()->meta, 'market_observation.stop_loss_threshold_breached'));
        $this->assertSame(1, $position->snapshots()->where('snapshot_type', 'unverified')->count());
        $this->assertSame('degraded', app(PaperTrackerHealthService::class)->status()['status']);
        $this->assertSame(0, app(PaperTrackerHealthService::class)->status()['priced_positions']);
    }

    public static function unverifiedFacts(): array
    {
        return [['liquidity.usd', null, 'liquidity_unavailable'], ['liquidity.usd', 0, 'liquidity_unavailable'],
            ['liquidity.usd', 'bad', 'liquidity_unavailable']];
    }

    public function test_realistic_collapse_is_not_discarded_or_filled_at_stop_threshold(): void
    {
        [$position, $wallet] = $this->position();
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$this->pair(0.000001)])]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $event = $position->fresh()->exit_events[0];
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertEqualsWithDelta(0.9, $event['trigger_multiple'], 0.000001);
        $this->assertEqualsWithDelta(0.000001, $event['fill_multiple'], 0.00000001);
        $this->assertSame('severe_decline', $event['market_observation']['status']);
        $this->assertFalse($event['execution_verified']);
        $this->assertNull($event['estimated_executable_fill']);
        $this->assertSame('observed_mark_without_slippage_or_depth', $event['fill_model']);
        $this->assertEqualsWithDelta(4.9000001, $wallet->fresh()->available_balance_sol, 0.00000001);
    }

    public function test_price_ratio_is_labelled_and_fdv_is_not_used(): void
    {
        [$position] = $this->position();
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([[...$this->pair(0.8), 'marketCap' => null, 'fdv' => 9000000]])]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $event = $position->fresh()->exit_events[0];
        $this->assertEqualsWithDelta(80000, $event['observed_market_cap'], 0.01);
        $this->assertSame('entry_price_ratio', $event['market_observation']['valuation_source']);
        $this->assertStringContainsString('unchanged circulating supply', $event['market_observation']['valuation_assumption']);
    }

    public function test_missing_provider_rotates_attempts_without_claiming_a_valid_update(): void
    {
        [$first] = $this->position();
        $second = $first->replicate();
        $second->address = '0x'.str_repeat('d', 40);
        $second->save();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([]), 'api.geckoterminal.com/*' => Http::response(['data' => []])]);

        $this->artisan('tokens:paper-track', ['--limit' => 1])->assertSuccessful();
        $this->artisan('tokens:paper-track', ['--limit' => 1])->assertSuccessful();

        $this->assertNotNull($first->fresh()->last_checked_at);
        $this->assertNotNull($second->fresh()->last_checked_at);
        $this->assertSame('unverified', data_get($second->fresh()->meta, 'market_observation.status'));
        $health = app(PaperTrackerHealthService::class)->status();
        $this->assertNull($health['last_successful_market_observation']);
        $this->assertTrue($health['cycle_completed']);
        $this->assertSame('degraded', $health['status']);
    }

    public function test_fetch_age_is_checked_without_claiming_upstream_freshness(): void
    {
        [$position] = $this->position();
        $facts = ['available' => true, 'requested_token_is_base' => true, 'market_cap' => 90000, 'price_usd' => 0.9,
            'liquidity_usd' => 10000, 'fetched_at' => now()->subSeconds(61)->toIso8601String()];

        $result = app(PaperMarketObservation::class)->evaluate($position, $facts);

        $this->assertFalse($result['simulation_allowed']);
        $this->assertContains('stale_or_missing_fetch_time', $result['reasons']);
        $this->assertNull($result['provider_observed_at']);
    }

    public function test_running_process_or_skipped_cycle_does_not_imply_healthy_tracking(): void
    {
        $health = app(PaperTrackerHealthService::class);
        $process = Cache::lock('paper-tracker.fast.process', 300);
        $process->get();
        $health->recordProcessHeartbeat();
        try {
            $this->artisan('tokens:paper-track')->assertSuccessful();
            $this->assertSame('unknown', $health->status()['status']);
            $this->assertTrue($health->status()['process_lock_held']);
            $this->assertNull($health->status()['last_tracker_check']);
        } finally {
            $process->release();
        }
    }

    public function test_scheduled_completed_cycle_stays_fresh_across_ten_second_interval(): void
    {
        $this->freezeTime();
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->travel(11)->seconds();
        $this->assertSame('active', app(PaperTrackerHealthService::class)->status()['status']);
        $this->travel(20)->seconds();
        $this->assertSame('stale', app(PaperTrackerHealthService::class)->status()['status']);
    }

    public function test_lost_cycle_lock_stops_application_and_does_not_delete_new_owner_lock(): void
    {
        [$position, $wallet] = $this->position();
        config(['services.trading.paper_tracker_lock_seconds' => 30]);
        $replacement = null;
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => function () use (&$replacement) {
            $this->travel(31)->seconds();
            $replacement = Cache::lock('paper-tracker.position-cycle', 300);
            $this->assertTrue($replacement->get());

            return Http::response([$this->pair(0.1)]);
        }]);
        try {
            $this->artisan('tokens:paper-track')->run();
            $this->fail('Lost lock did not stop the tracker.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lock was lost', $exception->getMessage());
            $this->assertTrue($replacement->isOwnedByCurrentProcess());
            $this->assertSame('open', $position->fresh()->status);
            $this->assertEqualsWithDelta(4.9, $wallet->fresh()->available_balance_sol, 0.000001);
            $this->assertFalse(app(PaperTrackerHealthService::class)->raw()['cycle_completed']);
        } finally {
            $replacement?->release();
        }
    }

    public function test_scheduler_overlap_timeout_is_bounded_and_cycle_lock_still_prevents_work(): void
    {
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'tokens:paper-track'));
        $this->assertSame(5, $event->expiresAt);
        $cycle = Cache::lock('paper-tracker.position-cycle', 300);
        $cycle->get();
        try {
            $this->artisan('tokens:paper-track:fast', ['--max-cycles' => 1])->assertSuccessful();
            $this->assertNull(app(PaperTrackerHealthService::class)->status()['last_tracker_check']);
        } finally {
            $cycle->release();
        }
    }

    public function test_repeated_unverified_observation_keeps_one_diagnostic_snapshot(): void
    {
        [$position] = $this->position();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([]), 'api.geckoterminal.com/*' => Http::response(['data' => []])]);
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->assertSame(1, $position->snapshots()->where('snapshot_type', 'unverified')->count());
    }

    public function test_valid_observation_after_deferred_exit_applies_only_current_mark_once(): void
    {
        [$position, $wallet] = $this->position();
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::sequence()->push([[...$this->pair(0.8), 'liquidity' => null]])->push([$this->pair(0.7)]),
            'api.geckoterminal.com/*' => Http::response([], 503)]);
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->assertSame('open', $position->fresh()->status);
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertCount(1, $position->fresh()->exit_events);
        $this->assertEqualsWithDelta(0.7, $position->fresh()->exit_events[0]['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(4.97, $wallet->fresh()->available_balance_sol, 0.000001);
    }

    public function test_slow_fallback_cannot_delay_primary_position_and_stops_at_work_budget(): void
    {
        $this->freezeTime();
        config(['services.trading.paper_market.ethereum.work_budget_seconds' => 8]);
        [$slow, $wallet] = $this->position();
        $healthy = $slow->replicate();
        $healthy->address = '0x'.str_repeat('d', 40);
        $healthy->save();
        $later = $slow->replicate();
        $later->address = '0x'.str_repeat('e', 40);
        $later->save();
        $acquired = now()->toIso8601String();
        $pair = $this->pair(0.8);
        $pair['baseToken']['address'] = $healthy->address;
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$pair]), 'api.geckoterminal.com/*' => function () use ($healthy) {
            $this->assertSame('closed', $healthy->fresh()->status);
            $this->travel(8)->seconds();

            return Http::response([], 503);
        }]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $this->assertSame($acquired, data_get($healthy->fresh()->meta, 'market_observation.fetched_at'));
        $this->assertSame('open', $slow->fresh()->status);
        $this->assertSame('open', $later->fresh()->status);
        $this->assertContains('provider_work_budget_exhausted', data_get($later->fresh()->meta, 'market_observation.reasons'));
        $this->assertEqualsWithDelta(4.98, $wallet->fresh()->available_balance_sol, 0.000001);
        Http::assertSentCount(2);
    }

    public function test_supply_discrepancy_is_diagnostic_and_does_not_block_stop_loss(): void
    {
        [$position, $wallet] = $this->position();
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([[...$this->pair(0.1), 'marketCap' => 80000]])]);
        $this->artisan('tokens:paper-track')->assertSuccessful();

        $event = $position->fresh()->exit_events[0];
        $this->assertContains('market_cap_price_ratio_discrepancy_possible_supply_change', $event['market_observation']['diagnostics']);
        $this->assertSame([], $event['market_observation']['reasons']);
        $this->assertEqualsWithDelta(0.8, $event['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(4.98, $wallet->fresh()->available_balance_sol, 0.000001);
    }

    #[DataProvider('solanaObservations')]
    public function test_solana_exit_behavior_is_unchanged(float $multiple, mixed $liquidity, string $status): void
    {
        [$position, $wallet] = $this->position();
        $position->update(['chain' => Chain::Solana, 'address' => 'solana-token']);
        $wallet->update(['chain' => Chain::Solana, 'currency' => 'SOL']);
        $before = $wallet->fresh()->getRawOriginal();
        $pair = $this->pair($multiple);
        $pair['chainId'] = 'solana';
        $pair['baseToken']['address'] = 'solana-token';
        $pair['liquidity'] = ['usd' => $liquidity];
        $pair['priceUsd'] = 1;
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/tokens/v1/solana/*' => Http::response([$pair])]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $this->assertSame($status, $position->fresh()->status);
        $this->assertSame([], data_get($position->fresh()->meta, 'market_observation.reasons'));
        if ($status === 'closed') {
            $this->assertEqualsWithDelta($multiple, $position->fresh()->exit_events[0]['fill_multiple'], 0.00000001);
            $this->assertEqualsWithDelta(4.9 + 0.1 * $multiple, $wallet->fresh()->available_balance_sol, 0.00000001);
        } else {
            $this->assertSame($before, $wallet->fresh()->getRawOriginal());
        }
        Http::assertSentCount(1);
    }

    public static function solanaObservations(): array
    {
        return [[0.85, 10000, 'closed'], [0.8, null, 'closed'], [0.000001, 0, 'closed'], [1.1, null, 'open']];
    }

    #[DataProvider('databaseWaitModes')]
    public function test_loss_of_lease_after_database_select_rolls_back_without_crediting(bool $retry): void
    {
        [$position, $wallet] = $this->position();
        config(['services.trading.paper_tracker_lock_seconds' => 30]);
        $replacement = null;
        $reads = 0;
        DB::listen(function ($query) use (&$reads, &$replacement, $retry): void {
            if (! str_starts_with($query->sql, 'select * from "paper_positions"')) {
                return;
            }
            $reads++;
            if ($retry && $reads === 2) {
                throw new \PDOException('database is locked');
            }
            if ($reads === ($retry ? 3 : 2)) {
                $this->travel(31)->seconds();
                $replacement = Cache::lock('paper-tracker.position-cycle', 300);
                $this->assertTrue($replacement->get());
            }
        });
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$this->pair(0.8)])]);
        try {
            $this->artisan('tokens:paper-track')->run();
            $this->fail('Lost database-window lease was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lock was lost', $exception->getMessage());
            $this->assertSame('open', $position->fresh()->status);
            $this->assertSame([], $position->fresh()->exit_events);
            $this->assertEqualsWithDelta(4.9, $wallet->fresh()->available_balance_sol, 0.000001);
            $this->assertTrue($replacement->isOwnedByCurrentProcess());
        } finally {
            $replacement?->release();
        }
    }

    public static function databaseWaitModes(): array
    {
        return [[false], [true]];
    }

    public function test_configured_age_and_diagnostic_thresholds_are_chain_scoped(): void
    {
        $this->freezeTime();
        [$position] = $this->position();
        config(['services.trading.paper_market.ethereum.max_observation_age_seconds' => 5,
            'services.trading.paper_market.ethereum.decline_diagnostic_percent' => 20,
            'services.trading.paper_market.ethereum.valuation_discrepancy_ratio' => 3]);
        $facts = ['available' => true, 'requested_token_is_base' => true, 'market_cap' => 70000, 'price_usd' => 0.3,
            'liquidity_usd' => 10000, 'fetched_at' => now()->toIso8601String()];
        $evaluator = app(PaperMarketObservation::class);
        $current = $evaluator->evaluate($position, $facts);
        $this->assertTrue($current['simulation_allowed']);
        $this->assertSame(['severe_decline'], $current['diagnostics']);
        $this->travel(6)->seconds();
        $this->assertContains('stale_or_missing_fetch_time', $evaluator->evaluate($position, $facts)['reasons']);
        $position->chain = Chain::Solana;
        $facts['liquidity_usd'] = null;
        $solana = $evaluator->evaluate($position, $facts);
        $this->assertTrue($solana['simulation_allowed']);
        $this->assertSame([], $solana['diagnostics']);
    }

    public function test_scheduler_uses_configured_overlap_expiry(): void
    {
        config(['services.trading.paper_tracker_overlap_minutes' => 7]);
        require base_path('routes/console.php');
        $event = collect(app(Schedule::class)->events())->last(fn ($event) => str_contains($event->command ?? '', 'tokens:paper-track'));
        $this->assertSame(7, $event->expiresAt);
    }

    public function test_observation_that_ages_while_waiting_for_wallet_lock_cannot_credit_balance(): void
    {
        [$position, $wallet] = $this->position();
        $delayed = false;
        DB::listen(function ($query) use (&$delayed): void {
            if (! $delayed && str_starts_with($query->sql, 'select * from "paper_wallets"')) {
                $delayed = true;
                $this->travel(61)->seconds();
            }
        });
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$this->pair(0.8)])]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $this->assertTrue($delayed);
        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame([], $position->fresh()->exit_events);
        $this->assertEqualsWithDelta(4.9, $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertSame(0, app(PaperTrackerHealthService::class)->status()['priced_positions']);
    }

    public function test_newer_position_state_is_not_overwritten_after_database_wait(): void
    {
        [$position, $wallet] = $this->position();
        $calls = 0;
        $newer = null;
        $this->mock(DatabaseLockRetryService::class)->shouldReceive('run')->andReturnUsing(function ($operation) use (&$calls, &$newer, $position) {
            if (++$calls === 2) {
                $position->fresh()->update(['peak_market_cap' => 300000, 'tp_50_hit' => true,
                    'meta' => ['market_observation' => ['status' => 'observed', 'provider' => 'newer_worker']]]);
                $newer = $position->fresh()->getRawOriginal();
            }

            return $operation();
        });
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$this->pair(0.8)])]);
        $this->artisan('tokens:paper-track')->assertSuccessful();

        $this->assertSame($newer, $position->fresh()->getRawOriginal());
        $this->assertEqualsWithDelta(4.9, $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertSame(0, app(PaperTrackerHealthService::class)->status()['priced_positions']);
    }

    #[DataProvider('recoveryModes')]
    public function test_outstanding_risk_survives_other_batches_and_clears_after_recovery_or_close(string $mode): void
    {
        $this->freezeTime();
        [$unverified] = $this->position();
        $unverified->update(['last_checked_at' => now(), 'meta' => ['market_observation' => ['status' => 'unverified']]]);
        $healthy = $unverified->replicate();
        $healthy->address = '0x'.str_repeat('d', 40);
        $healthy->last_checked_at = null;
        $healthy->meta = [];
        $healthy->save();
        $pair = $this->pair(1.1);
        $pair['baseToken']['address'] = $healthy->address;
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::sequence()->push([$pair])->push([$this->pair(1.1)])]);
        $this->artisan('tokens:paper-track', ['--limit' => 1])->assertSuccessful();
        $health = app(PaperTrackerHealthService::class);
        $this->assertSame(1, $health->status()['priced_positions']);
        $this->assertSame(1, $health->status()['outstanding_unverified_positions']);
        $this->assertSame('degraded', $health->status()['status']);
        if ($mode === 'close') {
            $unverified->update(['status' => 'closed']);
        } else {
            $unverified->update(['last_checked_at' => now()->subMinute()]);
            $this->artisan('tokens:paper-track', ['--limit' => 1])->assertSuccessful();
        }
        $this->assertSame(0, $health->status()['outstanding_unverified_positions']);
        $this->assertSame('active', $health->status()['status']);
    }

    public static function recoveryModes(): array
    {
        return [['close'], ['recover']];
    }

    public function test_protected_floor_exit_records_the_same_simulation_provenance_as_stop_loss(): void
    {
        [$position] = $this->position();
        $position->update(['peak_market_cap' => 220000, 'tp_50_hit' => true, 'last_market_cap' => 220000]);
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$this->pair(1.8)])]);
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $event = $position->fresh()->exit_events[0];
        $this->assertSame('protected_floor_exit', $event['type']);
        $this->assertFalse($event['execution_verified']);
        $this->assertNull($event['estimated_executable_fill']);
        $this->assertSame('dexscreener', $event['market_observation']['provider']);
        $this->assertSame('0x'.str_repeat('b', 40), $event['market_observation']['pair_address']);
        $this->assertEqualsWithDelta(1.8, $event['observed_multiple'], 0.000001);
        $this->assertEqualsWithDelta(2, $event['trigger_multiple'], 0.000001);
        $this->assertSame('observed_mark_without_slippage_or_depth', $event['fill_model']);
        $this->assertSame('original_native_asset_cost_times_observed_valuation_ratio', $event['proceeds_basis']);
    }

    public function test_explicit_health_threshold_below_thirty_seconds_is_honored(): void
    {
        $this->freezeTime();
        config(['services.trading.paper_tracker_stale_seconds' => 5]);
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->travel(6)->seconds();
        $this->assertSame('stale', app(PaperTrackerHealthService::class)->status()['status']);
    }

    public function test_last_valid_observation_survives_unavailable_and_cooldown_checks_then_updates_on_recovery(): void
    {
        $this->freezeTime();
        [$position, $wallet] = $this->position();
        $walletBefore = $wallet->fresh()->getRawOriginal();
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::sequence()->push([$this->pair(1.1)])->push([])->push([])->push([$this->pair(0.8)]),
            'api.geckoterminal.com/*' => Http::response([], 429)]);

        $this->artisan('tokens:paper-track')->assertSuccessful();
        $valid = $position->fresh()->meta['market_observation'];
        $validAt = now()->toIso8601String();
        $this->assertTrue($valid['simulation_allowed']);
        $this->assertSame($valid, $position->fresh()->meta['last_valid_market_observation']);
        $this->assertSame($validAt, $position->fresh()->meta['last_valid_market_observation_at']);

        foreach (['no_valid_provider_observation', 'geckoterminal_cooldown'] as $reason) {
            $this->travel(6)->seconds();
            $this->artisan('tokens:paper-track')->assertSuccessful();
            $fresh = $position->fresh();
            $this->assertSame('unverified', $fresh->meta['market_observation']['status']);
            $this->assertContains($reason, $fresh->meta['market_observation']['reasons']);
            $this->assertFalse($fresh->meta['market_observation']['simulation_allowed']);
            $this->assertSame($valid, $fresh->meta['last_valid_market_observation']);
            $this->assertSame($validAt, $fresh->meta['last_valid_market_observation_at']);
            $this->assertSame(110000.0, $fresh->last_market_cap);
            $this->assertEqualsWithDelta(1.1, $fresh->last_price, 0.000001);
            $this->assertSame('open', $fresh->status);
            $this->assertSame([], $fresh->exit_events);
            $this->assertSame($walletBefore, $wallet->fresh()->getRawOriginal());
            $this->assertSame('degraded', app(PaperTrackerHealthService::class)->status()['status']);
        }

        $this->travel(6)->seconds();
        $this->artisan('tokens:paper-track')->assertSuccessful();
        $fresh = $position->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertSame($fresh->meta['market_observation'], $fresh->meta['last_valid_market_observation']);
        $this->assertSame(now()->toIso8601String(), $fresh->meta['last_valid_market_observation_at']);
        $this->assertNotSame($valid, $fresh->meta['last_valid_market_observation']);
        $this->assertCount(1, $fresh->exit_events);
        $this->assertEqualsWithDelta(0.8, $fresh->exit_events[0]['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(4.98, $wallet->fresh()->available_balance_sol, 0.000001);
        Http::assertSentCount(5);
    }

    public function test_historical_stop_breach_cannot_authorize_exit_without_a_current_observation(): void
    {
        $this->freezeTime();
        [$position, $wallet] = $this->position();
        $historical = app(PaperMarketObservation::class)->evaluate($position, [
            'available' => true, 'requested_token_is_base' => true, 'market_cap' => 50000,
            'price_usd' => 0.5, 'liquidity_usd' => 10000, 'fetched_at' => now()->toIso8601String(),
        ]);
        $this->assertTrue($historical['simulation_allowed']);
        $this->assertTrue($historical['stop_loss_threshold_breached']);
        $validAt = now()->toIso8601String();
        $position->update(['last_market_cap' => 50000, 'last_price' => 0.5,
            'meta' => ['market_observation' => $historical, 'last_valid_market_observation' => $historical,
                'last_valid_market_observation_at' => $validAt]]);
        $historical = $position->fresh()->meta['last_valid_market_observation'];
        $walletBefore = $wallet->fresh()->getRawOriginal();
        $this->travel(120)->seconds();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([]), 'api.geckoterminal.com/*' => Http::response([], 404)]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        $fresh = $position->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertSame([], $fresh->exit_events);
        $this->assertSame($walletBefore, $wallet->fresh()->getRawOriginal());
        $this->assertFalse($fresh->meta['market_observation']['simulation_allowed']);
        $this->assertSame($historical, $fresh->meta['last_valid_market_observation']);
        $this->assertSame($validAt, $fresh->meta['last_valid_market_observation_at']);
        Http::assertSentCount(2);
    }

    private function position(): array
    {
        $wallet = PaperWallet::query()->create(['name' => 'default', 'chain' => 'ethereum', 'currency' => 'ETH',
            'starting_balance_sol' => 5, 'available_balance_sol' => 4.9, 'invested_balance_sol' => 0.1, 'realized_pnl_sol' => 0]);
        $position = PaperPosition::query()->create(['chain' => 'ethereum', 'address' => '0x'.str_repeat('a', 40), 'symbol' => 'TEST',
            'entry_market_cap' => 100000, 'last_market_cap' => 100000, 'entry_price' => 1, 'entry_at' => now()->subMinute(),
            'status' => 'open', 'initial_investment_sol' => 0.1, 'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1, 'exit_events' => []]);

        return [$position, $wallet];
    }

    private function pair(float $multiple): array
    {
        return ['chainId' => 'ethereum', 'pairAddress' => '0x'.str_repeat('b', 40),
            'baseToken' => ['address' => '0x'.str_repeat('a', 40)], 'quoteToken' => ['address' => '0x'.str_repeat('c', 40)],
            'priceUsd' => $multiple, 'marketCap' => 100000 * $multiple, 'liquidity' => ['usd' => 10000]];
    }
}
