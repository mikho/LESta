<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PackageSeeder extends Seeder
{
    /**
     * Seed one default, selectable package, so account creation always has at least one
     * `is_active` package to offer -- the real admin UI for managing further packages lives at
     * /packages.
     *
     * Deliberately not a single updateOrCreate() call: DatabaseSeeder's own WithoutModelEvents
     * means Package::creating() (HasUuid's own hook) never fires here, so a plain
     * updateOrCreate() would insert this row with no uuid at all and fail its NOT NULL
     * constraint -- confirmed directly via a real migrate:fresh --seed run, not assumed. Setting
     * it explicitly, only when actually creating a new row, also means a re-seed of an existing
     * package never overwrites its real uuid (which every route/link to it already depends on).
     */
    public function run(): void
    {
        $package = Package::query()->firstOrNew(['name' => 'Starter']);

        $package->fill([
            'description' => 'The default package for new hosting accounts.',
            'is_active' => true,
        ]);

        $package->uuid ??= (string) Str::uuid();

        $package->save();
    }
}
