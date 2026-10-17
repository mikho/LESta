<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An API refusal with its own problem type (RFC 9457), for the cases a client may want to tell
 * apart from a plain status: a missing ability, a reused Idempotency-Key, a suspended account.
 */
class ApiProblemException extends HttpException
{
    /**
     * @param  string  $problem  The last part of the problem type, urn:lesta:problem:<problem>.
     * @param  array<string, string>  $headers
     */
    public function __construct(int $status, public readonly string $problem, string $detail, array $headers = [])
    {
        parent::__construct($status, $detail, null, $headers);
    }
}
