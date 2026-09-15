<?php

namespace App\Http\Requests\Mail;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * No `domain` field: UpdateMailDomain::handle() never accepts one (a mail domain's own domain
 * name is fixed at creation, matching this project's established precedent for provisioned
 * resources whose identity is embedded in real rendered node config).
 */
class UpdateMailDomainRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'antivirus_enabled' => ['nullable', 'boolean'],
            'antispam_enabled' => ['nullable', 'boolean'],
            'dkim_enabled' => ['nullable', 'boolean'],
            'catchall_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
