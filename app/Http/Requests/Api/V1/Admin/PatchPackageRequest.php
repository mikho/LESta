<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Packages\UpdatePackageRequest;
use App\Models\Package;

/**
 * The panel's package rules for a PATCH: every field left out keeps its current value.
 */
class PatchPackageRequest extends UpdatePackageRequest
{
    protected function prepareForValidation(): void
    {
        /** @var Package $package */
        $package = $this->route('package');

        $this->merge(array_diff_key([
            'name' => $package->name,
            'description' => $package->description,
            'is_active' => $package->is_active,
        ], $this->all()));

        parent::prepareForValidation();
    }
}
