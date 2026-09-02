<?php

use App\Actions\TenantDatabases\DeleteTenantDatabase;
use App\Actions\TenantDatabases\RotateTenantDatabasePassword;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;

test('a member of another account cannot rotate a foreign tenant database password', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(RotateTenantDatabasePassword::class)->handle($stranger, $tenantDatabase);
})->throws(AuthorizationException::class);

test('a member of another account cannot delete a foreign tenant database', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(DeleteTenantDatabase::class)->handle($stranger, $tenantDatabase);
})->throws(AuthorizationException::class);
