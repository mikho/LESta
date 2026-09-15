<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'package_id' => ['required', Rule::exists('packages', 'id')->where('is_active', true)],
            // Always required, even when owner_email turns out to belong to an existing user --
            // App\Actions\Accounts\CreateAccount ignores owner_name in that case. Keeping it
            // unconditionally required avoids conditional-required validation complexity for a
            // lookup the form itself cannot resolve ahead of submission.
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
        ];
    }
}
