<?php

namespace App\Services;

use Closure;

class EthereumSwapInputRules
{
    public function __construct(
        private EthereumQuoteLimitService $limits,
        private ApplicationSettingsService $settings,
    ) {}

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $maximumSlippage = min(500, max(1, (int) round((float) $this->settings->get('risk.max_slippage_percent') * 100)));

        return [
            'buy_token' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/', 'not_in:'.ZeroXSwapService::NATIVE_ETH],
            'sell_amount_wei' => ['required', 'string', 'max:78', 'regex:/^[1-9]\d*$/', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $this->limits->exceeds($value, $this->limits->maximumWei())) {
                    $fail('The ETH amount exceeds the configured maximum trade amount.');
                }
            }],
            'slippage_bps' => ['required', 'integer', 'min:1', 'max:'.$maximumSlippage],
        ];
    }
}
