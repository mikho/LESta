<?php

namespace App\Policies;

use App\Models\AccountNodeIdentity;
use App\Models\User;

/**
 * AccountNodeIdentity implements ProviderAdminManaged, so AuthorizationServiceProvider's global
 * Gate::before grants a provider admin every ability here before any of these methods ever run,
 * matching NodePolicy's own established pattern exactly. delete() returning false is complete,
 * not a stub: a tenant account never sees or controls this resource's own lifecycle, so a
 * non-admin must never gain that ability at all.
 *
 * updateSshCredential() is the one deliberate exception (see AccountNodeIdentity's own doc
 * comment): the SSH key is the tenant's own SFTP login credential, not OS-lifecycle management,
 * so the account's owner can set it directly, mirroring MailAccountPolicy's own owner-can-rotate-
 * their-own-credential precedent.
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

    public function updateSshCredential(User $user, AccountNodeIdentity $identity): bool
    {
        return $user->hasAccountRole($identity->account, 'owner');
    }
}
