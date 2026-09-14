<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform-admin-only, checked directly against the real accounts.update permission -- see
 * AssignAccountToReseller's own identical shape.
 */
class UnassignAccountFromReseller
{
    public function handle(User $actor, Account $account): void
    {
        if (! $actor->hasPermission('accounts.update')) {
            throw new AuthorizationException;
        }

        if ($account->reseller_account_id === null) {
            return;
        }

        DB::transaction(function () use ($actor, $account): void {
            $account->update(['reseller_account_id' => null]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.reseller_unassigned',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
