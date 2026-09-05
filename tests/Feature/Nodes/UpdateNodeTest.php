<?php

use App\Actions\Nodes\UpdateNode;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use Illuminate\Auth\Access\AuthorizationException;

test('a provider admin can update a node with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    app(UpdateNode::class)->handle($admin, $node, ['name' => 'renamed', 'hostname' => 'renamed.example.net']);

    expect($node->refresh()->name)->toBe('renamed')
        ->and($node->hostname)->toBe('renamed.example.net')
        ->and(AuditEvent::where('action', 'node.updated')->where('auditable_id', $node->id)->exists())->toBeTrue();
});

test('a non-admin cannot update a node', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();

    app(UpdateNode::class)->handle($owner, $node, ['name' => 'renamed', 'hostname' => 'renamed.example.net']);
})->throws(AuthorizationException::class);
