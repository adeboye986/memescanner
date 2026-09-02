<?php

namespace App\Services;

use Closure;
use Throwable;

class DatabaseLockRetryService
{
    public function run(Closure $operation, Closure $onRetry): mixed
    {
        $maximumRetries = max(0, (int) config('services.trading.sqlite_lock_retries', 3));
        $backoffMilliseconds = max(0, (int) config('services.trading.sqlite_lock_backoff_ms', 50));

        for ($retry = 0; ; $retry++) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                if (! $this->isLockException($exception) || $retry >= $maximumRetries) {
                    throw $exception;
                }

                $nextRetry = $retry + 1;
                $onRetry($nextRetry, $maximumRetries);

                if ($backoffMilliseconds > 0) {
                    usleep($backoffMilliseconds * $nextRetry * 1000);
                }
            }
        }
    }

    public function isLockException(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());

            if (str_contains($message, 'database is locked') || str_contains($message, 'database table is locked')) {
                return true;
            }
        }

        return false;
    }
}
