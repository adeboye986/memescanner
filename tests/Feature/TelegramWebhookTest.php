<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
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

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'a-secure-webhook-secret')->postJson(route('telegram.webhook'), [
            'update_id' => 123,
            'message' => ['chat' => ['id' => 456, 'type' => 'private'], 'from' => ['id' => 789], 'text' => '/start'],
        ])->assertOk()->assertExactJson(['ok' => true]);

        Queue::assertPushed(ProcessTelegramUpdate::class);
    }

    public function test_invalid_secret_is_rejected_without_queueing(): void
    {
        Queue::fake();
        $payload = ['update_id' => 1, 'message' => ['chat' => ['id' => 2, 'type' => 'private'], 'from' => ['id' => 3], 'text' => '/start']];

        $this->postJson(route('telegram.webhook'), $payload)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')->postJson(route('telegram.webhook'), $payload)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_malformed_update_is_rejected(): void
    {
        Queue::fake();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'a-secure-webhook-secret')->postJson(route('telegram.webhook'), ['update_id' => 1])->assertUnprocessable();
        Queue::assertNothingPushed();
    }
}
