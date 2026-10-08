<?php

namespace App\Http\Requests\Nodes;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNodeToolsDomainRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ssl_mode' => ['required', Rule::in(['lets_encrypt', 'manual'])],
        ];
    }
}
