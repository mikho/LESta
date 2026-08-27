<?php

namespace Database\Seeders;

use App\Enums\RoleScope;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's roles.
     */
    public function run(): void
    {
        foreach ([
            ['name' => 'owner', 'scope' => RoleScope::Account, 'description' => 'Full control over a single account.'],
            ['name' => 'member', 'scope' => RoleScope::Account, 'description' => 'Limited access within a single account.'],
            ['name' => 'provider_admin', 'scope' => RoleScope::Platform, 'description' => 'Platform-wide administrative access.'],
        ] as $role) {
            Role::query()->updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
