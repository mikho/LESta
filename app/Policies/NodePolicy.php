<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;

/**
 * Node implements ProviderAdminManaged, but AuthorizationServiceProvider's PERMISSION_BACKED_MODELS
 * excludes it from the blanket Gate::before bypass: every ability below is a real
 * Permission::CATALOG check instead, so a platform role can be granted, say, nodes.view without
 * nodes.delete. A non-admin (an account owner, member, or stranger) has no path to any Node
 * ability regardless, since hasPermission() only ever consults a platform-scope membership, and a
 * node is platform infrastructure with no account scoping at all.
 */
class NodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('nodes.view_any');
    }

    public function view(User $user, Node $node): bool
    {
        return $user->hasPermission('nodes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('nodes.create');
    }

    public function update(User $user, Node $node): bool
    {
        return $user->hasPermission('nodes.update');
    }

    public function delete(User $user, Node $node): bool
    {
        return $user->hasPermission('nodes.delete');
    }

    public function suspend(User $user, Node $node): bool
    {
        return $user->hasPermission('nodes.suspend');
    }

    public function unsuspend(User $user, Node $node): bool
    {
        return $user->hasPermission('nodes.unsuspend');
    }
}
