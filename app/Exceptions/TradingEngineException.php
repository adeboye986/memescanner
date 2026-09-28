<?php

namespace App\Exceptions;

use RuntimeException;

class TradingEngineException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $responseStatus = null,
    ) {
        parent::__construct($message);
    }

    /** @return array{error_code: string, retryable: bool, response_status: ?int} */
    public function context(): array
    {
        return [
            'error_code' => $this->errorCode,
            'retryable' => $this->retryable,
            'response_status' => $this->responseStatus,
        ];
    }
}
