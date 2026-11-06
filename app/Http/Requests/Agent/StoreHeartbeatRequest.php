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
            'services' => ['sometimes', 'nullable', 'array', 'max:64'],
            'services.*.name' => ['required_with:services', 'string', 'max:128', 'regex:/^[A-Za-z0-9@._-]+$/'],
            'services.*.state' => ['required_with:services', 'string', 'max:32'],
            'php_versions' => ['sometimes', 'nullable', 'array', 'max:16'],
            'php_versions.*' => ['string', 'regex:/^[0-9]+\.[0-9]+$/'],
            'resources' => ['sometimes', 'nullable', 'array'],
            'resources.load_1' => ['required_with:resources', 'numeric', 'min:0', 'max:100000'],
            'resources.load_5' => ['required_with:resources', 'numeric', 'min:0', 'max:100000'],
            'resources.load_15' => ['required_with:resources', 'numeric', 'min:0', 'max:100000'],
            'resources.cpu_count' => ['required_with:resources', 'integer', 'min:1', 'max:4096'],
            'resources.processes' => ['required_with:resources', 'integer', 'min:0', 'max:100000000'],
            'resources.memory_total_kb' => ['required_with:resources', 'integer', 'min:0'],
            'resources.memory_available_kb' => ['required_with:resources', 'integer', 'min:0'],
            'resources.swap_total_kb' => ['required_with:resources', 'integer', 'min:0'],
            'resources.swap_free_kb' => ['required_with:resources', 'integer', 'min:0'],
            'resources.uptime_seconds' => ['required_with:resources', 'integer', 'min:0'],
        ];
    }
}
