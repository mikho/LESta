<?php

namespace App\Http\Requests\TenantDatabases;

use App\Concerns\ValidatesRemoteHosts;
use App\Enums\DatabaseUserAccess;
use App\Models\TenantDatabase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTenantDatabaseUserRequest extends FormRequest
{
    use ValidatesRemoteHosts;

    protected function prepareForValidation(): void
    {
        $this->normalizeRemoteHosts();
    }

    /**
     * The label becomes the user name's suffix, <database_name>_<label>: a
     * lowercase letter, then up to 15 letters or digits, never "ro" (the
     * statistics account's suffix), unique on the database.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var TenantDatabase $tenantDatabase */
        $tenantDatabase = $this->route('tenantDatabase');

        return [
            'label' => [
                'required',
                'string',
                'regex:/^[a-z][a-z0-9]{0,15}$/',
                'not_in:ro',
                Rule::unique('tenant_database_users', 'label')->where('tenant_database_id', $tenantDatabase->id),
            ],
            'access' => ['required', Rule::enum(DatabaseUserAccess::class)],
            ...$this->remoteHostRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var TenantDatabase $tenantDatabase */
            $tenantDatabase = $this->route('tenantDatabase');
            $this->requireNodeCertificateForRemoteHosts($validator, $tenantDatabase);
        });
    }
}
