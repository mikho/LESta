<?php

namespace App\Http\Requests\Domains;

use App\Models\WebDomainRedirect;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebDomainRedirectRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'source' => '/'.ltrim(trim((string) $this->input('source')), '/'),
            'target' => trim((string) $this->input('target')),
            'prefix' => $this->boolean('prefix'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => [
                'required',
                'string',
                'max:200',
                'regex:'.WebDomainRedirect::SOURCE_PATTERN,
                fn (string $attribute, mixed $value, \Closure $fail) => (str_contains((string) $value, '//') || str_contains((string) $value, '..'))
                    ? $fail(__('The path cannot contain // or ..'))
                    : null,
            ],
            'target' => [
                'required',
                'string',
                'max:500',
                'regex:'.WebDomainRedirect::TARGET_PATTERN,
                fn (string $attribute, mixed $value, \Closure $fail) => $value === $this->input('source')
                    ? $fail(__('A redirect cannot point at its own path.'))
                    : null,
            ],
            'status' => ['required', 'integer', Rule::in([301, 302])],
            'prefix' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.regex' => __('Use a plain path such as /old-page, with letters, numbers and . _ ~ % + - only.'),
            'target.regex' => __('Use a full address such as https://example.com/page, or a path such as /page, without spaces or quotes.'),
        ];
    }
}
