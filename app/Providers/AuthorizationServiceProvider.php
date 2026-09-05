<?php

namespace App\Providers;

use App\Contracts\ProviderAdminManaged;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthorizationServiceProvider extends ServiceProvider
{
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
