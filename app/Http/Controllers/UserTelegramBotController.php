<?php

namespace App\Http\Controllers;

use App\Models\TelegramIdentity;
use App\Services\ApplicationSettingsService;
use App\Services\TelegramLinkService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserTelegramBotController extends Controller
{
    public function show(Request $request, ApplicationSettingsService $settings): View
    {
        return view('settings.telegram', [
            'identity' => TelegramIdentity::query()->where('user_id', $request->user()->id)->where('status', 'active')->first(),
            'botUsername' => ltrim(trim((string) $settings->get('telegram.bot_username')), '@'),
            'platformBotAvailable' => (bool) $settings->get('telegram.enabled')
                && (bool) $settings->getSecret('telegram.bot_token')
                && (bool) $settings->getSecret('telegram.webhook_secret')
                && trim((string) $settings->get('telegram.bot_username')) !== '',
        ]);
    }

    public function link(Request $request, TelegramLinkService $links): RedirectResponse
    {
        try {
            return back()->with('telegram_link_url', $links->create($request->user()));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function unlink(Request $request, TelegramLinkService $links): RedirectResponse
    {
        $links->unlink($request->user());

        return back()->with('success', 'Telegram account unlinked.');
    }
}
