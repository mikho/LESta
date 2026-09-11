<?php

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('membership authorization matrix: owner, non-owner member, stranger, admin with the full catalog', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $targetMembership = Membership::factory()->for($account)->member()->create();
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('update', $targetMembership))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $targetMembership))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('update', $targetMembership))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $targetMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', [Membership::class, $account]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $targetMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $targetMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('impersonate', $targetMembership))->toBeTrue();
});

test('impersonate is denied without memberships.impersonate even for an otherwise-permissioned admin', function () {
    $account = Account::factory()->create();
    $targetMembership = Membership::factory()->for($account)->member()->create();

    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'memberships.view'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('view', $targetMembership))->toBeTrue()
        ->and(Gate::forUser($limitedAdmin)->allows('impersonate', $targetMembership))->toBeFalse();
});

test('impersonate is denied against a platform-scope membership regardless of permission', function () {
    $platformMembership = Membership::factory()->providerAdmin()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($admin)->allows('impersonate', $platformMembership))->toBeFalse();
});
