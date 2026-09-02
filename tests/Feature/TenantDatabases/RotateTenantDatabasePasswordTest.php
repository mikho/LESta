<?php

use App\Actions\TenantDatabases\RotateTenantDatabasePassword;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can rotate their tenant database password', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create(['password' => 'old-password']);
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    [$rotated, $newPassword] = app(RotateTenantDatabasePassword::class)->handle($owner, $tenantDatabase);

    expect($newPassword)->toMatch('/^[0-9a-f]{48}$/')
        ->and($newPassword)->not->toBe('old-password')
        ->and($rotated->password)->toBe($newPassword)
        ->and($rotated->desired_state_version)->toBe(2)
        ->and(AuditEvent::where('action', 'tenant_database.password_rotated')->where('auditable_id', $tenantDatabase->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $tenantDatabase->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->payload['password'])->toBe($newPassword)
        ->and($operation->payload['database_name'])->toBe($tenantDatabase->database_name);
});

test('rotating never logs the password itself in the audit event', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    [, $newPassword] = app(RotateTenantDatabasePassword::class)->handle($owner, $tenantDatabase);

    $event = AuditEvent::where('action', 'tenant_database.password_rotated')->where('auditable_id', $tenantDatabase->id)->firstOrFail();

    expect(json_encode($event->getAttributes()))->not->toContain($newPassword);
});

test('a non-owner member cannot rotate a tenant database password', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $member = Membership::factory()->for($tenantDatabase->account)->member()->create()->user;

    app(RotateTenantDatabasePassword::class)->handle($member, $tenantDatabase);
})->throws(AuthorizationException::class);
