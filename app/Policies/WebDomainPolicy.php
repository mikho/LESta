<?php

namespace App\Policies;

class WebDomainPolicy extends AccountOwnedResourcePolicy
{
    protected function viewAnyPermission(): string
    {
        return 'web_domains.view_any';
    }
}
