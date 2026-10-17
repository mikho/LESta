<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every API error as an RFC 9457 problem document (application/problem+json): type, title,
 * status and detail, and for invalid input the field errors under "errors" in Laravel's usual
 * shape. A type is urn:lesta:problem:<name> when the API has a name for the problem, otherwise
 * about:blank with the status's own title. Nothing internal (model classes, exception messages
 * of a server error) reaches the client.
 */
class RendersApiProblem
{
    public const string PREFIX = 'urn:lesta:problem:';

    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::problem(422, 'validation', __('The given data was invalid.'), ['errors' => $e->errors()]),
            $e instanceof ApiProblemException => self::problem($e->getStatusCode(), $e->problem, $e->getMessage(), headers: $e->getHeaders()),
            $e instanceof ResourceQuotaExceededException, $e instanceof PackageQuotaExceededException => self::problem(422, 'quota-exceeded', $e->getMessage()),
            self::isCapacity($e) => self::problem(503, 'no-capacity', __('No server can take this right now. Try again later or contact your provider.')),
            $e instanceof HttpExceptionInterface => self::problem($e->getStatusCode(), null, self::httpDetail($e), headers: $e->getHeaders()),
            default => self::problem(500, null, __('Something went wrong on the server.')),
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function problem(int $status, ?string $problem, string $detail, array $extra = [], array $headers = []): JsonResponse
    {
        $title = Response::$statusTexts[$status] ?? 'Error';

        return new JsonResponse([
            'type' => $problem === null ? 'about:blank' : self::PREFIX.$problem,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            ...$extra,
        ], $status, [...$headers, 'Content-Type' => 'application/problem+json']);
    }

    private static function isCapacity(Throwable $e): bool
    {
        return $e instanceof NoWebCapableNodeAvailableException
            || $e instanceof NoPhpCapableNodeAvailableException
            || $e instanceof NoIpAllocationAvailableException
            || $e instanceof NoDnsCapableNodeAvailableException
            || $e instanceof NoMailCapableNodeAvailableException
            || $e instanceof NoTenantDatabaseCapableNodeAvailableException
            || $e instanceof NoCronCapableNodeAvailableException
            || $e instanceof NoBackupCapableNodeAvailableException
            || $e instanceof NoMetricsCapableNodeAvailableException;
    }

    /**
     * The exception's own message where the app wrote one for the client (abort(403, '...')), a
     * plain sentence otherwise: a 404 from a missing model names the model class, which stays out.
     */
    private static function httpDetail(HttpExceptionInterface $e): string
    {
        return match ($e->getStatusCode()) {
            404 => __('The resource does not exist.'),
            405 => __('This method is not allowed here.'),
            429 => __('Too many requests. Wait and try again.'),
            default => $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$e->getStatusCode()] ?? 'Error'),
        };
    }
}
