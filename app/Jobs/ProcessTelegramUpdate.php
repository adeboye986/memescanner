<?php

namespace App\Jobs;

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
    public function __construct(public array $update) {}

    /**
     * Execute the job.
     */
    public function handle(TelegramUpdateService $updates): void
    {
        $updates->handle($this->update);
    }
}
