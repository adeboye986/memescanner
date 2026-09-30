<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\LivePosition;
use App\Models\SolanaSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineLiveAttemptInspector;
use App\Services\TradingEngine\TradingEngineLiveReadiness;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TradingEngineLiveReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_fully_valid_synthetic_configuration_is_ready(): void
    {
        $this->configureValidReadiness();
        $this->bindReadiness();

        $result = app(TradingEngineLiveReadiness::class)->inspect();

        $this->assertTrue($result['ready']);
        $this->assertSame('READY', $result['overall']);
        $this->assertNotContains(
            TradingEngineLiveReadiness::STATUS_BLOCKING,
            collect($result['checks'])->pluck('status')->all(),
        );
        $this->artisan('trading-engine:verify-live-readiness')
            ->expectsOutputToContain('Trading Engine LIVE Readiness')
            ->expectsOutputToContain('Overall: READY')
            ->assertSuccessful();
    }

    public function test_missing_required_upstream_flag_is_not_ready(): void
    {
        $this->configureValidReadiness();
        config()->set('services.trading_engine.opportunity_projection_enabled', false);

        $readiness = $this->bindReadiness();
        $result = $readiness->inspect('ethereum');

        $this->assertFalse($result['ready']);
        $this->assertCheck($result, 'Opportunity projection', TradingEngineLiveReadiness::STATUS_BLOCKING);
        $this->artisan('trading-engine:verify-live-readiness', ['--chain' => 'ethereum'])
            ->expectsOutputToContain('Overall: NOT READY')
            ->assertExitCode(1);
    }

    public function test_missing_engine_authentication_configuration_is_not_ready(): void
    {
        $this->configureValidReadiness();
        config()->set('services.trading_engine.private_key_base64', null);

        $result = $this->readiness()->inspect('ethereum');

        $this->assertFalse($result['ready']);
        $this->assertCheck($result, 'Engine endpoint and service authentication', TradingEngineLiveReadiness::STATUS_BLOCKING);
    }

    public function test_invalid_flag_dependency_order_is_not_ready(): void
    {
        $this->configureValidReadiness();
        config()->set('services.trading_engine.decision_boundary_enabled', false);
        config()->set('services.trading_engine.live_preparation_enabled', true);

        $result = $this->readiness()->inspect('solana');

        $this->assertFalse($result['ready']);
        $this->assertCheck($result, 'Rollout flag dependency order', TradingEngineLiveReadiness::STATUS_BLOCKING);
    }

    public function test_missing_required_schema_is_not_ready(): void
    {
        $this->configureValidReadiness();
        Schema::drop('trading_engine_opportunity_evaluations');

        $result = $this->readiness()->inspect('ethereum');

        $this->assertFalse($result['ready']);
        $this->assertCheck($result, 'Projection and evaluation schema', TradingEngineLiveReadiness::STATUS_BLOCKING);
    }

    public function test_missing_scheduler_registration_is_not_ready(): void
    {
        $this->configureValidReadiness();
        $schedule = Mockery::mock(Schedule::class);
        $schedule->shouldReceive('events')->andReturn([]);

        $result = $this->readiness($schedule)->inspect('ethereum');

        $this->assertFalse($result['ready']);
        $this->assertCheck($result, 'Ethereum reconciliation and expiry', TradingEngineLiveReadiness::STATUS_BLOCKING);
    }

    public function test_ethereum_only_does_not_require_solana_rollout_or_provider_configuration(): void
    {
        $this->configureValidReadiness();
        config()->set([
            'services.trading_engine.solana_live_integration_enabled' => false,
            'services.solana.rpc_url' => null,
            'services.jupiter.base_url' => null,
            'services.jupiter.swap_v2_base_url' => null,
            'services.solana_transaction_validator.url' => null,
            'services.solana_transaction_validator.api_key' => null,
        ]);

        $result = $this->readiness()->inspect('ethereum');

        $this->assertTrue($result['ready']);
        $this->assertNull(collect($result['checks'])->firstWhere('name', 'Solana LIVE integration'));
        $this->assertNull(collect($result['checks'])->firstWhere('name', 'Solana RPC, Jupiter, and transaction validator'));
    }

    public function test_solana_only_does_not_require_ethereum_rollout_or_provider_configuration(): void
    {
        $this->configureValidReadiness();
        config()->set([
            'services.trading_engine.live_recovery_enabled' => false,
            'services.ethereum.rpc_url' => null,
            'services.zero_x.base_url' => null,
            'services.zero_x.api_key' => null,
        ]);

        $result = $this->readiness()->inspect('solana');

        $this->assertTrue($result['ready']);
        $this->assertNull(collect($result['checks'])->firstWhere('name', 'Ethereum interrupted-broadcast recovery'));
        $this->assertNull(collect($result['checks'])->firstWhere('name', 'Ethereum RPC and 0x'));
    }

    public function test_warnings_alone_do_not_cause_failure(): void
    {
        $this->configureValidReadiness();
        config()->set('services.trading_engine.live_recovery_enabled', false);

        $readiness = $this->bindReadiness();
        $result = $readiness->inspect('ethereum');

        $this->assertTrue($result['ready']);
        $this->assertCheck($result, 'Ethereum interrupted-broadcast recovery', TradingEngineLiveReadiness::STATUS_WARNING);
        $this->artisan('trading-engine:verify-live-readiness', ['--chain' => 'ethereum'])
            ->expectsOutputToContain('[WARN] Ethereum interrupted-broadcast recovery')
            ->assertSuccessful();
    }

    public function test_uncertain_broadcast_blocks_readiness_without_mutating_the_attempt(): void
    {
        $this->configureValidReadiness();
        $this->travelTo('2026-09-30 12:00:00');
        $attempt = $this->uncertainEthereumAttempt();
        $before = DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->first();

        $result = $this->readiness()->inspect('ethereum');

        $this->assertFalse($result['ready']);
        $this->assertCheck($result, 'Ethereum unresolved LIVE attempts', TradingEngineLiveReadiness::STATUS_BLOCKING);
        $this->assertSame(
            serialize($before),
            serialize(DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->first()),
        );
    }

    public function test_command_performs_no_http_rpc_or_queue_work_and_creates_no_trading_state(): void
    {
        $this->configureValidReadiness();
        $this->bindReadiness();
        Http::preventStrayRequests();
        Queue::fake();

        $before = [
            'ethereum_attempts' => EthereumSwapAttempt::query()->count(),
            'solana_attempts' => SolanaSwapAttempt::query()->count(),
            'opportunities' => TradeOpportunity::query()->count(),
            'positions' => LivePosition::query()->count(),
            'jobs' => DB::table('jobs')->count(),
        ];

        $this->artisan('trading-engine:verify-live-readiness')->assertSuccessful();

        $this->assertSame($before, [
            'ethereum_attempts' => EthereumSwapAttempt::query()->count(),
            'solana_attempts' => SolanaSwapAttempt::query()->count(),
            'opportunities' => TradeOpportunity::query()->count(),
            'positions' => LivePosition::query()->count(),
            'jobs' => DB::table('jobs')->count(),
        ]);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_command_output_never_contains_sensitive_configuration(): void
    {
        $this->configureValidReadiness();
        $this->bindReadiness();
        $secrets = [
            'webhook-secret-do-not-print-1234567890',
            'zero-x-secret-marker',
            'validator-secret-marker',
            (string) config('services.trading_engine.private_key_base64'),
        ];

        $exitCode = Artisan::call('trading-engine:verify-live-readiness');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_invalid_chain_is_rejected_without_running_readiness(): void
    {
        $this->artisan('trading-engine:verify-live-readiness', ['--chain' => 'base'])
            ->expectsOutputToContain('The --chain option must be ethereum, solana, or all.')
            ->assertExitCode(2);
    }

    private function configureValidReadiness(): void
    {
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => true,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => true,
            'services.trading_engine.decision_boundary_enabled' => true,
            'services.trading_engine.live_decision_integration_enabled' => true,
            'services.trading_engine.live_preparation_enabled' => true,
            'services.trading_engine.live_recovery_enabled' => true,
            'services.trading_engine.solana_live_integration_enabled' => true,
            'services.trading_engine.base_url' => 'https://engine.example.test',
            'services.trading_engine.service_issuer' => 'meme-scanner-test',
            'services.trading_engine.service_audience' => 'trading-engine-test',
            'services.trading_engine.service_subject' => 'meme-scanner-test',
            'services.trading_engine.private_key_base64' => base64_encode(str_repeat('k', 64)),
            'services.trading_engine.assertion_lifetime_seconds' => 30,
            'services.trading_engine.connect_timeout_seconds' => 3,
            'services.trading_engine.timeout_seconds' => 8,
            'services.trading_engine.webhook_secret' => 'webhook-secret-do-not-print-1234567890',
            'services.trading_engine.webhook_timestamp_tolerance_seconds' => 60,
            'services.trading_engine.webhook_body_max_bytes' => 262144,
            'services.trading_engine.webhook_rate_limit_per_minute' => 600,
            'services.ethereum.rpc_url' => 'https://ethereum-rpc.example.test',
            'services.zero_x.base_url' => 'https://zero-x.example.test',
            'services.zero_x.api_key' => 'zero-x-secret-marker',
            'services.solana.rpc_url' => 'https://solana-rpc.example.test',
            'services.jupiter.base_url' => 'https://jupiter.example.test/swap/v1',
            'services.jupiter.swap_v2_base_url' => 'https://jupiter.example.test/swap/v2',
            'services.solana_transaction_validator.url' => 'https://validator.example.test',
            'services.solana_transaction_validator.api_key' => 'validator-secret-marker',
        ]);
    }

    private function bindReadiness(): TradingEngineLiveReadiness
    {
        $readiness = $this->readiness();
        $this->app->instance(TradingEngineLiveReadiness::class, $readiness);

        return $readiness;
    }

    private function readiness(?Schedule $schedule = null): TradingEngineLiveReadiness
    {
        return new class(app(TradingEngineLiveAttemptInspector::class), $schedule ?? app(Schedule::class)) extends TradingEngineLiveReadiness
        {
            protected function sodiumAvailable(): bool
            {
                return true;
            }

            protected function bcMathAvailable(): bool
            {
                return true;
            }
        };
    }

    /**
     * @param  array{checks: list<array{group: string, status: string, name: string, message: string}>}  $result
     */
    private function assertCheck(array $result, string $name, string $status): void
    {
        $check = collect($result['checks'])->firstWhere('name', $name);

        $this->assertIsArray($check, "Readiness check {$name} was not returned.");
        $this->assertSame($status, $check['status']);
    }

    private function uncertainEthereumAttempt(): EthereumSwapAttempt
    {
        $user = User::factory()->create();
        $address = '0x'.str_repeat('1', 40);
        $wallet = ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Ethereum,
            'address' => $address,
            'address_hash' => ConnectedWallet::addressHash(Chain::Ethereum, $address),
        ]);

        return EthereumSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'wallet_address' => $wallet->address,
            'buy_token' => '0x'.str_repeat('2', 40),
            'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => [],
            'status' => 'prepared',
            'signing_requested_at' => now()->subMinutes(20),
            'signing_armed_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinute(),
        ]);
    }
}
