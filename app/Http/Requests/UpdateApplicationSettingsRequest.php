<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'application_name' => ['required', 'string', 'max:80'],
            'execution_mode' => ['required', 'in:paper,live'],
            'entry_mode' => ['required', 'in:signal,confirm,auto'],
            'max_chase_percent' => ['required', 'numeric', 'min:0', 'max:500'],
            'telegram_enabled' => ['nullable', 'boolean'],
            'telegram_bot_token' => ['nullable', 'string', 'max:500'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'birdeye_api_key' => ['nullable', 'string', 'max:500'],
            'solana_rpc_url' => ['nullable', 'url:http,https', 'max:1000'],
            'tracker_snapshot_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'kill_switch' => ['nullable', 'boolean'],
            'max_trade_amount' => ['required', 'numeric', 'gt:0'],
            'max_open_positions' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_daily_loss' => ['required', 'numeric', 'gt:0'],
            'max_slippage_percent' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'minimum_wallet_reserve' => ['required', 'numeric', 'min:0'],
            'trade_cooldown_seconds' => ['required', 'integer', 'min:0'],
            'stop_loss_percent' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'protection_level_1_percent' => ['required', 'numeric', 'gt:0'],
            'protection_level_2_percent' => ['required', 'numeric', 'gt:protection_level_1_percent'],
        ];
    }
}
