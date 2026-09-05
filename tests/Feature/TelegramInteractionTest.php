<?php

namespace Tests\Feature;

use App\Jobs\RunDashboardCommand;
use App\Models\PaperPosition;
use App\Models\TelegramIdentity;
use App\Models\TelegramLinkToken;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Models\UserTelegramBot;
use App\Services\ApplicationSettingsService;
use App\Services\TelegramLinkService;
use App\Services\TelegramUpdateService;
use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TelegramInteractionTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private UserTelegramBot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        app(ApplicationSettingsService::class)->update([
            'telegram.enabled' => true,
            'telegram.bot_token' => 'test-token',
            'telegram.bot_username' => 'scanner_bot',
            'telegram.webhook_secret' => 'shared-webhook-secret',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->bot = UserTelegramBot::factory()->create(['user_id' => User::factory()->create(['is_admin' => true])]);
    }

    public function test_shared_link_is_one_time_and_numeric_identity_is_authoritative(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $url = app(TelegramLinkService::class)->create($user);
        preg_match('/link_([A-Za-z0-9]+)$/', $url, $matches);
        $identity = app(TelegramLinkService::class)->consume($matches[1], ['id' => 123456, 'username' => 'operator'], '123456');

        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame('123456', $identity->telegram_user_id);
        $this->assertNull($identity->user_telegram_bot_id);
        $this->assertNotNull(TelegramLinkToken::query()->firstOrFail()->consumed_at);
        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($matches[1], ['id' => 123456], '123456');
    }

    public function test_expired_shared_link_is_rejected(): void
    {
        $url = app(TelegramLinkService::class)->create(User::factory()->create());
        preg_match('/link_([A-Za-z0-9]+)$/', $url, $matches);
        TelegramLinkToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($matches[1], ['id' => 123], '123');
    }

    public function test_unauthorized_account_cannot_access_private_menu(): void
    {
        app(TelegramUpdateService::class)->handle($this->bot, $this->message('/menu', 999));
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'not linked'));
    }

    public function test_authorized_account_can_open_menu_and_enqueue_existing_scan_job(): void
    {
        Queue::fake();
        TelegramIdentity::factory()->create(['user_id' => $this->bot->user_id, 'user_telegram_bot_id' => $this->bot->id, 'telegram_user_id' => '123', 'telegram_chat_id' => '123']);
        app(TelegramUpdateService::class)->handle($this->bot, $this->message('/menu', 123));
        app(TelegramUpdateService::class)->handle($this->bot, $this->callbackUpdate('scan_run:solana:token-scan', 123));

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Trading Console'));
        Queue::assertPushed(RunDashboardCommand::class);
        $this->assertDatabaseHas('system_activities', ['action' => 'token-scan', 'chain' => 'solana']);
    }

    public function test_live_mode_requires_confirmation_and_change_is_audited(): void
    {
        $identity = TelegramIdentity::factory()->create(['user_id' => $this->bot->user_id, 'user_telegram_bot_id' => $this->bot->id, 'telegram_user_id' => '123', 'telegram_chat_id' => '123']);
        app(TelegramUpdateService::class)->handle($this->bot, $this->callbackUpdate('setmode:execution:live', 123));
        $this->assertSame('paper', app(ApplicationSettingsService::class)->get('trading.execution_mode'));
        app(TelegramUpdateService::class)->handle($this->bot, $this->callbackUpdate('confirmmode:execution:live', 123));
        $this->assertSame('paper', $identity->user->tradingPreference()->firstOrFail()->execution_mode->value);
        $this->assertSame('paper', app(ApplicationSettingsService::class)->get('trading.execution_mode'));
    }

    public function test_identity_linked_to_another_bot_cannot_use_callbacks(): void
    {
        Queue::fake();
        $otherBot = UserTelegramBot::factory()->create(['user_id' => User::factory()->create(['is_admin' => true])]);
        TelegramIdentity::factory()->create(['user_id' => $this->bot->user_id, 'user_telegram_bot_id' => $this->bot->id, 'telegram_user_id' => '123']);

        app(TelegramUpdateService::class)->handle($otherBot, $this->callbackUpdate('scan_run:solana:token-scan', 123));

        Queue::assertNothingPushed();
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'not authorized'));
    }

    public function test_non_admin_bot_owner_sees_only_their_paper_wallets(): void
    {
        Queue::fake();
        $userBot = UserTelegramBot::factory()->create(['user_id' => User::factory()->create(['is_admin' => false])]);
        TelegramIdentity::factory()->create(['user_id' => $userBot->user_id, 'user_telegram_bot_id' => $userBot->id, 'telegram_user_id' => '456']);

        app(TelegramUpdateService::class)->handle($userBot, $this->callbackUpdate('wallets', 456));

        Queue::assertNothingPushed();
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Paper Wallets'));
        $this->assertDatabaseCount('paper_wallets', 2);
    }

    public function test_normal_user_system_status_hides_admin_operational_diagnostics(): void
    {
        $userBot = UserTelegramBot::factory()->create(['user_id' => User::factory()->create(['is_admin' => false])]);
        TelegramIdentity::factory()->create(['user_id' => $userBot->user_id, 'user_telegram_bot_id' => $userBot->id, 'telegram_user_id' => '456']);

        app(TelegramUpdateService::class)->handle($userBot, $this->callbackUpdate('status', 456));

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Platform: Online')
            && ! str_contains((string) ($request['text'] ?? ''), 'Admin Operations')
            && ! str_contains((string) ($request['text'] ?? ''), 'Failed jobs'));
    }

    public function test_telegram_callbacks_cannot_read_or_change_another_users_opportunity(): void
    {
        $identity = TelegramIdentity::factory()->create(['user_id' => $this->bot->user_id, 'user_telegram_bot_id' => $this->bot->id, 'telegram_user_id' => '123', 'telegram_chat_id' => '123']);
        $opportunity = TradeOpportunity::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending_confirmation',
        ]);

        foreach (['opp:', 'approve:', 'ignore:'] as $action) {
            app(TelegramUpdateService::class)->handle($this->bot, $this->callbackUpdate($action.$opportunity->id, (int) $identity->telegram_user_id));
        }

        $this->assertSame('pending_confirmation', $opportunity->fresh()->status->value);
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'could not be completed safely'));
    }

    public function test_telegram_callbacks_cannot_close_another_users_position(): void
    {
        $identity = TelegramIdentity::factory()->create(['user_id' => $this->bot->user_id, 'user_telegram_bot_id' => $this->bot->id, 'telegram_user_id' => '123', 'telegram_chat_id' => '123']);
        $owner = User::factory()->create();
        $position = PaperPosition::query()->create([
            'user_id' => $owner->id,
            'chain' => 'solana',
            'address' => 'private-position',
            'symbol' => 'PRIVATE',
            'entry_market_cap' => 100_000,
            'last_market_cap' => 100_000,
            'entry_at' => now(),
            'status' => 'open',
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
            'exit_events' => [],
        ]);

        app(TelegramUpdateService::class)->handle($this->bot, $this->callbackUpdate('close_confirm:'.$position->id, (int) $identity->telegram_user_id));

        $this->assertSame('open', $position->fresh()->status);
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'could not be completed safely'));
    }

    private function message(string $text, int $userId): array
    {
        return ['update_id' => 1, 'message' => ['chat' => ['id' => $userId, 'type' => 'private'], 'from' => ['id' => $userId], 'text' => $text]];
    }

    private function callbackUpdate(string $data, int $userId): array
    {
        return ['update_id' => 2, 'callback_query' => ['id' => 'cb', 'from' => ['id' => $userId], 'data' => $data, 'message' => ['chat' => ['id' => $userId, 'type' => 'private'], 'message_id' => 10]]];
    }
}
