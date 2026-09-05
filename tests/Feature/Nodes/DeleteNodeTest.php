<?php

use App\Actions\Nodes\DeleteNode;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use Illuminate\Validation\ValidationException;

test('deleting a node with zero dependents succeeds with an audit event and cascades its capabilities', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create();
    $id = $node->id;

    app(DeleteNode::class)->handle($admin, $node);

    expect(Node::find($id))->toBeNull()
        ->and(NodeCapability::where('node_id', $id)->count())->toBe(0)
        ->and(AuditEvent::where('action', 'node.deleted')->where('auditable_id', $id)->exists())->toBeTrue();
});

test('deleting a node with a dependent web domain is rejected', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    WebDomain::factory()->for($node)->create();

    app(DeleteNode::class)->handle($admin, $node);
})->throws(ValidationException::class);

test('deleting a node with a dependent dns zone is rejected', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    DnsZone::factory()->for($node)->create();

    app(DeleteNode::class)->handle($admin, $node);
})->throws(ValidationException::class);

test('deleting a node with a dependent cron job is rejected', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    CronJob::factory()->for($node)->create();

    app(DeleteNode::class)->handle($admin, $node);
})->throws(ValidationException::class);

test('deleting a node with a dependent tenant database is rejected', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    TenantDatabase::factory()->for($node)->create();

    app(DeleteNode::class)->handle($admin, $node);
})->throws(ValidationException::class);

test('a rejected deletion leaves the node intact', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    WebDomain::factory()->for($node)->create();

    try {
        app(DeleteNode::class)->handle($admin, $node);
    } catch (ValidationException) {
        // expected
    }

    expect(Node::find($node->id))->not->toBeNull();
});
