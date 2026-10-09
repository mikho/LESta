<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\User;

/**
 * Self-service backups belong to the account: any member can see them, only an owner can create,
 * restore or delete one. (The provider-admin-only, whole-node Backup has its own policy.)
 */
class AccountBackupPolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account);
    }

    public function view(User $user, AccountBackup $backup): bool
    {
        return $user->hasAnyAccountMembership($backup->account);
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function restore(User $user, AccountBackup $backup): bool
    {
        return $user->hasAccountRole($backup->account, 'owner');
    }

    public function delete(User $user, AccountBackup $backup): bool
    {
        return $user->hasAccountRole($backup->account, 'owner');
    }
}
