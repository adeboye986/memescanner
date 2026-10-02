<?php

namespace App\Services\TradingEngine;

use DateTimeInterface;

final class TradingEngineProjectionTimestamp
{
    public function representsSameStoredInstant(
        DateTimeInterface $first,
        DateTimeInterface $second,
    ): bool {
        return $first->getTimestamp() === $second->getTimestamp();
    }
}
