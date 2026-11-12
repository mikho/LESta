<?php

namespace App\Policies;

class MailDomainPolicy extends AccountOwnedResourcePolicy
{
    protected function viewAnyPermission(): string
    {
        return 'mail_domains.view_any';
    }
}
