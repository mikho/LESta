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
        'stats_user' => 'lesta_1_app1_ro',
    ]);

    $payload = $tenantDatabase->toProvisioningPayload();

    expect($payload)->toBe([
        'database_name' => 'lesta_1_app1',
        'database_user' => 'lesta_1_app1',
        'stats_user' => 'lesta_1_app1_ro',
        'suspended' => false,
    ])
        ->and(array_keys($payload))->toBe(['database_name', 'database_user', 'stats_user', 'suspended'])
        ->and($payload)->not->toHaveKey('password')
        ->and($payload)->not->toHaveKey('stats_password');
});

test('toProvisioningPayload includes both passwords only when explicitly requested', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();

    $payload = $tenantDatabase->toProvisioningPayload(includePassword: true, plaintextPassword: 'a-plaintext-password', statsPlaintextPassword: 'a-stats-plaintext-password');

    expect($payload['password'])->toBe('a-plaintext-password')
        ->and($payload['stats_password'])->toBe('a-stats-plaintext-password')
        ->and(array_keys($payload))->toBe(['database_name', 'database_user', 'password', 'stats_user', 'stats_password', 'suspended']);
});

test('toProvisioningPayload throws when includePassword is true but statsPlaintextPassword is omitted', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();

    $tenantDatabase->toProvisioningPayload(includePassword: true, plaintextPassword: 'a-plaintext-password');
})->throws(InvalidArgumentException::class);

test('toProvisioningPayload never leaks the encrypted-at-rest passwords when includePassword is false, regardless of the model own stored values', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create([
        'password' => 'super-secret-stored-password',
        'stats_password' => 'super-secret-stored-stats-password',
    ]);

    $payload = $tenantDatabase->toProvisioningPayload();

    expect($payload)->not->toHaveKey('password')
        ->and($payload)->not->toHaveKey('stats_password');
    expect(json_encode($payload))->not->toContain('super-secret-stored-password');
    expect(json_encode($payload))->not->toContain('super-secret-stored-stats-password');
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
