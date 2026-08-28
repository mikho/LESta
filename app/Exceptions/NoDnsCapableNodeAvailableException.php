<?php

namespace App\Exceptions;

use RuntimeException;

class NoDnsCapableNodeAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No non-suspended node with an active dns.bind9.v1 capability is available.');
    }
}
