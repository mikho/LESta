<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the application's permission catalog.
     */
    public function run(): void
    {
        foreach ([
            'accounts.view', 'accounts.update', 'accounts.suspend', 'accounts.unsuspend', 'accounts.delete',
            'memberships.view', 'memberships.create', 'memberships.update', 'memberships.delete', 'memberships.impersonate',
            'packages.view', 'packages.update',
            'nodes.view', 'nodes.update', 'nodes.suspend', 'nodes.unsuspend',
        ] as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
    }
}
