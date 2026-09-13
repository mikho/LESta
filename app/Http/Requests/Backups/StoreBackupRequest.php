<?php

namespace App\Http\Requests\Backups;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBackupRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'node' => ['required', 'string', Rule::exists('nodes', 'uuid')],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
