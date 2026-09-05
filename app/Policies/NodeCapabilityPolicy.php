<?php

namespace App\Policies;

use App\Models\NodeCapability;
use App\Models\User;

/**
 * NodeCapability implements ProviderAdminManaged, so AuthorizationServiceProvider's global
 * Gate::before grants a provider admin every ability here before any of these methods ever run.
 * Every method below returning false is therefore complete, not a stub: a non-admin must never
 * gain any NodeCapability ability, since a node's capabilities are platform infrastructure with
 * no account scoping at all.
 */
class NodeCapabilityPolicy
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
    public function view(User $user, NodeCapability $nodeCapability): bool
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
    public function update(User $user, NodeCapability $nodeCapability): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function delete(User $user, NodeCapability $nodeCapability): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function suspend(User $user, NodeCapability $nodeCapability): bool
    {
        return false;
    }

    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function unsuspend(User $user, NodeCapability $nodeCapability): bool
    {
        return false;
    }
}
