<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Accounts\StoreAccountRequest as PanelStoreAccountRequest;
use App\Models\Package;
use Illuminate\Validation\Rule;

/**
 * The panel's rules for a new account, with the package named by its API id (its uuid).
 */
class StoreAccountRequest extends PanelStoreAccountRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['package_id']);

        return [...$rules, 'package' => ['required', 'string', Rule::exists('packages', 'uuid')->where('is_active', true)]];
    }

    /**
     * @return array<string, mixed>
     */
    public function accountData(): array
    {
        $data = $this->validated();
        $data['package_id'] = Package::query()->where('uuid', $data['package'])->value('id');
        unset($data['package']);

        return $data;
    }
}
