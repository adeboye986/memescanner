<?php

namespace App\Http\Requests;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\TradeOpportunityStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpportunityIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(['active', 'ignored', 'history'])],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(TradeOpportunityStatus::class)],
            'chain' => ['nullable', Rule::enum(Chain::class)],
            'entry_mode' => ['nullable', Rule::enum(EntryMode::class)],
            'score_band' => ['nullable', Rule::in(['strong', 'moderate', 'elevated'])],
        ];
    }
}
