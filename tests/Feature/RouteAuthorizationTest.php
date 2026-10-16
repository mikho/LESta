<?php

use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Authorization in this app lives in the Actions (Gate::forUser(...)->authorize(...)), not in the
 * controllers, so nothing in the framework stops a new route from being added without it. This test
 * reads every authenticated controller route and requires that it, or something it calls, is
 * checked: the method itself, a route-level can: middleware, a form request with a real
 * authorize(), or an Action that authorizes. It is a source scan, so it proves a check exists, not
 * that it is the right one; the policy tests prove that.
 */

/** Routes that only ever act on the signed-in user's own record, or show nothing account specific. */
const SELF_SCOPED_ROUTES = [
    'dashboard',
    'accounts.mine', // lists only the accounts the signed-in user is a member of
    'impersonation.destroy', // ends the session's own impersonation
    'docs.show', 'docs.index', 'docs.chapter', // the installation guides, the same for everyone
    'verification.notice', 'verification.send', 'verification.verify',
    'logout',
    'api.v1.user', // the token's own user
    'api.v1.accounts.index', // lists only the accounts the token's user reaches, see AccountController
    'api.v1.operations.show', // only a change this very token made, see OperationController
];

function methodSource(ReflectionMethod $method): string
{
    $lines = file($method->getFileName());

    return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}

function checksAuthorization(string $source): bool
{
    return (bool) preg_match('/->authorize\(|Gate::|->can\(|->cannot\(|hasAccountRole|hasAnyAccountMembership|hasPermission/', $source);
}

/** @return list<string> */
function classesUsedIn(string $source): array
{
    preg_match_all('/([A-Z][A-Za-z0-9_]*)::class/', $source, $matches);

    return array_values(array_unique($matches[1]));
}

function resolveClass(string $short, ReflectionClass $context): ?string
{
    $source = file_get_contents($context->getFileName());

    if (preg_match('/^use\s+([^;]*\\\\'.$short.');/m', $source, $m)) {
        return $m[1];
    }

    $sameNamespace = $context->getNamespaceName().'\\'.$short;

    return class_exists($sameNamespace) ? $sameNamespace : null;
}

function routeIsAuthorized(LaravelRoute $route): bool
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && (str_starts_with($middleware, 'can:') || str_starts_with($middleware, 'permission:'))) {
            return true;
        }
    }

    $action = $route->getActionName();

    if (! str_contains($action, '@')) {
        return false;
    }

    [$class, $name] = explode('@', $action);
    $reflection = new ReflectionClass($class);
    $method = $reflection->getMethod($name);
    $source = methodSource($method);

    if (checksAuthorization($source)) {
        return true;
    }

    // A form request that decides for itself.
    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
            $request = new ReflectionClass($type->getName());

            if ($request->hasMethod('authorize') && $request->getMethod('authorize')->getDeclaringClass()->getName() === $request->getName()) {
                $body = methodSource($request->getMethod('authorize'));

                if (! preg_match('/return\s+true\s*;/', $body) || checksAuthorization($body)) {
                    return true;
                }
            }
        }
    }

    // An Action or helper the method hands the work to.
    foreach (classesUsedIn($source) as $short) {
        $used = resolveClass($short, $reflection);

        if ($used !== null && class_exists($used) && str_starts_with($used, 'App\\') && ! str_contains($used, '\\Models\\') && file_exists((new ReflectionClass($used))->getFileName()) && checksAuthorization(file_get_contents((new ReflectionClass($used))->getFileName()))) {
            return true;
        }
    }

    return false;
}

test('every signed-in controller route is authorized by itself or by what it calls', function () {
    $unguarded = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        $signedIn = collect($middleware)->contains(fn ($m) => is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:') || $m === AuthenticateApiToken::class));

        if (! $signedIn || ! is_string($route->getName()) && $route->getName() !== null) {
            continue;
        }

        if (! str_contains($route->getActionName(), '@') || str_starts_with($route->getActionName(), 'Laravel\\') || str_starts_with($route->getActionName(), 'Illuminate\\')) {
            continue;
        }

        if (in_array($route->getName(), SELF_SCOPED_ROUTES, true) || str_starts_with((string) $route->getName(), 'settings.') || str_starts_with($route->uri(), 'settings')) {
            continue;
        }

        if (! routeIsAuthorized($route)) {
            $unguarded[] = implode('|', $route->methods()).' '.$route->uri().' ('.$route->getActionName().')';
        }
    }

    expect($unguarded)->toBe([], "These routes have no authorization the scan can find. Add a Gate/policy check, or list the route in SELF_SCOPED_ROUTES with a reason:\n".implode("\n", $unguarded));
});
