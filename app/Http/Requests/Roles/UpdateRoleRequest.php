<?php

namespace App\Http\Requests\Roles;

use App\Models\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::notIn(['owner', 'member', 'provider_admin']),
                Rule::unique('roles', 'name')->ignore($this->route('role')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permission::CATALOG)],
        ];
    }
}
