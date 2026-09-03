<?php

namespace App\Exceptions;

use RuntimeException;

class NoCronCapableNodeAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No non-suspended node with an active scheduler.account-cron.v1 capability is available.');
    }
}
