<?php

namespace App\Http\Requests;

use App\Services\ApplicationSettingsService;
use App\Services\SolanaQuoteLimitService;
use App\Services\SolanaSwapQuoteService;
use App\Services\SolanaWalletConnectionService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SolanaSwapQuoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(
        SolanaQuoteLimitService $limits,
        ApplicationSettingsService $settings,
        SolanaWalletConnectionService $wallets,
    ): array {
        $maximumSlippageBps = min(500, max(1, (int) round((float) $settings->get('risk.max_slippage_percent') * 100)));

        return [
            'input_mint' => ['required', 'string', Rule::in([SolanaSwapQuoteService::SOL_MINT])],
            'output_mint' => [
                'required',
                'string',
                'different:input_mint',
                function (string $attribute, mixed $value, Closure $fail) use ($wallets): void {
                    if (! is_string($value) || ! $wallets->isValidAddress($value)) {
                        $fail('The output mint must be a valid Solana address.');
                    }
                },
            ],
            'amount' => ['required', 'integer', 'min:1', 'max:'.$limits->maximumLamports()],
            'slippage_bps' => ['required', 'integer', 'min:1', 'max:'.$maximumSlippageBps],
        ];
    }
}
