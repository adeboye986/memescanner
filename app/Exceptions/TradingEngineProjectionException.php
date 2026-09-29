<?php

namespace App\Exceptions;

use RuntimeException;

class TradingEngineProjectionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $safeMessage,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($safeMessage);
    }

    /** @return array{error_code: string, retryable: bool} */
    public function context(): array
    {
        return [
            'error_code' => $this->errorCode,
            'retryable' => $this->retryable,
        ];
    }
}
