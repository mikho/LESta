<?php

namespace App\Http\Requests\Roles;

use App\Models\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * name excludes owner/member/provider_admin: those are the fixed, structural roles
     * (RoleSeeder) this feature never creates, updates, or deletes -- roles.name is also
     * DB-unique, but that alone would produce a confusing "already taken" error for a name that's
     * reserved rather than merely in use.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::notIn(['owner', 'member', 'provider_admin']),
                Rule::unique('roles', 'name'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permission::CATALOG)],
        ];
    }
}
