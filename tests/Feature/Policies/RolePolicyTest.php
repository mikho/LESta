<?php

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Package;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('role authorization: only an admin with the matching permission passes', function () {
    $role = Role::factory()->create(['scope' => RoleScope::Platform]);
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('viewAny', Role::class))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $role))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewAny', Role::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $role))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Role::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $role))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $role))->toBeTrue();
});

test('a custom platform role with only roles.view_any cannot create, update, or delete a role', function () {
    $role = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'roles.view_any'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('viewAny', Role::class))->toBeTrue()
        ->and(Gate::forUser($limitedAdmin)->allows('create', Role::class))->toBeFalse()
        ->and(Gate::forUser($limitedAdmin)->allows('update', $role))->toBeFalse()
        ->and(Gate::forUser($limitedAdmin)->allows('delete', $role))->toBeFalse();
});

test('a custom role granting only packages.view_any composes correctly: it can view packages but not manage roles', function () {
    Package::factory()->create();
    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'packages.view_any'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('viewAny', Package::class))->toBeTrue()
        ->and(Gate::forUser($limitedAdmin)->allows('viewAny', Role::class))->toBeFalse()
        ->and(Gate::forUser($limitedAdmin)->allows('create', Role::class))->toBeFalse();
});
