<?php

namespace App\Exceptions;

use RuntimeException;

class NoTenantDatabaseCapableNodeAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No non-suspended node with an active database.tenant.v1 capability is available.');
    }
}
