<?php

use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('a provider admin can create, suspend, unsuspend, and change the status of a node capability', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->create();

    expect(Gate::forUser($admin)->allows('create', [NodeCapability::class, $node]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('suspend', $capability))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('unsuspend', $capability))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('updateStatus', $capability))->toBeTrue();
});

test('a delegated node admin can create, suspend, unsuspend, and change status only on their own granted node', function () {
    $node = Node::factory()->create();
    $otherNode = Node::factory()->create();
    $delegatedAdmin = User::factory()->create();
    NodeAdminGrant::factory()->for($delegatedAdmin)->for($node)->create();

    $ownCapability = NodeCapability::factory()->for($node)->create();
    $otherCapability = NodeCapability::factory()->for($otherNode)->create();

    expect(Gate::forUser($delegatedAdmin)->allows('create', [NodeCapability::class, $node]))->toBeTrue()
        ->and(Gate::forUser($delegatedAdmin)->allows('suspend', $ownCapability))->toBeTrue()
        ->and(Gate::forUser($delegatedAdmin)->allows('unsuspend', $ownCapability))->toBeTrue()
        ->and(Gate::forUser($delegatedAdmin)->allows('updateStatus', $ownCapability))->toBeTrue()
        ->and(Gate::forUser($delegatedAdmin)->allows('create', [NodeCapability::class, $otherNode]))->toBeFalse()
        ->and(Gate::forUser($delegatedAdmin)->allows('suspend', $otherCapability))->toBeFalse()
        ->and(Gate::forUser($delegatedAdmin)->allows('updateStatus', $otherCapability))->toBeFalse();
});

test('a stranger with no permission and no grant cannot touch any node capability', function () {
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->create();
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('create', [NodeCapability::class, $node]))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('suspend', $capability))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('unsuspend', $capability))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('updateStatus', $capability))->toBeFalse();
});
