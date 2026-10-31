<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            // A new address is only taken with the current password, so a session left open (or
            // borrowed) cannot move the account to another address and reset it from there.
            'current_password' => [Rule::requiredIf(fn (): bool => $this->input('email') !== $this->user()->email), 'nullable', 'string', 'current_password'],
        ];
    }
}
