<?php

namespace App\Exceptions;

use RuntimeException;

class NoWebCapableNodeAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No non-suspended node with an active web.nginx.v1 or web.apache.v1 capability is available.');
    }
}
