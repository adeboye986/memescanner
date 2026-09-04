<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\UserTelegramBot;
use App\Services\ApplicationSettingsService;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        app(ApplicationSettingsService::class)->update(['telegram.webhook_secret' => 'a-secure-webhook-secret']);
    }

    public function test_valid_secret_accepts_and_queues_update_without_csrf_token(): void
    {
        Queue::fake();
        $bot = UserTelegramBot::factory()->create(['webhook_secret' => 'bot-specific-secret']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'bot-specific-secret')->postJson(route('telegram.user-webhook', $bot->public_id), [
            'update_id' => 123,
            'message' => ['chat' => ['id' => 456, 'type' => 'private'], 'from' => ['id' => 789], 'text' => '/start'],
        ])->assertOk()->assertExactJson(['ok' => true]);

        Queue::assertPushed(ProcessTelegramUpdate::class, fn (ProcessTelegramUpdate $job): bool => $job->botId === $bot->id && ! str_contains(serialize($job), 'test-token-value'));
    }

    public function test_invalid_secret_is_rejected_without_queueing(): void
    {
        Queue::fake();
        $bot = UserTelegramBot::factory()->create(['webhook_secret' => 'bot-specific-secret']);
        $payload = ['update_id' => 1, 'message' => ['chat' => ['id' => 2, 'type' => 'private'], 'from' => ['id' => 3], 'text' => '/start']];

        $this->postJson(route('telegram.user-webhook', $bot->public_id), $payload)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')->postJson(route('telegram.user-webhook', $bot->public_id), $payload)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_malformed_update_is_rejected(): void
    {
        Queue::fake();
        $bot = UserTelegramBot::factory()->create(['webhook_secret' => 'bot-specific-secret']);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'bot-specific-secret')->postJson(route('telegram.user-webhook', $bot->public_id), ['update_id' => 1])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_bot_secrets_are_isolated_and_unknown_or_disabled_bots_are_rejected(): void
    {
        Queue::fake();
        $botA = UserTelegramBot::factory()->create(['webhook_secret' => 'secret-for-bot-a']);
        $botB = UserTelegramBot::factory()->create(['webhook_secret' => 'secret-for-bot-b']);
        $payload = ['update_id' => 1, 'message' => ['chat' => ['id' => 2, 'type' => 'private'], 'from' => ['id' => 3], 'text' => '/start']];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret-for-bot-a')->postJson(route('telegram.user-webhook', $botB->public_id), $payload)->assertForbidden();
        $this->postJson(route('telegram.user-webhook', 'unknown-public-id'), $payload)->assertNotFound();
        $botA->update(['enabled' => false]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret-for-bot-a')->postJson(route('telegram.user-webhook', $botA->public_id), $payload)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_each_user_webhook_queues_its_own_bot_context(): void
    {
        Queue::fake();
        $botA = UserTelegramBot::factory()->create(['webhook_secret' => 'secret-for-bot-a']);
        $botB = UserTelegramBot::factory()->create(['webhook_secret' => 'secret-for-bot-b']);
        $payload = ['update_id' => 1, 'message' => ['chat' => ['id' => 2, 'type' => 'private'], 'from' => ['id' => 3], 'text' => '/start']];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret-for-bot-a')->postJson(route('telegram.user-webhook', $botA->public_id), $payload)->assertOk();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret-for-bot-b')->postJson(route('telegram.user-webhook', $botB->public_id), $payload)->assertOk();

        Queue::assertPushed(ProcessTelegramUpdate::class, fn (ProcessTelegramUpdate $job): bool => $job->botId === $botA->id);
        Queue::assertPushed(ProcessTelegramUpdate::class, fn (ProcessTelegramUpdate $job): bool => $job->botId === $botB->id);
    }

    public function test_legacy_global_webhook_remains_available(): void
    {
        Queue::fake();
        $payload = ['update_id' => 1, 'message' => ['chat' => ['id' => 2, 'type' => 'private'], 'from' => ['id' => 3], 'text' => '/start']];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'a-secure-webhook-secret')->postJson(route('telegram.webhook'), $payload)->assertOk();

        Queue::assertPushed(ProcessTelegramUpdate::class, fn (ProcessTelegramUpdate $job): bool => $job->botId === null);
    }
}
