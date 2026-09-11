<?php

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('account authorization matrix: owner, member, no membership, admin with the full catalog', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('update', $account))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $account))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $account))->toBeFalse()
        ->and(Gate::forUser($member)->allows('view', $account))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('view', $account))->toBeFalse()
        // A provider admin with accounts.update now genuinely can update any
        // account, per Permission::CATALOG -- the gap the permission table
        // existed but did nothing about before this phase.
        ->and(Gate::forUser($admin)->allows('update', $account))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('suspend', $account))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('unsuspend', $account))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $account))->toBeTrue()
        // view stays member-scoped forever: accounts.view was never added to
        // Permission::CATALOG (accounts.view_as_support is the real
        // permission-gated ability), so even a fully-permissioned admin gets
        // no plain `view` this way.
        ->and(Gate::forUser($admin)->allows('view', $account))->toBeFalse();
});

test('a platform role without accounts.update cannot update an arbitrary account', function () {
    $account = Account::factory()->create();
    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'accounts.view_as_support'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('update', $account))->toBeFalse()
        ->and(Gate::forUser($limitedAdmin)->allows('viewAsSupport', $account))->toBeTrue();
});
