<?php

namespace App\Actions\Packages;

use App\Exceptions\PackageQuotaExceededException;
use App\Models\Account;
use App\Models\Package;
use App\Models\PackageLimit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdatePackageQuota
{
    public function handle(User $actor, Package $package, string $resourceType, ?int $limitValue): PackageLimit
    {
        Gate::forUser($actor)->authorize('update', $package);

        return DB::transaction(function () use ($package, $resourceType, $limitValue): PackageLimit {
            if ($resourceType === 'memberships' && $limitValue !== null) {
                $violates = Account::query()
                    ->where('package_id', $package->id)
                    ->whereHas('memberships', operator: '>', count: $limitValue)
                    ->exists();

                if ($violates) {
                    throw new PackageQuotaExceededException($resourceType, $limitValue);
                }
            }

            return PackageLimit::query()->updateOrCreate(
                ['package_id' => $package->id, 'resource_type' => $resourceType],
                ['limit_value' => $limitValue],
            );
        });
    }
}
