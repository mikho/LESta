<?php

namespace App\Exceptions;

use RuntimeException;

class NoPhpCapableNodeAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The resolved node has no active web.php-fpm.v1 capability, but a php_version was requested.');
    }
}
