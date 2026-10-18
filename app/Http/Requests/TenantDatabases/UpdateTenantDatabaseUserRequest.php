<?php

namespace App\Http\Requests\TenantDatabases;

use App\Enums\DatabaseUserAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantDatabaseUserRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'access' => ['required', Rule::enum(DatabaseUserAccess::class)],
        ];
    }
}
