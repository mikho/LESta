<?php

namespace App\Policies;

class TenantDatabasePolicy extends AccountOwnedResourcePolicy
{
    protected function viewAnyPermission(): string
    {
        return 'tenant_databases.view_any';
    }
}
