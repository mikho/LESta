<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;

/**
 * Package implements ProviderAdminManaged, but AuthorizationServiceProvider's
 * PERMISSION_BACKED_MODELS excludes it from the blanket Gate::before bypass:
 * every ability below is a real Permission::CATALOG check instead, so a
 * platform role can be granted, say, packages.view without packages.delete.
 * A non-admin (an account owner, member, or stranger) has no path to any
 * Package ability regardless, since hasPermission() only ever consults a
 * platform-scope membership.
 */
class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('packages.view_any');
    }

    public function view(User $user, Package $package): bool
    {
        return $user->hasPermission('packages.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('packages.create');
    }

    public function update(User $user, Package $package): bool
    {
        return $user->hasPermission('packages.update');
    }

    public function delete(User $user, Package $package): bool
    {
        return $user->hasPermission('packages.delete');
    }
}
