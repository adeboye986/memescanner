<?php

namespace App\Services\Trading;

use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Services\PaperTradeEntryService;
use App\Services\TradingEngine\TradingEnginePaperLifecycleEnrollment;

class PaperTradeExecutor implements TradeExecutor
{
    public function __construct(
        private PaperTradeEntryService $entries,
        private TradingEnginePaperLifecycleEnrollment $lifecycleEnrollment,
    ) {}

    public function execute(TradeOpportunity $opportunity, bool $sendNotification = true): PaperPosition
    {
        $position = $this->entries->buy([
            'user_id' => $opportunity->user_id,
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

        $this->lifecycleEnrollment->enroll($position, $opportunity);

        return $position;
    }

    public function sendNotification(PaperPosition $position): void
    {
        if ($position->wasRecentlyCreated) {
            $this->entries->sendBuyNotification($position);
        }
    }
}
