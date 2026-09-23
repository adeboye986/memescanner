<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReconsiderEthereumAccountingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review-ethereum-accounting') ?? false;
    }

    public function rules(): array
    {
        return $this->routeIs('ethereum-eligibility.reconsideration.execute')
            ? ['current_password' => ['required', 'string', 'current_password']]
            : ['review_id' => ['required', 'integer', 'min:1'], 'review_version' => ['required', 'integer', 'min:1']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) !== []) {
                $validator->errors()->add('reconsideration', 'Unexpected reconsideration fields.');
            }
        }];
    }
}
