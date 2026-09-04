<?php

namespace App\Http\Controllers;

use App\Models\SettingAudit;
use App\Services\ApplicationSettingsService;
use App\Services\IntegrationStatusService;
use App\Services\PaperStrategyService;
use Illuminate\Contracts\View\View;

class SettingsController extends Controller
{
    public function __invoke(ApplicationSettingsService $settings, PaperStrategyService $strategies, IntegrationStatusService $integrations): View
    {
        return view('settings.index', [
            'settings' => collect($settings->presentation())->groupBy('group'),
            'strategy' => $strategies->forNewPosition(),
            'audits' => SettingAudit::query()->latest()->limit(12)->get(),
            'integrations' => $integrations->all(),
        ]);
    }
}
