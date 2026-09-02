<?php

namespace App\Http\Requests\TenantDatabases;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantDatabaseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->resolveAccount();

        return [
            'label' => [
                'required',
                'string',
                'regex:/^[a-z][a-z0-9_]{0,32}$/',
                Rule::unique('tenant_databases', 'label')->where('account_id', $account->id),
            ],
        ];
    }

    /**
     * Resolve the acting account: the user's first account-scoped membership. Deliberately
     * duplicated from TenantDatabaseController's own resolveAccount() rather than shared,
     * matching this app's existing per-controller-request convention (no shared account-
     * resolution helper exists for DnsZone's own Store/Update requests either).
     */
    private function resolveAccount(): Account
    {
        /** @var Account */
        return $this->user()->memberships()->whereNotNull('account_id')->with('account')->firstOrFail()->account;
    }
}
