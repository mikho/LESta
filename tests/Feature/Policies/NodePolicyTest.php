<?php

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('node authorization: only an admin with the nodes.update permission passes', function () {
    $node = Node::factory()->create();
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('update', $node))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $node))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $node))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $node))->toBeTrue();
});

test('a platform role without nodes.update cannot update a node, even though it is otherwise a real admin role', function () {
    $node = Node::factory()->create();
    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'nodes.view'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('view', $node))->toBeTrue()
        ->and(Gate::forUser($limitedAdmin)->allows('update', $node))->toBeFalse()
        ->and(Gate::forUser($limitedAdmin)->allows('delete', $node))->toBeFalse();
});

test('a delegated node admin can view/update/suspend/unsuspend only their own granted node, never create or delete one', function () {
    $node = Node::factory()->create();
    $otherNode = Node::factory()->create();
    $delegatedAdmin = User::factory()->create();
    NodeAdminGrant::factory()->for($delegatedAdmin)->for($node)->create();

    expect(Gate::forUser($delegatedAdmin)->allows('view', $node))->toBeTrue()
        ->and(Gate::forUser($delegatedAdmin)->allows('update', $node))->toBeTrue()
        ->and(Gate::forUser($delegatedAdmin)->allows('suspend', $node))->toBeTrue()
        ->and(Gate::forUser($delegatedAdmin)->allows('unsuspend', $node))->toBeTrue()
        ->and(Gate::forUser($delegatedAdmin)->allows('create', Node::class))->toBeFalse()
        ->and(Gate::forUser($delegatedAdmin)->allows('delete', $node))->toBeFalse()
        ->and(Gate::forUser($delegatedAdmin)->allows('view', $otherNode))->toBeFalse()
        ->and(Gate::forUser($delegatedAdmin)->allows('update', $otherNode))->toBeFalse();
});

test('viewAny passes for a delegated node admin with no platform role, so they can reach a (server-scoped) node list', function () {
    $node = Node::factory()->create();
    $delegatedAdmin = User::factory()->create();
    NodeAdminGrant::factory()->for($delegatedAdmin)->for($node)->create();
    $stranger = User::factory()->create();

    expect(Gate::forUser($delegatedAdmin)->allows('viewAny', Node::class))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('viewAny', Node::class))->toBeFalse();
});
