<?php

use App\Enums\NodeEnrollmentStatus;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\User;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

function actingAsProviderAdmin(): User
{
    return Membership::factory()->providerAdmin()->create()->user;
}

test('a guest is redirected to login', function () {
    $this->get(route('nodes.index'))->assertRedirect(route('login'));
});

test('a regular tenant-account user is denied on every node route', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();

    $this->actingAs($owner)->get(route('nodes.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('nodes.create'))->assertForbidden();
    $this->actingAs($owner)->post(route('nodes.store'), ['name' => 'n', 'hostname' => 'h'])->assertForbidden();
    $this->actingAs($owner)->get(route('nodes.edit', $node))->assertForbidden();
    $this->actingAs($owner)->put(route('nodes.update', $node), ['name' => 'n', 'hostname' => 'h'])->assertForbidden();
    $this->actingAs($owner)->post(route('nodes.suspend', $node))->assertForbidden();
    $this->actingAs($owner)->post(route('nodes.unsuspend', $node))->assertForbidden();
    $this->actingAs($owner)->post(route('nodes.enrollment-token', $node))->assertForbidden();
    $this->actingAs($owner)->post(route('nodes.capabilities.store', $node), ['capability' => 'web.nginx.v1'])->assertForbidden();
    $this->actingAs($owner)->delete(route('nodes.destroy', $node))->assertForbidden();
});

test('a provider admin can list nodes', function () {
    $admin = actingAsProviderAdmin();
    Node::factory()->create(['name' => 'node-a']);

    $this->actingAs($admin)
        ->get(route('nodes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('nodes/index')
            ->has('nodes.data', 1)
        );
});

test('a provider admin can create a node', function () {
    $admin = actingAsProviderAdmin();

    $this->actingAs($admin)
        ->post(route('nodes.store'), ['name' => 'node-a', 'hostname' => 'node-a.example.net'])
        ->assertRedirect();

    expect(Node::where('name', 'node-a')->where('hostname', 'node-a.example.net')->exists())->toBeTrue();
});

test('a provider admin can edit a node', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();

    $this->actingAs($admin)
        ->put(route('nodes.update', $node), ['name' => 'renamed', 'hostname' => $node->hostname])
        ->assertRedirect(route('nodes.edit', $node->fresh()));

    expect($node->refresh()->name)->toBe('renamed');
});

test('a provider admin can suspend and unsuspend a node', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();

    $this->actingAs($admin)
        ->from(route('nodes.edit', $node))
        ->post(route('nodes.suspend', $node))
        ->assertRedirect(route('nodes.edit', $node));

    expect($node->refresh()->isSuspended())->toBeTrue();

    $this->actingAs($admin)
        ->from(route('nodes.edit', $node))
        ->post(route('nodes.unsuspend', $node))
        ->assertRedirect(route('nodes.edit', $node));

    expect($node->refresh()->isSuspended())->toBeFalse();
});

test('issuing an enrollment token returns a token once and the node becomes pending with a fresh hash', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();

    $this->actingAs($admin)
        ->post(route('nodes.enrollment-token', $node))
        ->assertRedirect();

    expect($node->refresh()->enrollment_status)->toBe(NodeEnrollmentStatus::Pending)
        ->and($node->enrollment_token_hash)->not->toBeNull();

    $firstHash = $node->enrollment_token_hash;

    $this->actingAs($admin)->post(route('nodes.enrollment-token', $node));

    expect($node->refresh()->enrollment_token_hash)->not->toBe($firstHash);
});

test('adding a capability creates a node capability row', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();

    $this->actingAs($admin)
        ->post(route('nodes.capabilities.store', $node), ['capability' => 'web.nginx.v1'])
        ->assertRedirect(route('nodes.edit', $node));

    expect(NodeCapability::where('node_id', $node->id)->where('capability', 'web.nginx.v1')->exists())->toBeTrue();
});

test('posting a duplicate capability is rejected with a validation error, not a 500', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    $this->actingAs($admin)
        ->post(route('nodes.capabilities.store', $node), ['capability' => 'web.nginx.v1'])
        ->assertSessionHasErrors('capability');

    expect(NodeCapability::where('node_id', $node->id)->where('capability', 'web.nginx.v1')->count())->toBe(1);
});

test('posting an unrecognized capability is rejected with a validation error', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();

    $this->actingAs($admin)
        ->post(route('nodes.capabilities.store', $node), ['capability' => 'not.a.real.capability'])
        ->assertSessionHasErrors('capability');
});

test('a provider admin can suspend and unsuspend a node capability', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->create();

    $this->actingAs($admin)
        ->post(route('nodes.capabilities.suspend', [$node, $capability]))
        ->assertRedirect(route('nodes.edit', $node));

    expect($capability->refresh()->isSuspended())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('nodes.capabilities.unsuspend', [$node, $capability]))
        ->assertRedirect(route('nodes.edit', $node));

    expect($capability->refresh()->isSuspended())->toBeFalse();
});

test('deleting a node with a dependent web domain fails validation and leaves the node intact', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();
    WebDomain::factory()->for($node)->create();

    $this->actingAs($admin)
        ->delete(route('nodes.destroy', $node))
        ->assertSessionHasErrors('node');

    expect(Node::find($node->id))->not->toBeNull();
});

test('deleting a node with zero dependents succeeds and its node capabilities are gone too', function () {
    $admin = actingAsProviderAdmin();
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create();
    $id = $node->id;

    $this->actingAs($admin)
        ->delete(route('nodes.destroy', $node))
        ->assertRedirect(route('nodes.index'));

    expect(Node::find($id))->toBeNull()
        ->and(NodeCapability::where('node_id', $id)->count())->toBe(0);
});
