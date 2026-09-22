<?php

namespace App\Exceptions;

use RuntimeException;

class EthereumAccountingException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $state = 'pending')
    {
        parent::__construct($reason);
    }
}
