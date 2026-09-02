<?php

namespace App\Http\Controllers;

use App\Services\TelegramLinkService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramAccessController extends Controller
{
    public function store(Request $request, TelegramLinkService $links): RedirectResponse
    {
        try {
            return to_route('settings.index')->with('telegram_link_url', $links->create($request->user()));
        } catch (DomainException $exception) {
            return to_route('settings.index')->with('error', $exception->getMessage());
        }
    }

    public function destroy(Request $request, TelegramLinkService $links): RedirectResponse
    {
        $links->unlink($request->user());

        return to_route('settings.index')->with('success', 'Telegram account unlinked.');
    }
}
