<?php

namespace App\Exceptions;

use RuntimeException;

class NoMetricsCapableNodeAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No non-suspended node with an active metrics.usage.v1 capability is available.');
    }
}
