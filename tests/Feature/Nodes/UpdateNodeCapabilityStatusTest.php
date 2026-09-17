<?php

use App\Actions\Nodes\UpdateNodeCapabilityStatus;
use App\Enums\NodeCapabilityStatus;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('a provider admin can manually mark a capability as stopped, with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->running()->create();

    app(UpdateNodeCapabilityStatus::class)->handle($admin, $capability, NodeCapabilityStatus::Stopped);

    expect($capability->refresh()->status)->toBe(NodeCapabilityStatus::Stopped)
        ->and(AuditEvent::where('action', 'node_capability.status_changed')->where('auditable_id', $capability->id)->exists())->toBeTrue();
});

test('a provider admin can reset a capability back to not installed', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->running()->create();

    app(UpdateNodeCapabilityStatus::class)->handle($admin, $capability, NodeCapabilityStatus::NotInstalled);

    expect($capability->refresh()->status)->toBe(NodeCapabilityStatus::NotInstalled);
});

test('manually setting a capability to running is rejected', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->create();

    app(UpdateNodeCapabilityStatus::class)->handle($admin, $capability, NodeCapabilityStatus::Running);
})->throws(ValidationException::class);

test('the update-status route also rejects running via form validation, before the action is ever reached', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->create();

    $this->actingAs($admin)
        ->post(route('nodes.capabilities.update-status', [$node, $capability]), ['status' => 'running'])
        ->assertSessionHasErrors('status');

    expect($capability->refresh()->status)->toBe(NodeCapabilityStatus::NotInstalled);
});

test('a status change is allowed while the capability is suspended', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->suspended()->create();

    app(UpdateNodeCapabilityStatus::class)->handle($admin, $capability, NodeCapabilityStatus::NotInstalled);

    expect($capability->refresh())
        ->status->toBe(NodeCapabilityStatus::NotInstalled)
        ->isSuspended()->toBeTrue();
});

test('a stranger with no permission or grant cannot change a capability status', function () {
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->create();
    $stranger = User::factory()->create();

    app(UpdateNodeCapabilityStatus::class)->handle($stranger, $capability, NodeCapabilityStatus::Stopped);
})->throws(AuthorizationException::class);

test('setting a capability to its current status is a no-op, no audit event created', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $capability = NodeCapability::factory()->for($node)->stopped()->create();

    app(UpdateNodeCapabilityStatus::class)->handle($admin, $capability, NodeCapabilityStatus::Stopped);

    expect(AuditEvent::where('action', 'node_capability.status_changed')->where('auditable_id', $capability->id)->exists())->toBeFalse();
});
