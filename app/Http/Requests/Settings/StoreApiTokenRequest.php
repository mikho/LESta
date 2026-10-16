<?php

namespace App\Http\Requests\Settings;

use App\Models\ApiToken;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiToken::ABILITIES)],
            'account_id' => ['nullable', 'integer'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:'.ApiToken::MAX_DAYS],
        ];
    }
}
