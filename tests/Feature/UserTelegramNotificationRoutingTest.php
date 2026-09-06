<?php

namespace Tests\Feature;

use App\Enums\EntryMode;
use App\Models\PaperWallet;
use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\UserTelegramBot;
use App\Services\PaperTradeEntryService;
use App\Services\PaperTradeExitService;
use App\Services\TelegramService;
use App\Services\TradeOpportunityService;
use App\Services\UserTelegramNotificationService;
use App\Services\UserTradingBootstrapService;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class UserTelegramNotificationRoutingTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
    }

    public function test_shared_identity_receives_private_notification_without_global_fallback(): void
    {
        $user = User::factory()->create();
        TelegramIdentity::factory()->for($user)->create(['user_telegram_bot_id' => null, 'telegram_chat_id' => 'private-a']);

        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('private-a', 'hello')->andReturn([]);
        $telegram->shouldReceive('send')->never();

        app(UserTelegramNotificationService::class)->send($user, 'hello');
    }

    public function test_notifications_are_isolated_between_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        TelegramIdentity::factory()->for($userA)->create(['user_telegram_bot_id' => null, 'telegram_chat_id' => 'private-a']);
        TelegramIdentity::factory()->for($userB)->create(['user_telegram_bot_id' => null, 'telegram_chat_id' => 'private-b']);

        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('private-a', 'for-a')->andReturn([]);
        $telegram->shouldReceive('sendMessage')->once()->with('private-b', 'for-b')->andReturn([]);
        $telegram->shouldReceive('send')->never();

        $router = app(UserTelegramNotificationService::class);
        $router->send($userA, 'for-a');
        $router->send($userB, 'for-b');
    }

    public function test_byob_identity_is_used_when_no_shared_identity_exists(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $user = User::factory()->create();
        $bot = UserTelegramBot::factory()->for($user)->create(['bot_token' => '123456:test-token-value']);
        TelegramIdentity::factory()->for($user)->create(['user_telegram_bot_id' => $bot->id, 'telegram_chat_id' => 'byob-chat']);

        $this->mock(TelegramService::class)->shouldReceive('send')->never();

        app(UserTelegramNotificationService::class)->send($user, 'byob-message');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/bot123456:test-token-value/sendMessage')
            && $request['chat_id'] === 'byob-chat'
            && $request['text'] === 'byob-message');
    }

    public function test_missing_identity_throws_without_using_global_chat(): void
    {
        $user = User::factory()->create();
        $this->mock(TelegramService::class)->shouldReceive('send')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User does not have an active Telegram identity.');

        app(UserTelegramNotificationService::class)->send($user, 'private-message');
    }

    public function test_customer_paper_buy_routes_through_customer_notification_service(): void
    {
        $user = User::factory()->create();
        app(UserTradingBootstrapService::class)->bootstrap($user);

        $this->mock(UserTelegramNotificationService::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(fn (User $recipient, string $message): bool => $recipient->is($user) && str_contains($message, 'PAPER BUY EXECUTED'));
        $this->mock(TelegramService::class)->shouldReceive('send')->never();

        $position = app(PaperTradeEntryService::class)->buy([
            ...$this->opportunity('buy-token'),
            'user_id' => $user->id,
        ]);

        $this->assertTrue($position->user->is($user));
    }

    public function test_missing_identity_does_not_roll_back_customer_paper_buy_or_fall_back_globally(): void
    {
        $user = User::factory()->create();
        app(UserTradingBootstrapService::class)->bootstrap($user);
        $this->mock(TelegramService::class)->shouldReceive('send')->never();

        $position = app(PaperTradeEntryService::class)->buy([
            ...$this->opportunity('no-identity-buy'),
            'user_id' => $user->id,
        ]);

        $this->assertSame('open', $position->status);
        $this->assertEqualsWithDelta(4.9, $user->paperWallets()->where('chain', 'solana')->sole()->available_balance_sol, 0.000001);
    }

    public function test_ownerless_paper_buy_retains_legacy_global_notification(): void
    {
        PaperWallet::query()->create([
            'name' => 'default',
            'chain' => 'solana',
            'currency' => 'SOL',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 5,
            'invested_balance_sol' => 0,
            'realized_pnl_sol' => 0,
        ]);
        $this->mock(TelegramService::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'PAPER BUY EXECUTED'));

        $position = app(PaperTradeEntryService::class)->buy($this->opportunity('ownerless-buy'));

        $this->assertNull($position->user_id);
    }

    public function test_customer_manual_close_routes_through_customer_notification_service(): void
    {
        Http::fake([
            'api.dexscreener.com/token-pairs/v1/solana/manual-close-token' => Http::response([[
                'baseToken' => ['address' => 'manual-close-token', 'symbol' => 'TEST'],
                'quoteToken' => ['address' => 'sol', 'symbol' => 'SOL'],
                'marketCap' => 12_000,
                'priceUsd' => '0.0012',
                'liquidity' => ['usd' => 2_000],
            ]]),
        ]);

        $user = User::factory()->create();
        app(UserTradingBootstrapService::class)->bootstrap($user);
        $position = app(PaperTradeEntryService::class)->buy([
            ...$this->opportunity('manual-close-token'),
            'user_id' => $user->id,
            'send_notification' => false,
        ]);

        $this->mock(UserTelegramNotificationService::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(fn (User $recipient, string $message): bool => $recipient->is($user) && str_contains($message, 'PAPER TRADE MANUALLY CLOSED'));
        $this->mock(TelegramService::class)->shouldReceive('send')->never();

        $result = app(PaperTradeExitService::class)->closeManually($position);

        $this->assertSame('closed', $result['position']->status);
        $this->assertSame('manual_close', $result['event']['type']);
    }

    public function test_signal_and_confirm_opportunities_notify_only_the_owning_shared_chat(): void
    {
        $signalUser = User::factory()->create();
        $confirmUser = User::factory()->create();
        $signalUser->tradingPreference()->create(['execution_mode' => 'paper', 'entry_mode' => EntryMode::Signal, 'trading_enabled' => true]);
        $confirmUser->tradingPreference()->create(['execution_mode' => 'paper', 'entry_mode' => EntryMode::Confirm, 'trading_enabled' => true]);
        TelegramIdentity::factory()->for($signalUser)->create(['user_telegram_bot_id' => null, 'telegram_chat_id' => 'signal-chat']);
        TelegramIdentity::factory()->for($confirmUser)->create(['user_telegram_bot_id' => null, 'telegram_chat_id' => 'confirm-chat']);

        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->withArgs(fn (string $chatId, string $message): bool => $chatId === 'signal-chat' && str_contains($message, 'Mode: SIGNAL'))->andReturn([]);
        $telegram->shouldReceive('sendMessage')->once()->withArgs(fn (string $chatId, string $message): bool => $chatId === 'confirm-chat' && str_contains($message, 'Mode: CONFIRM'))->andReturn([]);
        $telegram->shouldReceive('send')->never();

        $service = app(TradeOpportunityService::class);
        $service->qualify($this->opportunity('signal-token'), $signalUser);
        $service->qualify($this->opportunity('confirm-token'), $confirmUser);
    }

    public function test_scheduled_auto_opportunity_notifies_only_the_position_owner(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        app(UserTradingBootstrapService::class)->bootstrap($userA);
        app(UserTradingBootstrapService::class)->bootstrap($userB);
        $userA->tradingPreference()->update(['execution_mode' => 'paper', 'entry_mode' => EntryMode::Auto, 'trading_enabled' => true]);
        $userB->tradingPreference()->update(['execution_mode' => 'paper', 'entry_mode' => EntryMode::Auto, 'trading_enabled' => false]);
        TelegramIdentity::factory()->for($userA)->create(['user_telegram_bot_id' => null, 'telegram_chat_id' => 'auto-owner-chat']);
        TelegramIdentity::factory()->for($userB)->create(['user_telegram_bot_id' => null, 'telegram_chat_id' => 'other-user-chat']);

        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->withArgs(fn (string $chatId, string $message): bool => $chatId === 'auto-owner-chat' && str_contains($message, 'PAPER BUY EXECUTED'))->andReturn([]);
        $telegram->shouldReceive('sendMessage')->with('other-user-chat', Mockery::any())->never();
        $telegram->shouldReceive('send')->never();

        app(TradeOpportunityService::class)->qualify($this->opportunity('scheduled-auto'));

        $this->assertDatabaseHas('paper_positions', ['user_id' => $userA->id, 'address' => 'scheduled-auto', 'status' => 'open']);
        $this->assertDatabaseMissing('paper_positions', ['user_id' => $userB->id, 'address' => 'scheduled-auto']);
    }

    /** @return array<string, mixed> */
    private function opportunity(string $address): array
    {
        return [
            'chain' => 'solana',
            'address' => $address,
            'symbol' => 'TEST',
            'name' => 'Test Token',
            'entry_market_cap' => 10_000,
            'entry_price' => 0.001,
            'entry_liquidity' => 2_000,
            'scanner' => 'test',
            'send_notification' => true,
        ];
    }
}
