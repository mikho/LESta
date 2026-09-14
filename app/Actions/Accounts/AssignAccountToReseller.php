<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Assigns $account to be managed by $resellerAccount: platform-admin-only, checked directly
 * against the real accounts.update permission (mirroring GrantNodeAdmin's own shape). Enforces
 * one level of nesting only, in both directions -- $resellerAccount must not itself already be
 * reseller-managed, and $account must not itself already manage other accounts -- otherwise a
 * chain could form that User::hasAccountRole()'s own one-hop-per-call recursion would silently
 * walk through, defeating the "a reseller manages ordinary accounts, never another reseller's
 * accounts" boundary this feature was scoped to.
 */
class AssignAccountToReseller
{
    public function handle(User $actor, Account $account, Account $resellerAccount): void
    {
        if (! $actor->hasPermission('accounts.update')) {
            throw new AuthorizationException;
        }

        if ($account->is($resellerAccount)) {
            throw ValidationException::withMessages([
                'reseller_account_id' => 'An account cannot be its own reseller.',
            ]);
        }

        if ($resellerAccount->reseller_account_id !== null) {
            throw ValidationException::withMessages([
                'reseller_account_id' => 'A reseller-managed account cannot itself act as a reseller.',
            ]);
        }

        if ($account->managedAccounts()->exists()) {
            throw ValidationException::withMessages([
                'reseller_account_id' => 'An account that already manages other accounts cannot itself be reseller-managed.',
            ]);
        }

        DB::transaction(function () use ($actor, $account, $resellerAccount): void {
            $account->update(['reseller_account_id' => $resellerAccount->id]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.reseller_assigned',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
