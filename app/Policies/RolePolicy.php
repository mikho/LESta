<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Role implements ProviderAdminManaged, but AuthorizationServiceProvider's
 * PERMISSION_BACKED_MODELS excludes it from the blanket Gate::before bypass, mirroring
 * PackagePolicy's own real Permission::CATALOG checks exactly: a platform role can be granted
 * roles.view_any without roles.delete, say. Every ability here (and every Actions\Roles\* class)
 * operates only on Role::scope === Platform rows -- the two fixed account-scope roles (owner,
 * member) are structural and never reachable through this policy's own real callers.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view_any');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.delete');
    }
}
