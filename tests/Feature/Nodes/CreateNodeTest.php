<?php

use App\Actions\Nodes\CreateNode;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use Illuminate\Auth\Access\AuthorizationException;

test('a provider admin can create a node with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $node = app(CreateNode::class)->handle($admin, ['name' => 'node-a', 'hostname' => 'node-a.example.net']);

    expect($node->name)->toBe('node-a')
        ->and($node->hostname)->toBe('node-a.example.net')
        ->and($node->uuid)->not->toBeEmpty()
        ->and(AuditEvent::where('action', 'node.created')->where('auditable_id', $node->id)->exists())->toBeTrue();
});

test('a non-admin cannot create a node', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateNode::class)->handle($owner, ['name' => 'node-a', 'hostname' => 'node-a.example.net']);
})->throws(AuthorizationException::class);
