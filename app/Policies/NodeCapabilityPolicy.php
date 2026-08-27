<?php

namespace App\Policies;

use App\Models\NodeCapability;
use App\Models\User;

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

    public function create(User $user): bool
    {
        return false;
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
        return false;
    }

    public function unsuspend(User $user, NodeCapability $nodeCapability): bool
    {
        return false;
    }
}
