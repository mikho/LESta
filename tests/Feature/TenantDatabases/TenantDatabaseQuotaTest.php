<?php

use App\Actions\TenantDatabases\CreateTenantDatabase;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\TenantDatabase;

function setUpTenantDatabaseCapableAccount(?int $limitValue): array
{
    $package = $limitValue === -1
        ? Package::factory()->create()
        : Package::factory()->withLimit('tenant_databases', $limitValue)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);

    return [$account, $owner];
}

test('a package with no PackageLimit row at all blocks tenant database creation', function () {
    [$account, $owner] = setUpTenantDatabaseCapableAccount(-1);

    app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'app1']);
})->throws(ResourceQuotaExceededException::class);

test('an explicit PackageLimit row with a null limit value means unlimited', function () {
    [$account, $owner] = setUpTenantDatabaseCapableAccount(null);

    TenantDatabase::factory()->for($account)->count(10)->create();

    [$tenantDatabase] = app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'app1']);

    expect($tenantDatabase)->toBeInstanceOf(TenantDatabase::class);
});

test('a configured and exceeded limit blocks further creation', function () {
    [$account, $owner] = setUpTenantDatabaseCapableAccount(1);

    app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'first']);

    app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'second']);
})->throws(ResourceQuotaExceededException::class);

test('creation under a configured limit is allowed', function () {
    [$account, $owner] = setUpTenantDatabaseCapableAccount(2);

    app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'first']);
    [$second] = app(CreateTenantDatabase::class)->handle($owner, $account, ['label' => 'second']);

    expect($second)->toBeInstanceOf(TenantDatabase::class)
        ->and($account->tenantDatabases()->count())->toBe(2);
});
