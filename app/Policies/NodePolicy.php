<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;

class NodePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Node $node): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Node $node): bool
    {
        return false;
    }

    public function delete(User $user, Node $node): bool
    {
        return false;
    }

    public function suspend(User $user, Node $node): bool
    {
        return false;
    }

    public function unsuspend(User $user, Node $node): bool
    {
        return false;
    }
}
