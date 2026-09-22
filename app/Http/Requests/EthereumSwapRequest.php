<?php

namespace App\Http\Requests;

use App\Services\EthereumSwapInputRules;
use Illuminate\Foundation\Http\FormRequest;

class EthereumSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(EthereumSwapInputRules $inputs): array
    {
        return $inputs->rules();
    }
}
