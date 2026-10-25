<?php

namespace App\Http\Requests\TenantDatabases;

use App\Concerns\ValidatesRemoteHosts;
use App\Enums\DatabaseUserAccess;
use App\Models\TenantDatabase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The access level, the remote hosts, or both; a field left out keeps its value.
 */
class UpdateTenantDatabaseUserRequest extends FormRequest
{
    use ValidatesRemoteHosts;

    protected function prepareForValidation(): void
    {
        $this->normalizeRemoteHosts();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'access' => ['sometimes', 'required', Rule::enum(DatabaseUserAccess::class)],
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
