<?php

namespace App\Actions\Users;

use App\Models\Membership;
use App\Models\User;

/**
 * Why a user cannot delete their own login yet. Deleting it removes its memberships, so the
 * last owner of an account (or the last full administrator) would leave something nobody
 * can manage or delete.
 */
class ListUserDeletionBlockers
{
    /**
     * @return list<string>
     */
    public function handle(User $user): array
    {
        $blockers = [];

        $ownedAccounts = $user->memberships()
            ->whereNotNull('account_id')
            ->whereHas('role', fn ($query) => $query->where('name', 'owner'))
            ->with('account')
            ->get();

        foreach ($ownedAccounts as $membership) {
            $otherOwners = Membership::query()
                ->where('account_id', $membership->account_id)
                ->where('user_id', '!=', $user->getKey())
                ->whereHas('role', fn ($query) => $query->where('name', 'owner'))
                ->exists();

            if (! $otherOwners) {
                $blockers[] = __('You are the only owner of :account. Invite another owner, or ask an administrator to delete the account first.', [
                    'account' => $membership->account->name,
                ]);
            }
        }

        if ($user->isProviderAdmin()) {
            $otherAdmins = Membership::query()
                ->whereNull('account_id')
                ->where('user_id', '!=', $user->getKey())
                ->whereHas('role', fn ($query) => $query->where('name', 'provider_admin'))
                ->exists();

            if (! $otherAdmins) {
                $blockers[] = __('You are the only full administrator. Make someone else an administrator first.');
            }
        }

        return $blockers;
    }
}
