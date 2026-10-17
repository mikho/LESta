<?php

use App\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\OpenApiResponseValidator;

/**
 * docs/api/openapi.yaml describes exactly the API's routes: every route is in the description and
 * every described path and method exists. (symfony/yaml is here through laravel/sail and
 * laravel/roster, both dev dependencies.) Every response body a test receives is also checked
 * against its schema, by Tests\OpenApiResponseValidator from Tests\TestCase.
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

test('the response check catches a body or a status the description does not have', function () {
    $owner = Membership::factory()->owner()->create();
    $request = Request::create('/api/v1/accounts/'.$owner->account->public_id.'/web-domains', 'GET');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

    $good = new JsonResponse(['data' => [], 'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null], 'meta' => ['path' => '/x', 'per_page' => 50, 'next_cursor' => null, 'prev_cursor' => null]]);
    $wrongBody = new JsonResponse(['data' => [['id' => 'not-a-uuid']], 'links' => [], 'meta' => []]);
    $wrongStatus = new JsonResponse([], 418);

    expect(OpenApiResponseValidator::check($request, $good))->toBeNull()
        ->and(OpenApiResponseValidator::check($request, $wrongBody))->toContain('does not match the schema')
        ->and(OpenApiResponseValidator::check($request, $wrongStatus))->toContain('418: this status is not described');
});
