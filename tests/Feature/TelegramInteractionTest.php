<?php

namespace Tests\Feature;

use App\Jobs\RunDashboardCommand;
use App\Models\TelegramIdentity;
use App\Models\TelegramLinkToken;
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
        app(ApplicationSettingsService::class)->update(['telegram.enabled' => true, 'telegram.bot_token' => 'test-token', 'telegram.bot_username' => 'scanner_bot']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->bot = UserTelegramBot::factory()->create(['user_id' => User::factory()->create(['is_admin' => true])]);
    }

    public function test_link_is_one_time_and_numeric_identity_is_authoritative(): void
    {
        $user = $this->bot->user;
        $url = app(TelegramLinkService::class)->create($user);
        preg_match('/link_([A-Za-z0-9]+)$/', $url, $matches);
        $identity = app(TelegramLinkService::class)->consume($matches[1], ['id' => 123456, 'username' => 'operator'], '123456', $this->bot);

        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame('123456', $identity->telegram_user_id);
        $this->assertNotNull(TelegramLinkToken::query()->firstOrFail()->consumed_at);
        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($matches[1], ['id' => 123456], '123456', $this->bot);
    }

    public function test_expired_link_is_rejected(): void
    {
        $url = app(TelegramLinkService::class)->create($this->bot->user);
        preg_match('/link_([A-Za-z0-9]+)$/', $url, $matches);
        TelegramLinkToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($matches[1], ['id' => 123], '123', $this->bot);
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
        $this->assertSame('live', app(ApplicationSettingsService::class)->get('trading.execution_mode'));
        $this->assertDatabaseHas('setting_audits', ['user_id' => $identity->user_id, 'setting_key' => 'trading.execution_mode']);
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

    public function test_non_admin_bot_owner_cannot_access_global_trading_data(): void
    {
        Queue::fake();
        $userBot = UserTelegramBot::factory()->create(['user_id' => User::factory()->create(['is_admin' => false])]);
        TelegramIdentity::factory()->create(['user_id' => $userBot->user_id, 'user_telegram_bot_id' => $userBot->id, 'telegram_user_id' => '456']);

        app(TelegramUpdateService::class)->handle($userBot, $this->callbackUpdate('wallets', 456));

        Queue::assertNothingPushed();
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'admin-only'));
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
