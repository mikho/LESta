<?php

namespace App\Exceptions;

use RuntimeException;

class PackageQuotaExceededException extends RuntimeException
{
    public function __construct(string $resourceType, int $limitValue)
    {
        parent::__construct(
            "Cannot set [{$resourceType}] limit to [{$limitValue}]: at least one subscribed account already exceeds it."
        );
    }
}
