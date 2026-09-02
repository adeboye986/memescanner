<?php

namespace App\Services\Trading;

use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Services\PaperTradeEntryService;

class PaperTradeExecutor implements TradeExecutor
{
    public function __construct(private PaperTradeEntryService $entries) {}

    public function execute(TradeOpportunity $opportunity, bool $sendNotification = true): PaperPosition
    {
        return $this->entries->buy([
            'chain' => $opportunity->chain->value,
            'address' => $opportunity->address,
            'symbol' => $opportunity->symbol,
            'name' => $opportunity->name,
            'discovery_market_cap' => data_get($opportunity->qualification_data, 'discovery_market_cap', $opportunity->market_cap),
            'entry_market_cap' => $opportunity->market_cap,
            'entry_price' => $opportunity->price,
            'entry_liquidity' => $opportunity->liquidity,
            'move_since_discovery_percent' => data_get($opportunity->qualification_data, 'move_since_discovery_percent'),
            'scanner' => $opportunity->scanner,
            'send_notification' => $sendNotification && (bool) data_get($opportunity->qualification_data, 'send_notification', true),
            'meta' => [
                ...(array) data_get($opportunity->qualification_data, 'meta', []),
                'trade_opportunity_id' => $opportunity->id,
            ],
        ]);
    }

    public function sendNotification(PaperPosition $position): void
    {
        if ($position->wasRecentlyCreated) {
            $this->entries->sendBuyNotification($position);
        }
    }
}
