<?php

namespace App\Http\Requests;

use App\Services\ApplicationSettingsService;
use App\Services\EthereumQuoteLimitService;
use App\Services\ZeroXSwapService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class EthereumSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(EthereumQuoteLimitService $limits, ApplicationSettingsService $settings): array
    {
        $maximumSlippage = min(500, max(1, (int) round((float) $settings->get('risk.max_slippage_percent') * 100)));

        return [
            'buy_token' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/', 'not_in:'.ZeroXSwapService::NATIVE_ETH],
            'sell_amount_wei' => ['required', 'string', 'max:78', 'regex:/^[1-9]\d*$/', function (string $attribute, mixed $value, Closure $fail) use ($limits): void {
                if (is_string($value) && $limits->exceeds($value, $limits->maximumWei())) {
                    $fail('The ETH amount exceeds the configured maximum trade amount.');
                }
            }],
            'slippage_bps' => ['required', 'integer', 'min:1', 'max:'.$maximumSlippage],
        ];
    }
}
