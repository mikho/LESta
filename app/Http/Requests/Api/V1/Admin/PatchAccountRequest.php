<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The panel's account fields for a PATCH: only those sent change, the package named by its API id.
 */
class PatchAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'package' => ['sometimes', 'required', 'string', Rule::exists('packages', 'uuid')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function accountData(): array
    {
        $data = $this->validated();

        if (isset($data['package'])) {
            $data['package_id'] = Package::query()->where('uuid', $data['package'])->value('id');
            unset($data['package']);
        }

        return $data;
    }
}
