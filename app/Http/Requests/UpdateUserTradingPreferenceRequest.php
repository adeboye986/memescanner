<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserTradingPreferenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null
            && ($this->input('entry_mode') !== 'auto' || $this->user()->hasVerifiedEmail());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'execution_mode' => ['required', 'in:paper'],
            'entry_mode' => ['required', 'in:signal,confirm,auto'],
        ];
    }
}
