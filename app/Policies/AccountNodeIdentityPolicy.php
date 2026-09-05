<?php

namespace App\Policies;

use App\Models\AccountNodeIdentity;
use App\Models\User;

/**
 * AccountNodeIdentity implements ProviderAdminManaged, so AuthorizationServiceProvider's global
 * Gate::before grants a provider admin every ability here before any of these methods ever run,
 * matching NodePolicy's own established pattern exactly. Every method below returning false is
 * therefore complete, not a stub: a tenant account never sees or controls its own OS-level
 * identity, so a non-admin must never gain any ability here at all.
 */
class AccountNodeIdentityPolicy
{
    /**
     * Never true for a non-admin; a provider admin bypasses this via Gate::before.
     */
    public function delete(User $user, AccountNodeIdentity $identity): bool
    {
        return false;
    }
}
