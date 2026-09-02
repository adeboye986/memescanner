<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaperStrategyRequest extends FormRequest
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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'stop_loss_percent' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'protection_level_1_percent' => ['required', 'numeric', 'gt:0', 'lte:10000'],
            'protection_level_2_percent' => ['required', 'numeric', 'gt:protection_level_1_percent', 'lte:10000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'stop_loss_percent.gt' => 'Stop loss must be greater than 0%.',
            'stop_loss_percent.lt' => 'Stop loss must be less than 100%.',
            'protection_level_1_percent.gt' => 'Profit Protection Level 1 must be greater than 0%.',
            'protection_level_2_percent.gt' => 'Profit Protection Level 2 must be greater than Level 1.',
            'protection_level_1_percent.lte' => 'Profit Protection Level 1 is unreasonably large.',
            'protection_level_2_percent.lte' => 'Profit Protection Level 2 is unreasonably large.',
        ];
    }
}
