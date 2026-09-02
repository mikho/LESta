<?php

use App\Actions\TenantDatabases\SuspendTenantDatabase;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can suspend their tenant database', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    app(SuspendTenantDatabase::class)->handle($owner, $tenantDatabase);

    $tenantDatabase->refresh();

    expect($tenantDatabase->isSuspended())->toBeTrue()
        ->and($tenantDatabase->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($tenantDatabase->desired_state_version)->toBe(2)
        ->and(AuditEvent::where('action', 'tenant_database.suspended')->where('auditable_id', $tenantDatabase->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $tenantDatabase->id)
        ->where('operation', ProvisioningVerb::Suspend)
        ->first();

    expect($operation)->not->toBeNull();
});

test('duplicate suspend submissions do not create a second audit row', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    app(SuspendTenantDatabase::class)->handle($owner, $tenantDatabase);
    app(SuspendTenantDatabase::class)->handle($owner, $tenantDatabase);

    expect(AuditEvent::where('action', 'tenant_database.suspended')->where('auditable_id', $tenantDatabase->id)->count())->toBe(1);
});

test('a non-owner member cannot suspend a tenant database', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $member = Membership::factory()->for($tenantDatabase->account)->member()->create()->user;

    app(SuspendTenantDatabase::class)->handle($member, $tenantDatabase);
})->throws(AuthorizationException::class);

test('a cascade suspension records the cascade source', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    app(SuspendTenantDatabase::class)->handle($owner, $tenantDatabase, SuspensionSource::Cascade);

    expect($tenantDatabase->refresh()->suspension_source)->toBe(SuspensionSource::Cascade);
});
