<?php

namespace App\Http\Requests;

use App\Services\EthereumEligibilityReviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEthereumEligibilityReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review-ethereum-accounting') ?? false;
    }

    public function rules(): array
    {
        $rules = ['submission_id' => ['required', 'uuid'], 'evidence_digest' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'expected_review_id' => ['present', 'nullable', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:0']];
        if ($this->routeIs('ethereum-eligibility.publish')) {
            return [...$rules, 'current_password' => ['required', 'string', 'current_password']];
        }
        if ($this->routeIs('ethereum-eligibility.collect')) {
            return $rules;
        }
        $rules += ['decision' => ['required', 'in:approved,rejected'], 'rationale' => ['required', 'string', 'max:255']];
        if ($this->input('decision') === 'approved') {
            $rules += ['source_reference' => ['required', 'string', 'max:2048'], 'source_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
                'historical_applicability' => ['required', 'string', 'max:4096'], 'reviewer_notes' => ['nullable', 'string', 'max:4096'],
                'conclusions' => ['required', 'array:'.implode(',', array_keys(EthereumEligibilityReviewService::WORKFLOW_CONCLUSIONS))]];
            foreach (EthereumEligibilityReviewService::WORKFLOW_CONCLUSIONS as $key => $label) {
                $rules['conclusions.'.$key] = ['required', 'accepted'];
            }
        } else {
            // The same form may contain unused source fields; none become rejection evidence.
            $rules += ['source_reference' => ['nullable', 'string', 'max:2048'], 'source_sha256' => ['nullable', 'string', 'max:64'],
                'historical_applicability' => ['nullable', 'string', 'max:4096'], 'reviewer_notes' => ['nullable', 'string', 'max:4096'],
                'conclusions' => ['sometimes', 'array']];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_unique(array_map(fn ($key) => explode('.', $key)[0], array_keys($this->rules())));
            if (array_diff(array_keys($this->all()), [...$allowed, '_token']) !== []) {
                $validator->errors()->add('review', 'Unexpected review fields. Trusted evidence and reviewer identity cannot be submitted.');
            }
        }];
    }
}
