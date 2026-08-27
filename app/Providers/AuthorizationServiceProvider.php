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

            if ($target instanceof ProviderAdminManaged && $user->isProviderAdmin()) {
                return true;
            }

            return null;
        });
    }
}
