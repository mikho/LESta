<?php

namespace App\Exceptions;

use RuntimeException;

class NoBackupCapableNodeAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No non-suspended node with an active backup.encrypted-artifacts.v1 capability is available.');
    }
}
