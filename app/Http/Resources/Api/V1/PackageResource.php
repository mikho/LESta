<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Package;
use App\Models\PackageLimit;
use Illuminate\Http\Request;

/**
 * A hosting package and its limits, one per resource type: configured false means none may be
 * created (EnforcesPackageQuota refuses a resource with no limit set), limit null means unlimited.
 *
 * @mixin Package
 */
class PackageResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $limits = $this->limits->keyBy('resource_type');

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'limits' => collect(PackageLimit::RESOURCE_TYPES)->mapWithKeys(fn (string $type): array => [$type => [
                'configured' => $limits->has($type),
                'limit' => $limits->get($type)?->limit_value,
            ]])->all(),
            'accounts_count' => $this->whenCounted('accounts'),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
