<?php

namespace App\Exceptions;

use RuntimeException;

class NoIpAllocationAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No dedicated or shared IP allocation is available on the resolved node.');
    }
}
