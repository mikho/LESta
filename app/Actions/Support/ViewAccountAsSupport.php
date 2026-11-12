<?php

namespace App\Actions\Support;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ViewAccountAsSupport
{
    public function handle(User $admin, Account $account): Account
    {
        Gate::forUser($admin)->authorize('viewAsSupport', $account);

        AuditEvent::record($account, 'account.viewed_as_support', $admin);

        return $account;
    }
}
