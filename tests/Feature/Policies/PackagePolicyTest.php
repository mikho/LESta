<?php

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Package;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('package authorization: only an admin with the matching permission passes', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('viewAny', Package::class))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $package))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewAny', Package::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $package))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Package::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $package))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $package))->toBeTrue();
});

test('a platform role with only packages.view cannot update or delete a package', function () {
    $package = Package::factory()->create();
    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'packages.view'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('view', $package))->toBeTrue()
        ->and(Gate::forUser($limitedAdmin)->allows('update', $package))->toBeFalse()
        ->and(Gate::forUser($limitedAdmin)->allows('delete', $package))->toBeFalse();
});
