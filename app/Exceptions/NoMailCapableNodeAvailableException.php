<?php

namespace App\Exceptions;

use RuntimeException;

class NoMailCapableNodeAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No non-suspended node with an active mail.smtp-imap.v1 capability is available.');
    }
}
