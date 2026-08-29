<?php

namespace App\Http\Requests\Domains;

use App\Models\WebDomain;
use App\Rules\ValidDomainName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebDomainRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $aliases = $this->input('aliases', []);

        $this->merge([
            'domain' => WebDomain::normalizeDomain((string) $this->input('domain')),
            'aliases' => is_array($aliases)
                ? array_map(fn (mixed $alias): string => WebDomain::normalizeDomain((string) $alias), $aliases)
                : [],
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var WebDomain $webDomain */
        $webDomain = $this->route('webDomain');

        return [
            'domain' => ['required', 'string', new ValidDomainName, Rule::unique('web_domains', 'domain')->ignore($webDomain->id)],
            'web_template' => ['nullable', 'string', 'max:255'],
            'web_server' => ['nullable', 'string', Rule::in(['nginx', 'apache'])],
            'ssl_mode' => ['nullable', 'string', Rule::in(['none', 'manual', 'lets_encrypt'])],
            'aliases' => ['array'],
            'aliases.*' => ['string', new ValidDomainName, Rule::unique('web_domain_aliases', 'alias')->ignore($webDomain->id, 'web_domain_id')],
        ];
    }
}
