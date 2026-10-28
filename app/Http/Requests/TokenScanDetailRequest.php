<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TokenScanDetailRequest extends FormRequest
{
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
            'candidate_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function candidatePage(): int
    {
        return (int) $this->validated('candidate_page', 1);
    }
}
