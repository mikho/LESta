<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the application's permission catalog (Permission::CATALOG) and
     * grant every one of them to the provider_admin role, preserving that
     * role's existing blanket-everything behavior now that Account/
     * Membership/Package/Node have moved off a raw role-name check and onto
     * real per-permission gating (see AuthorizationServiceProvider).
     * RoleSeeder runs first (DatabaseSeeder's own ordering), so
     * provider_admin already exists here.
     */
    public function run(): void
    {
        $ids = collect(Permission::CATALOG)
            ->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name])->id);

        Role::query()->where('name', 'provider_admin')->first()?->permissions()->sync($ids);
    }
}
