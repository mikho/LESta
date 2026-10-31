<?php

namespace App\Http\Requests\Agent;

use App\Rules\ValidDomainName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // The node's name in the panel, which also decides the tools host and the names no
            // customer may claim: a real domain name, and no other node's.
            'hostname' => ['sometimes', 'string', 'max:253', new ValidDomainName, Rule::unique('nodes', 'hostname')->where(fn ($query) => $query->where('uuid', '!=', (string) $this->input('node_uuid')))],
            'agent_version' => ['required', 'string', 'max:64'],
            'protocol_version' => ['required', 'string', 'max:32'],
        ];
    }
}
