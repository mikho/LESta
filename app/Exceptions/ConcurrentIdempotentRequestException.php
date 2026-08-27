<?php

namespace App\Exceptions;

use RuntimeException;

class ConcurrentIdempotentRequestException extends RuntimeException
{
    public function __construct(string $scope, string $key)
    {
        parent::__construct("Idempotent request already processing for scope [{$scope}] key [{$key}].");
    }
}
