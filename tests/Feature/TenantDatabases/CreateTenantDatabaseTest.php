<?php

use App\Actions\TenantDatabases\CreateTenantDatabase;
use App\Enums\ProvisioningStatus;
use App\Exceptions\NoTenantDatabaseCapableNodeAvailableException;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can create a tenant database and it is provisioned after commit', function () {
    $package = Package::factory()->withLimit('tenant_databases', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);

    [$tenantDatabase, $password] = app(CreateTenantDatabase::class)->handle($owner, $account, [
        'label' => 'app1',
    ]);

    expect($tenantDatabase->label)->toBe('app1')
        ->and($tenantDatabase->account_id)->toBe($account->id)
        ->and($tenantDatabase->node_id)->toBe($node->id)
        ->and($tenantDatabase->database_name)->toBe("lesta_{$account->id}_app1")
        ->and($tenantDatabase->database_user)->toBe($tenantDatabase->database_name)
        ->and($tenantDatabase->password)->toBe($password)
        ->and($password)->toMatch('/^[0-9a-f]{48}$/')
        ->and($tenantDatabase->desired_state_version)->toBe(1)
        ->and(AuditEvent::where('action', 'tenant_database.created')->where('auditable_id', $tenantDatabase->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_type', $tenantDatabase->getMorphClass())
        ->where('provisionable_id', $tenantDatabase->id)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->capability)->toBe('database.tenant.v1')
        ->and($operation->operation->value)->toBe('create')
        ->and($operation->payload['password'])->toBe($password);
});

test('a non-owner member cannot create a tenant database', function () {
    $package = Package::factory()->withLimit('tenant_databases', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(CreateTenantDatabase::class)->handle($member, $account, ['label' => 'app1']);
})->throws(AuthorizationException::class);

test('a package with no tenant_databases limit row blocks creation entirely', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'app1']);
})->throws(ResourceQuotaExceededException::class);

test('a package with an explicit limit already reached blocks creation', function () {
    $package = Package::factory()->withLimit('tenant_databases', 1)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);

    app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'first']);

    app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'second']);
})->throws(ResourceQuotaExceededException::class);

test('creation fails when no tenant-database-capable node is available', function () {
    $package = Package::factory()->withLimit('tenant_databases', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'app1']);
})->throws(NoTenantDatabaseCapableNodeAvailableException::class);

test('a rolled-back creation leaves no partial rows', function () {
    $package = Package::factory()->withLimit('tenant_databases', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    try {
        app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'app1']);
    } catch (NoTenantDatabaseCapableNodeAvailableException) {
        // expected
    }

    expect(TenantDatabase::count())->toBe(0)
        ->and(AuditEvent::where('action', 'tenant_database.created')->count())->toBe(0)
        ->and(ProvisioningOperation::count())->toBe(0);
});
