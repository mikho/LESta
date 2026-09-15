<?php

namespace App\Http\Requests\Mail;

use App\Models\MailDomain;
use App\Rules\ValidDomainName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMailDomainRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => MailDomain::normalizeDomain((string) $this->input('domain')),
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
            'domain' => ['required', 'string', new ValidDomainName, Rule::unique('mail_domains', 'domain')],
            'antivirus_enabled' => ['nullable', 'boolean'],
            'antispam_enabled' => ['nullable', 'boolean'],
            'dkim_enabled' => ['nullable', 'boolean'],
            'catchall_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
