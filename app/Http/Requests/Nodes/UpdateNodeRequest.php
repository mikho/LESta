<?php

namespace App\Http\Requests\Nodes;

use App\Models\Node;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNodeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Node $node */
        $node = $this->route('node');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('nodes', 'name')->ignore($node->id)],
            'hostname' => ['required', 'string', 'max:255', Rule::unique('nodes', 'hostname')->ignore($node->id)],
        ];
    }
}
