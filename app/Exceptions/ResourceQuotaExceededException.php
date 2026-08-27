<?php

namespace App\Exceptions;

use RuntimeException;

class ResourceQuotaExceededException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The package has no limit row at all for this resource type: a stricter deny-by-default,
     * distinct from an explicit row with a null limit value (which means unlimited).
     */
    public static function notConfigured(string $resourceType): self
    {
        return new self("Cannot create [{$resourceType}]: the account's package has no configured limit for this resource.");
    }

    /**
     * The package has an explicit, finite limit row for this resource type, and the account is
     * already at or over it.
     */
    public static function limitReached(string $resourceType, int $limitValue): self
    {
        return new self("Cannot create [{$resourceType}]: the account's package limit of [{$limitValue}] has been reached.");
    }
}
