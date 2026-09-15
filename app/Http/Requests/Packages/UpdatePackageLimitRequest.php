<?php

namespace App\Http\Requests\Packages;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePackageLimitRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. A null limit_value means unlimited;
     * the resource type itself is a route parameter, validated against the real, actively-
     * enforced set in PackageController::RESOURCE_TYPES before this request class is ever
     * resolved (a bare abort_unless, not a FormRequest rule, since it gates route dispatch
     * itself rather than the request body).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'limit_value' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
