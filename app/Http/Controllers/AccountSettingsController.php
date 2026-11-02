<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountRequest;
use App\Models\TelegramIdentity;
use App\Services\ApplicationSettingsService;
use App\Services\PaperStrategyService;
use App\Services\UserTradingPreferenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountSettingsController extends Controller
{
    public function edit(
        Request $request,
        UserTradingPreferenceService $preferences,
        PaperStrategyService $strategies,
        ApplicationSettingsService $settings,
    ): View {
        $user = $request->user();
        $sections = ['general', 'trading', 'risk', 'notifications', 'security'];

        if ($user->is_admin) {
            $sections[] = 'diagnostics';
        }

        $activeSection = strtolower((string) $request->query('section', 'trading'));

        if (! in_array($activeSection, $sections, true)) {
            $activeSection = 'trading';
        }

        return view('account.edit', [
            'activeSection' => $activeSection,
            'identity' => TelegramIdentity::query()
                ->where('user_id', $user->id)
                ->whereNull('user_telegram_bot_id')
                ->where('status', 'active')
                ->first(),
            'paperStrategy' => $strategies->forUser($user),
            'platformBotAvailable' => (bool) $settings->get('telegram.enabled')
                && (bool) $settings->getSecret('telegram.bot_token')
                && (bool) $settings->getSecret('telegram.webhook_secret')
                && trim((string) $settings->get('telegram.bot_username')) !== '',
            'preference' => $preferences->forUser($user),
            'sections' => $sections,
            'user' => $user,
        ]);
    }

    public function update(UpdateAccountRequest $request): RedirectResponse
    {
        $user = $request->user();
        $emailChanged = $user->email !== $request->validated('email');
        $user->fill($request->validated());

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('success', 'Account details updated.');
    }
}
