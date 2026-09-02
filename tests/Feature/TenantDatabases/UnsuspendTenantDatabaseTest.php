<?php

use App\Actions\TenantDatabases\UnsuspendTenantDatabase;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can unsuspend their tenant database', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    app(UnsuspendTenantDatabase::class)->handle($owner, $tenantDatabase);

    $tenantDatabase->refresh();

    expect($tenantDatabase->isSuspended())->toBeFalse()
        ->and($tenantDatabase->suspension_source)->toBeNull()
        ->and(AuditEvent::where('action', 'tenant_database.unsuspended')->where('auditable_id', $tenantDatabase->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $tenantDatabase->id)
        ->where('operation', ProvisioningVerb::Unsuspend)
        ->first();

    expect($operation)->not->toBeNull();
});

test('unsuspending an already-active tenant database is a no-op', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    app(UnsuspendTenantDatabase::class)->handle($owner, $tenantDatabase);

    expect(AuditEvent::where('action', 'tenant_database.unsuspended')->where('auditable_id', $tenantDatabase->id)->count())->toBe(0);
});

test('a non-owner member cannot unsuspend a tenant database', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->suspended()->for($node)->create();
    $member = Membership::factory()->for($tenantDatabase->account)->member()->create()->user;

    app(UnsuspendTenantDatabase::class)->handle($member, $tenantDatabase);
})->throws(AuthorizationException::class);
