<?php

namespace App\Services;

use App\Models\TelegramIdentity;

class TelegramCommandRouter
{
    public function __construct(private TelegramMenuService $menus) {}

    public function handle(TelegramBotClient $telegram, TelegramIdentity $identity, string $chatId, string $command): void
    {
        $this->menus->main($telegram, $chatId, null, $identity);
    }

    /** @return array<int, array<int, array<string, string>>> */
    public function mainKeyboard(): array
    {
        return TelegramMenuService::MAIN_KEYBOARD;
    }
}
