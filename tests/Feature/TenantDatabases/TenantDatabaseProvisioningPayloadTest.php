<?php

use App\Actions\Provisioning\ResolvesTenantDatabaseCapableNode;
use App\Exceptions\NoTenantDatabaseCapableNodeAvailableException;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;

test('toProvisioningPayload returns exactly the expected keys with no password by default', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create([
        'database_name' => 'lesta_1_app1',
        'database_user' => 'lesta_1_app1',
    ]);

    $payload = $tenantDatabase->toProvisioningPayload();

    expect($payload)->toBe([
        'database_name' => 'lesta_1_app1',
        'database_user' => 'lesta_1_app1',
        'suspended' => false,
    ])
        ->and(array_keys($payload))->toBe(['database_name', 'database_user', 'suspended'])
        ->and($payload)->not->toHaveKey('password');
});

test('toProvisioningPayload includes the password only when explicitly requested', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();

    $payload = $tenantDatabase->toProvisioningPayload(includePassword: true, plaintextPassword: 'a-plaintext-password');

    expect($payload['password'])->toBe('a-plaintext-password')
        ->and(array_keys($payload))->toBe(['database_name', 'database_user', 'password', 'suspended']);
});

test('toProvisioningPayload never leaks the encrypted-at-rest password when includePassword is false, regardless of the model own stored value', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create(['password' => 'super-secret-stored-password']);

    $payload = $tenantDatabase->toProvisioningPayload();

    expect($payload)->not->toHaveKey('password');
    expect(json_encode($payload))->not->toContain('super-secret-stored-password');
});

test('toProvisioningPayload reflects the current suspension state', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->suspended()->for($node)->create();

    expect($tenantDatabase->toProvisioningPayload()['suspended'])->toBeTrue();
});

test('resolve returns the first non-suspended node with an active database.tenant.v1 capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);

    [$resolvedNode, $capability] = app(ResolvesTenantDatabaseCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capability)->toBe('database.tenant.v1');
});

test('resolve throws when no node has an active tenant-database capability', function () {
    Node::factory()->create();

    app(ResolvesTenantDatabaseCapableNode::class)->resolve();
})->throws(NoTenantDatabaseCapableNodeAvailableException::class);

test('resolveFor returns the capability string for an already-assigned node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);

    expect(app(ResolvesTenantDatabaseCapableNode::class)->resolveFor($node))->toBe('database.tenant.v1');
});

test('resolveFor throws when the assigned node has no active tenant-database capability', function () {
    $node = Node::factory()->create();

    app(ResolvesTenantDatabaseCapableNode::class)->resolveFor($node);
})->throws(NoTenantDatabaseCapableNodeAvailableException::class);
