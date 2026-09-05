<?php

namespace App\Http\Requests\Nodes;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNodeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('nodes', 'name')],
            'hostname' => ['required', 'string', 'max:255', Rule::unique('nodes', 'hostname')],
        ];
    }
}
