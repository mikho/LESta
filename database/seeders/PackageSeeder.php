<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    /**
     * Seed one default, selectable package. There is still no admin UI to create further
     * packages (Permission::CATALOG already reserves packages.view_any/view/create/update/delete
     * for that, unused) -- this only unblocks account creation, which needs at least one
     * `is_active` package to exist.
     */
    public function run(): void
    {
        Package::query()->updateOrCreate(
            ['name' => 'Starter'],
            ['description' => 'The default package for new hosting accounts.', 'is_active' => true],
        );
    }
}
