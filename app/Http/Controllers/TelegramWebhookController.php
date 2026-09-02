<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Services\ApplicationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, ApplicationSettingsService $settings): JsonResponse
    {
        $expectedSecret = $settings->getSecret('telegram.webhook_secret');
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! $expectedSecret || ! hash_equals($expectedSecret, $providedSecret)) {
            abort(403);
        }

        $validated = $request->validate([
            'update_id' => ['required', 'integer'],
            'message' => ['nullable', 'array', 'required_without:callback_query'],
            'message.chat.id' => ['required_with:message'],
            'message.chat.type' => ['required_with:message', 'string', 'in:private,group,supergroup,channel'],
            'message.from.id' => ['required_with:message'],
            'message.text' => ['nullable', 'string', 'max:4096'],
            'callback_query' => ['nullable', 'array', 'required_without:message'],
            'callback_query.id' => ['required_with:callback_query', 'string', 'max:255'],
            'callback_query.from.id' => ['required_with:callback_query'],
            'callback_query.data' => ['required_with:callback_query', 'string', 'max:64'],
            'callback_query.message.chat.id' => ['required_with:callback_query'],
            'callback_query.message.chat.type' => ['required_with:callback_query', 'string', 'in:private,group,supergroup,channel'],
            'callback_query.message.message_id' => ['required_with:callback_query', 'integer'],
        ]);

        ProcessTelegramUpdate::dispatch($validated);

        return response()->json(['ok' => true]);
    }
}
