<?php

namespace App\Providers;

use App\Contracts\ProviderAdminManaged;
use App\Models\Backup;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthorizationServiceProvider extends ServiceProvider
{
    /**
     * Models whose own policy fully owns authorization via real
     * Permission::CATALOG checks (App\Models\User::hasPermission()), not the
     * blanket ProviderAdminManaged bypass below. The blanket bypass must
     * never apply to these classes, or a provider admin's fine-grained
     * permissions would be moot (Gate::before returning true always skips
     * the policy method entirely, regardless of what it would have decided).
     * Account/Membership were never blanket-bypassed in the first place
     * (they aren't ProviderAdminManaged: most of their abilities are
     * legitimately owner-scoped, not admin-blanket), so they need no entry
     * here, but their own policy methods use hasPermission() the same way.
     *
     * @var list<class-string>
     */
    private const array PERMISSION_BACKED_MODELS = [
        Package::class,
        Node::class,
        NodeCapability::class,
        Backup::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
            $target = $arguments[0] ?? null;
            $modelClass = is_string($target) ? $target : ($target !== null ? $target::class : null);

            if ($modelClass !== null && in_array($modelClass, self::PERMISSION_BACKED_MODELS, true)) {
                return null;
            }

            // A model-instance ability check (update, delete, suspend, ...) passes the model
            // itself; a class-string ability check (viewAny, create) passes the class name
            // instead, since no instance exists yet. Both forms need to bypass here for a
            // provider admin, so both are checked.
            $isProviderAdminManaged = $target instanceof ProviderAdminManaged
                || (is_string($target) && is_a($target, ProviderAdminManaged::class, true));

            if ($isProviderAdminManaged && $user->isProviderAdmin()) {
                return true;
            }

            return null;
        });
    }
}
