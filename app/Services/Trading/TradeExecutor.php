<?php

namespace App\Services\Trading;

use App\Models\PaperPosition;
use App\Models\TradeOpportunity;

interface TradeExecutor
{
    public function execute(TradeOpportunity $opportunity, bool $sendNotification = true): ?PaperPosition;
}
