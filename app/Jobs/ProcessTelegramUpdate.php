<?php

namespace App\Jobs;

use App\Models\UserTelegramBot;
use App\Services\TelegramUpdateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    /** @param array<string, mixed> $update */
    public function __construct(public ?int $botId, public array $update) {}

    /**
     * Execute the job.
     */
    public function handle(TelegramUpdateService $updates): void
    {
        if ($this->botId === null) {
            $updates->handle(null, $this->update);

            return;
        }

        $bot = UserTelegramBot::query()->whereKey($this->botId)->where('enabled', true)->first();

        if ($bot) {
            $updates->handle($bot, $this->update);
        }
    }
}
