<?php

namespace App\Http\Requests\IpRules;

use App\Models\AccountIpRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIpRuleRequest extends FormRequest
{
    /**
     * Store the canonical form of the address, so "203.0.113.9/24" and "203.0.113.0/24" are one rule.
     */
    protected function prepareForValidation(): void
    {
        $cidr = (string) $this->input('cidr');

        $this->merge(['cidr' => AccountIpRule::normalize($cidr) ?? trim($cidr)]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['allow', 'deny'])],
            'cidr' => [
                'required',
                'string',
                'max:49',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (AccountIpRule::normalize((string) $value) === null) {
                        $fail(__('Enter an IP address or a range such as 203.0.113.0/24.'));
                    }
                },
            ],
            'note' => ['nullable', 'string', 'max:100'],
        ];
    }
}
