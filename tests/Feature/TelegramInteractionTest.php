<?php

namespace Tests\Feature;

use App\Jobs\RunDashboardCommand;
use App\Models\TelegramIdentity;
use App\Models\TelegramLinkToken;
use App\Models\User;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        app(ApplicationSettingsService::class)->update(['telegram.enabled' => true, 'telegram.bot_token' => 'test-token', 'telegram.bot_username' => 'scanner_bot']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    public function test_link_is_one_time_and_numeric_identity_is_authoritative(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $url = app(TelegramLinkService::class)->create($user);
        preg_match('/link_([A-Za-z0-9]+)$/', $url, $matches);
        $identity = app(TelegramLinkService::class)->consume($matches[1], ['id' => 123456, 'username' => 'operator'], '123456');

        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame('123456', $identity->telegram_user_id);
        $this->assertNotNull(TelegramLinkToken::query()->firstOrFail()->consumed_at);
        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($matches[1], ['id' => 123456], '123456');
    }

    public function test_expired_link_is_rejected(): void
    {
        $url = app(TelegramLinkService::class)->create(User::factory()->create());
        preg_match('/link_([A-Za-z0-9]+)$/', $url, $matches);
        TelegramLinkToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($matches[1], ['id' => 123], '123');
    }

    public function test_unauthorized_account_cannot_access_private_menu(): void
    {
        app(TelegramUpdateService::class)->handle($this->message('/menu', 999));
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'not linked'));
    }

    public function test_authorized_account_can_open_menu_and_enqueue_existing_scan_job(): void
    {
        Queue::fake();
        TelegramIdentity::factory()->create(['telegram_user_id' => '123', 'telegram_chat_id' => '123']);
        app(TelegramUpdateService::class)->handle($this->message('/menu', 123));
        app(TelegramUpdateService::class)->handle($this->callbackUpdate('scan_run:solana:token-scan', 123));

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Trading Console'));
        Queue::assertPushed(RunDashboardCommand::class);
        $this->assertDatabaseHas('system_activities', ['action' => 'token-scan', 'chain' => 'solana']);
    }

    public function test_live_mode_requires_confirmation_and_change_is_audited(): void
    {
        $identity = TelegramIdentity::factory()->create(['telegram_user_id' => '123', 'telegram_chat_id' => '123']);
        app(TelegramUpdateService::class)->handle($this->callbackUpdate('setmode:execution:live', 123));
        $this->assertSame('paper', app(ApplicationSettingsService::class)->get('trading.execution_mode'));
        app(TelegramUpdateService::class)->handle($this->callbackUpdate('confirmmode:execution:live', 123));
        $this->assertSame('live', app(ApplicationSettingsService::class)->get('trading.execution_mode'));
        $this->assertDatabaseHas('setting_audits', ['user_id' => $identity->user_id, 'setting_key' => 'trading.execution_mode']);
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
