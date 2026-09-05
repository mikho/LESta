<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreHeartbeatRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'protocol_version' => ['required', 'string', 'max:32'],
            'agent_version' => ['required', 'string', 'max:64'],
            'ubuntu_release' => ['required', 'string', 'max:32'],
            'architecture' => ['required', 'string', 'max:32'],
            'timestamp' => ['required', 'date'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*.capability' => ['required_with:capabilities', 'string', 'max:128'],
            'capabilities.*.health_state' => ['required_with:capabilities', 'string', 'max:32'],
        ];
    }
}
