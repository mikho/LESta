<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    /**
     * Gates the platform-wide account list. Deliberately backed by accounts.view_as_support, the
     * same permission viewAsSupport below requires: browsing the list and opening one account
     * are the same "support visibility" concept, and no separate accounts.view_any permission
     * exists in Permission::CATALOG.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('accounts.view_as_support');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account);
    }

    /**
     * Gates creating a brand-new hosting account (App\Actions\Accounts\CreateAccount). Bare
     * class-string check, since no Account instance exists yet -- this replaces a previous,
     * never-actually-callable version of this method (it took an existing $account and checked
     * hasAccountRole($account, 'owner'), which cannot mean anything for creating a *new* account
     * and had zero real callers). Platform-admin-only for this first version: a reseller creating
     * their own managed accounts is a deliberately deferred, separate capability.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('accounts.create');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner') || $user->hasPermission('accounts.update');
    }

    public function suspend(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner') || $user->hasPermission('accounts.suspend');
    }

    public function unsuspend(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner') || $user->hasPermission('accounts.unsuspend');
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner') || $user->hasPermission('accounts.delete');
    }

    /**
     * The read-only, separately-audited support view (see
     * App\Actions\Support\ViewAccountAsSupport). Deliberately its own
     * permission, accounts.view_as_support, never accounts.view: a plain
     * view ability was never added to Permission::CATALOG at all, so it can
     * never be permission-granted here, keeping this ability the only path
     * for a provider admin to see a tenant's account (per the Foundations
     * decision log's explicit "distinct from and logged separately" design).
     */
    public function viewAsSupport(User $user, Account $account): bool
    {
        return $user->hasPermission('accounts.view_as_support');
    }
}
