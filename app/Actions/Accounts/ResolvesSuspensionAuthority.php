<?php

namespace App\Actions\Accounts;

use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\User;

/**
 * At which level a user suspends things on an account, and whether they may lift a suspension:
 * the provider (the accounts.suspend and accounts.unsuspend permissions), the reseller managing
 * the account (an owner of its reseller account, not of the account itself), or the account's own
 * owner. A suspension is lifted only at its own level or above (SuspensionSource::rank()).
 */
class ResolvesSuspensionAuthority
{
    public function suspendingLevel(User $actor, Account $account): SuspensionSource
    {
        if ($actor->hasPermission('accounts.suspend')) {
            return SuspensionSource::Provider;
        }

        return $this->isResellerOf($actor, $account) ? SuspensionSource::Reseller : SuspensionSource::Manual;
    }

    public function mayLift(User $actor, Account $account, ?SuspensionSource $source): bool
    {
        if ($source === null) {
            return true;
        }

        $level = match (true) {
            $actor->hasPermission('accounts.unsuspend') => SuspensionSource::Provider,
            $this->isResellerOf($actor, $account) => SuspensionSource::Reseller,
            default => SuspensionSource::Manual,
        };

        return $level->rank() >= $source->rank();
    }

    /**
     * Whether a resource of $account, suspended by $source, may be lifted by $actor: its own
     * suspension (a cascaded one counts as the account's), and the account's while it lasts.
     */
    public function mayLiftResource(User $actor, Account $account, ?SuspensionSource $source): bool
    {
        if ($source === SuspensionSource::Cascade) {
            $source = $account->suspension_source;
        }

        return $this->mayLift($actor, $account, $source)
            && (! $account->isSuspended() || $this->mayLift($actor, $account, $account->suspension_source));
    }

    /**
     * An owner of the account's reseller account who is not an owner of the account itself.
     */
    private function isResellerOf(User $actor, Account $account): bool
    {
        if ($account->reseller_account_id === null || $account->resellerAccount === null) {
            return false;
        }

        $directOwner = $actor->memberships()->where('account_id', $account->id)
            ->whereHas('role', fn ($query) => $query->where('name', 'owner'))->exists();

        return ! $directOwner && $actor->hasAccountRole($account->resellerAccount, 'owner');
    }
}
