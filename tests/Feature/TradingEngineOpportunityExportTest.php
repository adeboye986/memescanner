<?php

namespace Tests\Feature;

use App\Enums\TradeOpportunityStatus;
use App\Jobs\SubmitTradingEngineOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\TelegramService;
use App\Services\TradeOpportunityService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TradingEngineOpportunityExportTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        app(ApplicationSettingsService::class)->update(['trading.entry_mode' => 'signal']);
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
    }

    public function test_engine_enabled_alone_does_not_export(): void
    {
        Queue::fake([SubmitTradingEngineOpportunity::class]);
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
        ]);

        app(TradeOpportunityService::class)->qualify($this->candidate(), User::factory()->create());

        Queue::assertNothingPushed();
    }

    public function test_disabled_engine_does_not_export_even_when_export_flag_is_enabled(): void
    {
        Queue::fake([SubmitTradingEngineOpportunity::class]);
        config()->set([
            'services.trading_engine.enabled' => false,
            'services.trading_engine.opportunity_export_enabled' => true,
        ]);

        app(TradeOpportunityService::class)->qualify($this->candidate(), User::factory()->create());

        Queue::assertNothingPushed();
    }

    public function test_both_flags_export_one_new_supported_user_opportunity_before_unchanged_policy(): void
    {
        Queue::fake([SubmitTradingEngineOpportunity::class]);
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => true,
        ]);
        app(ApplicationSettingsService::class)->update(['trading.entry_mode' => 'confirm']);
        $user = User::factory()->create(['is_admin' => true]);

        $result = app(TradeOpportunityService::class)->qualify($this->candidate(), $user);

        Queue::assertPushed(SubmitTradingEngineOpportunity::class, function ($job) use ($result, $user): bool {
            return $job->idempotencyKey === 'opportunity:record:laravel:'.$result['opportunity']->id.':v1'
                && $job->payload['source']['opportunity_id'] === (string) $result['opportunity']->id
                && $job->payload['subject']['control_plane_user_id'] === (string) $user->id
                && $job->payload['source']['scanner'] === 'new-token';
        });
        $this->assertNull($result['position']);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $result['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_legacy_null_user_and_unsupported_scanner_are_not_exported(): void
    {
        Queue::fake([SubmitTradingEngineOpportunity::class]);
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => true,
        ]);

        app(TradeOpportunityService::class)->qualify($this->candidate());
        app(TradeOpportunityService::class)->qualify(
            [...$this->candidate(), 'scanner' => 'legacy'],
            User::factory()->create(),
        );

        Queue::assertNothingPushed();
    }

    public function test_existing_duplicate_opportunity_does_not_enqueue_again(): void
    {
        Queue::fake([SubmitTradingEngineOpportunity::class]);
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => true,
        ]);
        $user = User::factory()->create();

        app(TradeOpportunityService::class)->qualify($this->candidate(), $user);
        app(TradeOpportunityService::class)->qualify($this->candidate(), $user);

        Queue::assertPushed(SubmitTradingEngineOpportunity::class, 1);
    }

    public function test_synchronous_queue_dispatch_failure_does_not_change_policy_behavior(): void
    {
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => true,
        ]);
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));

        $result = app(TradeOpportunityService::class)->qualify(
            $this->candidate(),
            User::factory()->create(),
        );

        $this->assertNull($result['position']);
        $this->assertSame(TradeOpportunityStatus::Qualified, $result['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    /** @return array<string, mixed> */
    private function candidate(): array
    {
        return [
            'chain' => 'solana',
            'address' => 'So11111111111111111111111111111111111111112',
            'symbol' => 'TEST',
            'name' => 'Test Token',
            'discovery_key' => str_repeat('a', 64),
            'discovery_market_cap' => 10000,
            'entry_market_cap' => 12000,
            'entry_price' => '0.00000125',
            'entry_liquidity' => 3000,
            'volume' => 800,
            'move_since_discovery_percent' => 20,
            'scanner' => 'new-token',
            'send_notification' => false,
            'meta' => ['entry_source' => 'birdeye_provisional'],
        ];
    }
}
