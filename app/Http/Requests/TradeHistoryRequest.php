<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TradeHistoryRequest extends FormRequest
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
    public function rules(): array
    {
        return [
            'tab' => ['sometimes', Rule::in(['positions', 'history', 'performance'])],
            'source' => ['sometimes', Rule::in(['engine', 'legacy'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'open', 'closed'])],
            'result' => ['sometimes', Rule::in(['all', 'wins', 'losses', 'break-even'])],
            'exit_type' => ['sometimes', Rule::in(['all', 'manual', 'stop-loss', 'full-target', 'protected-floor', 'other'])],
            'chain' => ['sometimes', Rule::in(['all', 'solana', 'ethereum'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array{tab: string, source: string, search: string, status: string, result: string, exit_type: string, chain: string} */
    public function filters(): array
    {
        $validated = $this->validated();
        $isLegacyHistoryLink = ! array_key_exists('tab', $validated)
            && $this->hasAny(['status', 'result', 'exit_type', 'chain']);

        return [
            'tab' => $validated['tab'] ?? ($isLegacyHistoryLink ? 'history' : 'positions'),
            'source' => $validated['source'] ?? ($isLegacyHistoryLink ? 'legacy' : 'engine'),
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => $validated['status'] ?? 'all',
            'result' => $validated['result'] ?? 'all',
            'exit_type' => $validated['exit_type'] ?? 'all',
            'chain' => $validated['chain'] ?? 'all',
        ];
    }
}
