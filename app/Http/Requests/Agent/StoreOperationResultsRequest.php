<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOperationResultsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'max:50'],
            'results.*.idempotency_key' => ['required', 'uuid'],
            'results.*.status' => ['required', 'string', 'in:applied,already_applied,rejected,failed,degraded'],
            'results.*.observed_state_version' => ['required', 'integer', 'min:0'],
            'results.*.observed_state_digest' => ['required', 'string'],
            'results.*.generation_id' => ['required', 'string'],
            'results.*.errors' => ['present', 'array'],
            'results.*.completed_at' => ['required', 'date'],
        ];
    }
}
