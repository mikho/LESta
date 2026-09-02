<?php

use App\Actions\TenantDatabases\DeleteTenantDatabase;
use App\Actions\TenantDatabases\SuspendTenantDatabase;
use App\Actions\TenantDatabases\UnsuspendTenantDatabase;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;

/**
 * Security-critical, non-negotiable per the ADR: the tenant database password must never appear
 * in ProvisioningOperation.payload for suspend/unsuspend/delete (or observe, which this phase's
 * Laravel side never issues at all -- see ResolvesTenantDatabaseCapableNode's own suite for the
 * only two verbs, create and update/rotate, that are allowed to carry it). This is a literal
 * array_key_exists check, not merely "the password value isn't visible": a null-valued 'password'
 * key would still fail this assertion, exactly as it should.
 */
function setUpTenantDatabaseForPasswordAssertions(): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    return [$tenantDatabase, $owner];
}

function latestOperationPayloadFor(TenantDatabase $tenantDatabase, string $verb): array
{
    return ProvisioningOperation::where('provisionable_type', $tenantDatabase->getMorphClass())
        ->where('provisionable_id', $tenantDatabase->id)
        ->where('operation', $verb)
        ->latest('id')
        ->firstOrFail()
        ->payload;
}

test('suspend never includes a password key in the recorded provisioning payload', function () {
    [$tenantDatabase, $owner] = setUpTenantDatabaseForPasswordAssertions();

    app(SuspendTenantDatabase::class)->handle($owner, $tenantDatabase);

    $payload = latestOperationPayloadFor($tenantDatabase, 'suspend');

    expect($payload)->not->toHaveKey('password');
    expect(array_key_exists('password', $payload))->toBeFalse();
});

test('unsuspend never includes a password key in the recorded provisioning payload', function () {
    [$tenantDatabase, $owner] = setUpTenantDatabaseForPasswordAssertions();
    $tenantDatabase->suspend();

    app(UnsuspendTenantDatabase::class)->handle($owner, $tenantDatabase);

    $payload = latestOperationPayloadFor($tenantDatabase, 'unsuspend');

    expect($payload)->not->toHaveKey('password');
    expect(array_key_exists('password', $payload))->toBeFalse();
});

test('delete never includes a password key in the recorded provisioning payload', function () {
    [$tenantDatabase, $owner] = setUpTenantDatabaseForPasswordAssertions();
    $id = $tenantDatabase->id;

    app(DeleteTenantDatabase::class)->handle($owner, $tenantDatabase);

    $payload = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', 'delete')
        ->latest('id')
        ->firstOrFail()
        ->payload;

    expect($payload)->not->toHaveKey('password');
    expect(array_key_exists('password', $payload))->toBeFalse();
});
