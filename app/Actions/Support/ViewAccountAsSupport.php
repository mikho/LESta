<?php

namespace App\Actions\Support;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ViewAccountAsSupport
{
    public function handle(User $admin, Account $account): Account
    {
        Gate::forUser($admin)->authorize('viewAsSupport', $account);

        AuditEvent::create([
            'actor_type' => $admin->getMorphClass(),
            'actor_id' => $admin->getKey(),
            'auditable_type' => $account->getMorphClass(),
            'auditable_id' => $account->getKey(),
            'action' => 'account.viewed_as_support',
            'correlation_id' => (string) Str::uuid(),
        ]);

        return $account;
    }
}
