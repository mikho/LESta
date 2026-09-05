<?php

namespace App\Http\Requests\Nodes;

use App\Enums\NodeCapabilityType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNodeCapabilityRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'capability' => ['required', 'string', Rule::in(array_column(NodeCapabilityType::cases(), 'value'))],
        ];
    }
}
