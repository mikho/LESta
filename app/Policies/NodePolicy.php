<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;

/**
 * Node implements ProviderAdminManaged, so AuthorizationServiceProvider's global Gate::before
 * grants a provider admin every ability here before any of these methods ever run. Every method
 * below returning false is therefore complete, not a stub: a non-admin (an account owner,
 * member, or stranger) must never gain any Node ability, since a node is platform infrastructure
 * with no account scoping at all.
 */
class NodePolicy
{
    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function view(User $user, Node $node): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function update(User $user, Node $node): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function delete(User $user, Node $node): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function suspend(User $user, Node $node): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function unsuspend(User $user, Node $node): bool
    {
        return false;
    }
}
