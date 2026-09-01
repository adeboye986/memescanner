<?php

namespace App\Services;

use App\Models\PaperPosition;

class PaperTradingService
{
    public function __construct(private PaperTradeEntryService $entries) {}

    public function buy(array $data): PaperPosition
    {
        return $this->entries->buy($data);
    }
}
