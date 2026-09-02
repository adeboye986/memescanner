<?php

namespace App\Services\Trading;

use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use RuntimeException;

class LiveTradeExecutor implements TradeExecutor
{
    public function execute(TradeOpportunity $opportunity, bool $sendNotification = true): ?PaperPosition
    {
        throw new RuntimeException('Live execution is not enabled yet.');
    }
}
