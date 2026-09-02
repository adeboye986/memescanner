<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\SettingAudit;
use App\Services\ApplicationSettingsService;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class ApplicationSettingsTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
    }

    public function test_config_fallback_and_database_override_are_typed(): void
    {
        config()->set('services.trading.max_chase_percent', 37.5);
        $settings = app(ApplicationSettingsService::class);
        $this->assertSame(37.5, $settings->get('scanner.max_chase_percent'));

        $settings->update(['scanner.max_chase_percent' => 22.25]);

        $this->assertSame(22.25, $settings->get('scanner.max_chase_percent'));
    }

    public function test_secrets_are_encrypted_masked_and_blank_updates_keep_existing_value(): void
    {
        config()->set('services.telegram.bot_token', 'environment-token');
        $settings = app(ApplicationSettingsService::class);
        $settings->update(['telegram.bot_token' => 'database-secret-9FA2']);

        $stored = ApplicationSetting::query()->where('key', 'telegram.bot_token')->sole();
        $this->assertTrue($stored->encrypted);
        $this->assertStringNotContainsString('database-secret', $stored->value);
        $this->assertSame('database-secret-9FA2', Crypt::decryptString($stored->value));
        $this->assertSame('••••••••••••••••9FA2', $settings->presentation()['telegram.bot_token']['masked']);

        $settings->update(['telegram.bot_token' => '']);
        $this->assertSame('database-secret-9FA2', $settings->getSecret('telegram.bot_token'));
        $this->assertDatabaseCount('setting_audits', 1);
        $this->assertSame('[replaced]', SettingAudit::query()->sole()->new_value);
    }

    public function test_environment_secret_fallback_remains_supported(): void
    {
        config()->set('services.telegram.chat_id', '-1001234');

        $this->assertSame('-1001234', app(ApplicationSettingsService::class)->getSecret('telegram.chat_id'));
        $this->assertDatabaseCount('application_settings', 0);
    }
}
