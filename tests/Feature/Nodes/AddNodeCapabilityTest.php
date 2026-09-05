<?php

use App\Actions\Nodes\AddNodeCapability;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Validation\ValidationException;

test('adding a recognized capability creates a row with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    $capability = app(AddNodeCapability::class)->handle($admin, $node, 'web.nginx.v1');

    expect($capability)->toBeInstanceOf(NodeCapability::class)
        ->and($capability->capability)->toBe('web.nginx.v1')
        ->and(AuditEvent::where('action', 'node_capability.added')->where('auditable_id', $capability->id)->exists())->toBeTrue();
});

test('adding an unrecognized capability throws a validation exception', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    app(AddNodeCapability::class)->handle($admin, $node, 'not.a.real.capability');
})->throws(ValidationException::class);

test('adding a duplicate capability throws a validation exception, not a database error', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    app(AddNodeCapability::class)->handle($admin, $node, 'web.nginx.v1');
})->throws(ValidationException::class);
