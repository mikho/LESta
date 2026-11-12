<?php

namespace App\Policies;

use App\Actions\Accounts\ResolvesSuspensionAuthority;
use App\Models\Account;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Database\Eloquent\Model;

/**
 * The one rule set for every resource an account owns: members read, owners write, and the
 * provider admin only ever sees the cross-account list or acts through an account-level cascade.
 * A concrete policy names the permission behind that list and nothing else.
 *
 * @phpstan-type AccountOwned WebDomain|DnsZone|MailDomain|TenantDatabase|CronJob
 */
abstract class AccountOwnedResourcePolicy
{
    /**
     * The permission that opens the provider admin's cross-account list, such as web_domains.view_any.
     */
    abstract protected function viewAnyPermission(): string;

    public function viewAny(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account);
    }

    /**
     * The provider admin's read-only, cross-account list of every customer's resources. Never
     * implies any per-resource ability: viewing a customer's resource, or acting on it, stays
     * member and owner scoped.
     */
    public function viewAnyAcrossAccounts(User $user): bool
    {
        return $user->hasPermission($this->viewAnyPermission());
    }

    /**
     * @param  AccountOwned  $resource
     */
    public function view(User $user, Model $resource): bool
    {
        return $user->hasAnyAccountMembership($resource->account);
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    /**
     * @param  AccountOwned  $resource
     */
    public function update(User $user, Model $resource): bool
    {
        return $user->hasAccountRole($resource->account, 'owner');
    }

    /**
     * Also recognizes accounts.suspend/unsuspend/delete, the exact permission that already
     * authorized the owning Account-level action (SuspendAccount/UnsuspendAccount/DeleteAccount)
     * these three abilities exist to be cascaded from: an admin permitted to suspend/unsuspend/
     * delete a whole account cannot be blocked from the same action's own unavoidable cascade
     * into that account's individual resources, or the top-level action would fail outright the
     * moment any account with real resources hit it. Not a new permission grant, just making the
     * one already given fully work.
     *
     * @param  AccountOwned  $resource
     */
    public function suspend(User $user, Model $resource): bool
    {
        return $user->hasAccountRole($resource->account, 'owner') || $user->hasPermission('accounts.suspend');
    }

    /**
     * @param  AccountOwned  $resource
     */
    public function unsuspend(User $user, Model $resource): bool
    {
        return ($user->hasAccountRole($resource->account, 'owner') || $user->hasPermission('accounts.unsuspend'))
            && app(ResolvesSuspensionAuthority::class)->mayLiftResource($user, $resource->account, $resource->suspension_source);
    }

    /**
     * @param  AccountOwned  $resource
     */
    public function delete(User $user, Model $resource): bool
    {
        return $user->hasAccountRole($resource->account, 'owner') || $user->hasPermission('accounts.delete');
    }
}
