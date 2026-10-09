<?php

namespace App\Http\Requests\Domains;

use App\Enums\PhpVersion;
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

        $wafRules = $this->input('waf_excluded_rules', []);

        if (is_string($wafRules)) {
            $wafRules = preg_split('/[\s,]+/', trim($wafRules), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $hotlinkHosts = $this->input('hotlink_allowed_hosts', []);

        $this->merge([
            'hotlink_allowed_hosts' => is_array($hotlinkHosts)
                ? array_map(fn (mixed $host): string => WebDomain::normalizeDomain((string) $host), $hotlinkHosts)
                : [],
            'waf_excluded_rules' => is_array($wafRules) ? array_map(fn (mixed $id): mixed => is_string($id) && ctype_digit($id) ? (int) $id : $id, $wafRules) : [],
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
            'php_version' => ['nullable', 'string', Rule::in(array_column(PhpVersion::cases(), 'value'))],
            'ssl_mode' => ['nullable', 'string', Rule::in(['none', 'manual', 'lets_encrypt'])],
            'waf_mode' => ['nullable', 'string', Rule::in(['off', 'detect', 'block'])],
            'hotlink_protection' => ['nullable', 'boolean'],
            'hotlink_allowed_hosts' => ['array', 'max:50'],
            'hotlink_allowed_hosts.*' => ['string', new ValidDomainName],
            'waf_preset' => ['nullable', 'string', Rule::in(WebDomain::WAF_PRESETS)],
            'waf_excluded_rules' => ['array', 'max:100'],
            'waf_excluded_rules.*' => ['integer', 'between:1,999999999'],
            'aliases' => ['array'],
            'aliases.*' => ['string', new ValidDomainName, Rule::unique('web_domain_aliases', 'alias')->ignore($webDomain->id, 'web_domain_id')],
        ];
    }
}
