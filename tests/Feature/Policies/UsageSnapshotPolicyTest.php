<?php

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UsageSnapshot;
use Illuminate\Support\Facades\Gate;

test('usage snapshot authorization: any account member can view their own account, a stranger cannot, an admin with usage.view_any can view any', function () {
    $node = Node::factory()->create();
    $account = Account::factory()->create();
    $usageSnapshot = UsageSnapshot::factory()->for($account)->for($node)->create();

    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('view', $usageSnapshot))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', [UsageSnapshot::class, $account]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $usageSnapshot))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [UsageSnapshot::class, $account]))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('view', $usageSnapshot))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', [UsageSnapshot::class, $account]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $usageSnapshot))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', [UsageSnapshot::class, $account]))->toBeTrue();
});

test('a platform role without usage.view_any cannot view a stranger account own usage snapshot', function () {
    $node = Node::factory()->create();
    $account = Account::factory()->create();
    $usageSnapshot = UsageSnapshot::factory()->for($account)->for($node)->create();

    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'nodes.view'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('view', $usageSnapshot))->toBeFalse();
});

test('UsageSnapshotPolicy exposes no create, update, or delete ability: rows are system-written and system-pruned only', function () {
    expect(method_exists(\App\Policies\UsageSnapshotPolicy::class, 'create'))->toBeFalse()
        ->and(method_exists(\App\Policies\UsageSnapshotPolicy::class, 'update'))->toBeFalse()
        ->and(method_exists(\App\Policies\UsageSnapshotPolicy::class, 'delete'))->toBeFalse();
});
