<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\UserTelegramBot;
use App\Services\ApplicationSettingsService;
use App\Services\TelegramBotManager;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, ApplicationSettingsService $settings, TelegramBotManager $bots, TelegramService $legacyTelegram, ?string $publicId = null): JsonResponse
    {
        $bot = $publicId === null ? null : UserTelegramBot::query()->where('public_id', $publicId)->where('enabled', true)->firstOrFail();
        $expectedSecret = $bot ? (string) $bot->webhook_secret : (string) $settings->getSecret('telegram.webhook_secret');
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

        if (isset($validated['callback_query']['id'])) {
            try {
                ($bot ? $bots->client($bot) : $legacyTelegram->client())
                    ->answerCallbackQuery((string) $validated['callback_query']['id']);
            } catch (Throwable $exception) {
                report($exception);
            }

            $validated['callback_query']['_acknowledged'] = true;
        }

        ProcessTelegramUpdate::dispatch($bot?->id, $validated);

        return response()->json(['ok' => true]);
    }
}
