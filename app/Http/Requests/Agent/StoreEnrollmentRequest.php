<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEnrollmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'node_uuid' => ['required', 'uuid'],
            'enrollment_token' => ['required', 'string'],
            'hostname' => ['sometimes', 'string', 'max:255'],
            'agent_version' => ['required', 'string', 'max:64'],
            'protocol_version' => ['required', 'string', 'max:32'],
        ];
    }
}
