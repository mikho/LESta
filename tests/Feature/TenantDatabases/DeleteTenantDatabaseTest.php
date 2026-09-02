<?php

use App\Actions\TenantDatabases\DeleteTenantDatabase;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;

test('deleting a suspended tenant database force-unsuspends then deletes as one action', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;
    $id = $tenantDatabase->id;

    app(DeleteTenantDatabase::class)->handle($owner, $tenantDatabase);

    expect(TenantDatabase::find($id))->toBeNull()
        ->and(AuditEvent::where('action', 'tenant_database.deleted')->where('auditable_id', $id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->first();

    expect($operation)->not->toBeNull();
});

test('a non-owner member cannot delete a tenant database', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $member = Membership::factory()->for($tenantDatabase->account)->member()->create()->user;

    app(DeleteTenantDatabase::class)->handle($member, $tenantDatabase);
})->throws(AuthorizationException::class);

test('the delete operation payload reflects the database state before deletion, with no password', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create(['database_name' => 'lesta_1_gone', 'database_user' => 'lesta_1_gone']);
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;
    $id = $tenantDatabase->id;

    app(DeleteTenantDatabase::class)->handle($owner, $tenantDatabase);

    $operation = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->first();

    expect($operation->payload['database_name'])->toBe('lesta_1_gone')
        ->and($operation->payload)->not->toHaveKey('password');
});
