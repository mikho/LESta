<?php

namespace App\Policies;

use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\User;

class MailAccountPolicy
{
    public function viewAny(User $user, MailDomain $mailDomain): bool
    {
        return $user->memberships()->where('account_id', $mailDomain->account_id)->exists();
    }

    public function view(User $user, MailAccount $mailAccount): bool
    {
        return $user->memberships()->where('account_id', $mailAccount->mailDomain->account_id)->exists();
    }

    public function create(User $user, MailDomain $mailDomain): bool
    {
        return $user->hasAccountRole($mailDomain->account, 'owner');
    }

    public function update(User $user, MailAccount $mailAccount): bool
    {
        return $user->hasAccountRole($mailAccount->mailDomain->account, 'owner');
    }

    public function suspend(User $user, MailAccount $mailAccount): bool
    {
        return $user->hasAccountRole($mailAccount->mailDomain->account, 'owner');
    }

    public function unsuspend(User $user, MailAccount $mailAccount): bool
    {
        return $user->hasAccountRole($mailAccount->mailDomain->account, 'owner');
    }

    public function delete(User $user, MailAccount $mailAccount): bool
    {
        return $user->hasAccountRole($mailAccount->mailDomain->account, 'owner');
    }
}
