<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Models\Webhook;

/**
 * An account's webhooks are its owners' (a reseller account's owners for the accounts it
 * manages); platform-wide webhooks, which see every account, need webhooks.manage.
 */
class WebhookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->ownsAnyAccount() || $user->hasPermission('webhooks.manage');
    }

    public function create(User $user, ?Account $account = null): bool
    {
        return $account === null ? $user->hasPermission('webhooks.manage') : $user->hasAccountRole($account, 'owner');
    }

    public function manage(User $user, Webhook $webhook): bool
    {
        return $this->create($user, $webhook->account);
    }
}
