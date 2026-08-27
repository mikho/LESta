<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UnsuspendAccount
{
    public function handle(User $actor, Account $account): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $account);

        if (! $account->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $account): void {
            $account->unsuspend();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.unsuspended',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
