<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TokenScanIndexRequest extends FormRequest
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
            'tab' => ['sometimes', Rule::in(['new', 'history', 'providers'])],
            'status' => ['sometimes', Rule::in(['all', 'pending', 'running', 'completed', 'completed_with_warnings', 'failed'])],
            'scanner' => ['sometimes', Rule::in(['all', 'new-token', 'momentum'])],
            'network' => ['sometimes', Rule::in(['all', 'solana', 'ethereum'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array{status: string, scanner: string, network: string} */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'status' => $validated['status'] ?? 'all',
            'scanner' => $validated['scanner'] ?? 'all',
            'network' => $validated['network'] ?? 'all',
        ];
    }

    public function tab(): string
    {
        return $this->validated('tab', 'new');
    }
}
