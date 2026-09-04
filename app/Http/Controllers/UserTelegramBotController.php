<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConnectTelegramBotRequest;
use App\Services\TelegramBotManager;
use App\Services\TelegramLinkService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class UserTelegramBotController extends Controller
{
    public function show(Request $request): View
    {
        return view('settings.telegram', ['bot' => $request->user()->telegramBot()->with('identity')->first()]);
    }

    public function store(ConnectTelegramBotRequest $request, TelegramBotManager $bots): RedirectResponse
    {
        try {
            $bots->connect($request->user(), $request->validated('bot_token'), (string) $request->validated('bot_username'));

            return to_route('telegram.settings')->with('success', 'Telegram bot connected and webhook configured.');
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage())->withInput($request->safe()->except('bot_token'));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Telegram could not verify or configure this bot. Existing settings were preserved.')->withInput($request->safe()->except('bot_token'));
        }
    }

    public function test(Request $request, TelegramBotManager $bots): RedirectResponse
    {
        $bot = $request->user()->telegramBot()->where('enabled', true)->firstOrFail();

        try {
            $bots->verify($bot);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Telegram could not verify this bot. Stored credentials were not changed.');
        }

        return back()->with('success', 'Telegram verified the bot credentials successfully.');
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

    public function destroy(Request $request, TelegramBotManager $bots): RedirectResponse
    {
        $bot = $request->user()->telegramBot()->firstOrFail();
        try {
            $bots->disconnect($bot);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Telegram could not disconnect the webhook. The bot remains enabled.');
        }

        return back()->with('success', 'Telegram bot disconnected. Stored credentials remain encrypted for reconnection.');
    }
}
