<?php

use App\Contracts\ProviderAdminManaged;
use App\Providers\AuthorizationServiceProvider;
use Illuminate\Support\Str;

/**
 * AuthorizationServiceProvider's global Gate::before grants a provider admin every ability on any
 * model implementing ProviderAdminManaged, EXCEPT the models named in its own private
 * PERMISSION_BACKED_MODELS list -- those must fall through to their real policy method, which
 * checks the acting admin's actual granted permissions (User::hasPermission()) rather than a
 * blanket "provider admin, so yes" bypass. Nothing enforced this before: a future
 * ProviderAdminManaged model with a permission-checking policy, if its class never gets added to
 * that list, would silently get the blanket bypass instead -- every provider admin gets full
 * access regardless of their actual granted permissions, with no error, test failure, or runtime
 * signal anywhere. This test is that signal.
 */
test('every ProviderAdminManaged model whose policy checks real permissions is excluded from the blanket admin bypass', function () {
    $permissionBackedModels = (new ReflectionClass(AuthorizationServiceProvider::class))
        ->getConstant('PERMISSION_BACKED_MODELS');

    $modelFiles = glob(app_path('Models/*.php'));

    expect($modelFiles)->not->toBeEmpty();

    foreach ($modelFiles as $modelFile) {
        $modelClass = 'App\\Models\\'.basename($modelFile, '.php');

        if (! is_a($modelClass, ProviderAdminManaged::class, true)) {
            continue;
        }

        $policyClass = 'App\\Policies\\'.class_basename($modelClass).'Policy';
        $policyFile = app_path('Policies/'.class_basename($modelClass).'Policy.php');

        if (! file_exists($policyFile)) {
            continue;
        }

        $policySource = file_get_contents($policyFile);
        $checksRealPermissions = Str::contains($policySource, 'hasPermission(');

        if ($checksRealPermissions) {
            // toContain() is variadic (every argument is a needle, not a message) -- a plain
            // boolean check here, not expect(...)->toContain(...), so a real failure message can
            // actually explain what's missing and why it matters instead of just naming the
            // (correctly absent) message string as an unmatched "needle".
            expect(in_array($modelClass, $permissionBackedModels, true))->toBeTrue(
                "{$policyClass} calls hasPermission() but {$modelClass} is missing from AuthorizationServiceProvider::PERMISSION_BACKED_MODELS -- the blanket provider-admin bypass would silently skip this policy's own real permission check entirely."
            );
        }
    }
});

test('every model in PERMISSION_BACKED_MODELS still implements ProviderAdminManaged', function () {
    $permissionBackedModels = (new ReflectionClass(AuthorizationServiceProvider::class))
        ->getConstant('PERMISSION_BACKED_MODELS');

    foreach ($permissionBackedModels as $modelClass) {
        expect(is_a($modelClass, ProviderAdminManaged::class, true))
            ->toBeTrue("{$modelClass} is listed in PERMISSION_BACKED_MODELS but no longer implements ProviderAdminManaged -- the entry is now meaningless (the blanket bypass never applied to it in the first place) and should be removed.");
    }
});
