<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/api/openapi.yaml describes exactly the API's routes: every route is in the description and
 * every described path and method exists. (symfony/yaml is here through laravel/sail and
 * laravel/roster, both dev dependencies.) Validating response bodies against the schemas is step 5.
 */
test('the OpenAPI description and the API routes match', function () {
    $normalize = fn (string $path): string => preg_replace('/\{[^}]+\}/', '{}', $path);

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn ($route): array => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => strtolower($method).' '.$normalize(substr($route->uri(), strlen('api/v1'))))
            ->all())
        ->sort()->values()->all();

    $described = collect(Yaml::parseFile(base_path('docs/api/openapi.yaml'))['paths'])
        ->flatMap(fn (array $operations, string $path): array => collect(array_keys($operations))
            ->map(fn (string $method): string => $method.' '.$normalize($path))
            ->all())
        ->sort()->values()->all();

    expect($described)->toBe($routes);
});
