<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\User;

/**
 * NodeCapability implements ProviderAdminManaged; AuthorizationServiceProvider's
 * PERMISSION_BACKED_MODELS excludes it from the blanket Gate::before bypass, mirroring the exact
 * same real-permission migration Phase 28 already did for Package/Node/Backup. Only create/
 * suspend/unsuspend are ever actually checked anywhere in this app today (NodeCapabilityController's
 * own three routes); viewAny/view/update/delete stay real, deliberate "no non-admin path" stubs
 * exactly as before this migration -- nothing currently calls them, and a future caller wiring one
 * up should have to make its own real decision, not silently inherit a blanket bypass.
 *
 * create() takes an explicit Node argument (Gate::authorize('create', [NodeCapability::class,
 * $node]), not the bare class) since adding a capability is meaningless without knowing which
 * node it's being added to -- the one place a class-only "create" check would have made a
 * node-scoped grant impossible to express at all.
 */
class NodeCapabilityPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, NodeCapability $nodeCapability): bool
    {
        return false;
    }

    public function create(User $user, Node $node): bool
    {
        return $user->hasPermission('nodes.update') || $user->hasNodeAdminGrant($node);
    }

    public function update(User $user, NodeCapability $nodeCapability): bool
    {
        return false;
    }

    public function delete(User $user, NodeCapability $nodeCapability): bool
    {
        return false;
    }

    public function suspend(User $user, NodeCapability $nodeCapability): bool
    {
        return $user->hasPermission('nodes.update') || $user->hasNodeAdminGrant($nodeCapability->node);
    }

    public function unsuspend(User $user, NodeCapability $nodeCapability): bool
    {
        return $user->hasPermission('nodes.update') || $user->hasNodeAdminGrant($nodeCapability->node);
    }

    public function updateStatus(User $user, NodeCapability $nodeCapability): bool
    {
        return $user->hasPermission('nodes.update') || $user->hasNodeAdminGrant($nodeCapability->node);
    }
}
