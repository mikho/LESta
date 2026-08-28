<?php

namespace App\Http\Requests\Dns;

use App\Models\DnsZone;
use App\Rules\ValidDomainName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDnsZoneRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => DnsZone::normalizeDomain((string) $this->input('domain')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', new ValidDomainName, Rule::unique('dns_zones', 'domain')],
            'ttl' => ['nullable', 'integer', 'min:60', 'max:604800'],
        ];
    }
}
