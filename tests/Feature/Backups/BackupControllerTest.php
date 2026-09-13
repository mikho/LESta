<?php

use App\Models\Account;
use App\Models\Backup;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest is redirected to login', function () {
    $this->get(route('backups.index'))->assertRedirect(route('login'));
});

test('a regular tenant-account user is denied on every backup route', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    $backup = Backup::factory()->for($node)->create();

    $this->actingAs($owner)->get(route('backups.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('backups.create'))->assertForbidden();
    $this->actingAs($owner)->post(route('backups.store'), ['node' => $node->uuid])->assertForbidden();
    $this->actingAs($owner)->delete(route('backups.destroy', $backup))->assertForbidden();
});

test('a provider admin can list backups', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['name' => 'node-a']);
    Backup::factory()->completed()->for($node)->create();

    $this->actingAs($admin)
        ->get(route('backups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('backups/index')
            ->has('backups.data', 1)
            ->where('backups.data.0.node_name', 'node-a')
        );
});

test('the create form only lists nodes with an active backup capability', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $capableNode = Node::factory()->create(['name' => 'capable']);
    NodeCapability::factory()->for($capableNode)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    Node::factory()->create(['name' => 'not-capable']);

    $this->actingAs($admin)
        ->get(route('backups.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('backups/create')
            ->has('nodes', 1)
            ->where('nodes.0.name', 'capable')
        );
});

test('a provider admin can dispatch a new backup', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    $this->actingAs($admin)
        ->post(route('backups.store'), ['node' => $node->uuid, 'label' => 'nightly'])
        ->assertRedirect(route('backups.index'));

    expect(Backup::where('node_id', $node->id)->where('label', 'nightly')->exists())->toBeTrue();
});

test('dispatching against a node with no active backup capability fails validation, not a 500', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    $this->actingAs($admin)
        ->post(route('backups.store'), ['node' => $node->uuid])
        ->assertSessionHasErrors('node');

    expect(Backup::where('node_id', $node->id)->count())->toBe(0);
});

test('a provider admin can delete a backup', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();
    $id = $backup->id;

    $this->actingAs($admin)
        ->delete(route('backups.destroy', $backup))
        ->assertRedirect(route('backups.index'));

    expect(Backup::find($id))->toBeNull();
});
