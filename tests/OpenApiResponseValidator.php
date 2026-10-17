<?php

namespace Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks an API response against docs/api/openapi.yaml: the route's operation must describe the
 * response's status and content type, and the body must match that schema (JSON Schema 2020-12,
 * the dialect of OpenAPI 3.1). TestCase runs it on every API response a test receives, so the
 * description cannot drift from what the API really answers.
 */
class OpenApiResponseValidator
{
    private const string DOCUMENT_ID = 'https://lesta.invalid/openapi.json';

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    private static ?Validator $validator = null;

    /**
     * Null when the response matches, or when the route is not part of the description (a route a
     * test registered for itself); otherwise what is wrong.
     */
    public static function check(Request $request, Response $response): ?string
    {
        $route = $request->route();

        if (! $route instanceof Route || ! str_starts_with($route->uri(), 'api/v1/')) {
            return null;
        }

        $method = strtolower($request->getMethod());
        $path = self::specPath($route->uri());

        if ($path === null || ! isset(self::spec()['paths'][$path][$method])) {
            return null;
        }

        $status = (string) $response->getStatusCode();
        $where = strtoupper($method).' '.$path.' '.$status;
        $responses = self::spec()['paths'][$path][$method]['responses'];

        if (! isset($responses[$status])) {
            return "{$where}: this status is not described.";
        }

        $described = self::resolve($responses[$status]);
        $contentType = strtok((string) $response->headers->get('Content-Type'), ';');

        if (! isset($described['content'])) {
            return null;
        }

        if (! isset($described['content'][$contentType])) {
            return "{$where}: content type {$contentType} is not described (".implode(', ', array_keys($described['content'])).').';
        }

        $pointer = isset($responses[$status]['$ref'])
            ? substr($responses[$status]['$ref'], 1).'/content/'.self::escape($contentType).'/schema'
            : '/paths/'.self::escape($path).'/'.$method.'/responses/'.$status.'/content/'.self::escape($contentType).'/schema';

        // A JSON pointer in a URI fragment is percent-encoded (RFC 6901, section 6): paths hold braces.
        $fragment = implode('/', array_map(rawurlencode(...), explode('/', $pointer)));
        $result = self::validator()->validate(json_decode((string) $response->getContent()), self::DOCUMENT_ID.'#'.$fragment);

        if ($result->isValid()) {
            return null;
        }

        return "{$where}: the body does not match the schema: ".json_encode((new ErrorFormatter)->format($result->error(), false), JSON_UNESCAPED_SLASHES);
    }

    /**
     * The description's path for a route uri, the parameter names aside.
     */
    private static function specPath(string $uri): ?string
    {
        $normalize = fn (string $path): string => preg_replace('/\{[^}]+\}/', '{}', $path);
        $wanted = $normalize(substr($uri, strlen('api/v1')));

        foreach (array_keys(self::spec()['paths']) as $path) {
            if ($normalize($path) === $wanted) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function resolve(array $item): array
    {
        if (! isset($item['$ref'])) {
            return $item;
        }

        $node = self::spec();

        foreach (explode('/', substr($item['$ref'], 2)) as $part) {
            $node = $node[str_replace(['~1', '~0'], ['/', '~'], $part)];
        }

        return $node;
    }

    private static function escape(string $part): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $part);
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return self::$spec ??= Yaml::parseFile(dirname(__DIR__).'/docs/api/openapi.yaml');
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            self::$validator = new Validator;
            self::$validator->setMaxErrors(5);
            self::$validator->resolver()->registerRaw(json_decode(json_encode(self::spec())), self::DOCUMENT_ID);
        }

        return self::$validator;
    }
}
