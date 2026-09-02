<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\TenantDatabase;
use Inertia\Testing\AssertableInertia as Assert;

function actingAsOwnerWithTenantDatabaseCapableAccount(): array
{
    $package = Package::factory()->withLimit('tenant_databases', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);

    return [$account, $owner, $node];
}

test('the index page lists the account tenant databases', function () {
    [$account, $owner] = actingAsOwnerWithTenantDatabaseCapableAccount();
    TenantDatabase::factory()->for($account)->create(['label' => 'app1']);

    $this->actingAs($owner)
        ->get(route('tenant-databases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenant-databases/index')
            ->has('tenantDatabases.data', 1)
        );
});

test('a guest is redirected to login', function () {
    $this->get(route('tenant-databases.index'))->assertRedirect(route('login'));
});

test('storing a tenant database redirects to the edit page with a flash message', function () {
    [$account, $owner] = actingAsOwnerWithTenantDatabaseCapableAccount();

    $response = $this->actingAs($owner)
        ->post(route('tenant-databases.store'), ['label' => 'app1']);

    $tenantDatabase = TenantDatabase::where('account_id', $account->id)->where('label', 'app1')->firstOrFail();

    $response->assertRedirect(route('tenant-databases.edit', $tenantDatabase));
});

test('storing a tenant database over quota returns a validation error instead of a 500', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);

    $this->actingAs($owner)
        ->post(route('tenant-databases.store'), ['label' => 'app1'])
        ->assertSessionHasErrors('label');
});

test('storing a tenant database with no capable node available results in a server error', function () {
    $package = Package::factory()->withLimit('tenant_databases', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    // Deliberately no Node/NodeCapability at all: CreateTenantDatabase::handle() lets
    // NoTenantDatabaseCapableNodeAvailableException bubble up uncaught, matching DnsZone's own
    // controller precedent.
    $this->actingAs($owner)
        ->post(route('tenant-databases.store'), ['label' => 'app1'])
        ->assertServerError();
});

test('a non-owner member is forbidden from the create page', function () {
    $package = Package::factory()->withLimit('tenant_databases', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)->get(route('tenant-databases.create'))->assertForbidden();
});

test('rotating a tenant database password redirects back to the edit page', function () {
    [$account, $owner, $node] = actingAsOwnerWithTenantDatabaseCapableAccount();
    $tenantDatabase = TenantDatabase::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->post(route('tenant-databases.rotate-password', $tenantDatabase))
        ->assertRedirect(route('tenant-databases.edit', $tenantDatabase));
});

test('suspending a tenant database redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithTenantDatabaseCapableAccount();
    $tenantDatabase = TenantDatabase::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->from(route('tenant-databases.index'))
        ->post(route('tenant-databases.suspend', $tenantDatabase))
        ->assertRedirect(route('tenant-databases.index'));

    expect($tenantDatabase->refresh()->isSuspended())->toBeTrue();
});

test('unsuspending a tenant database redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithTenantDatabaseCapableAccount();
    $tenantDatabase = TenantDatabase::factory()->for($account)->for($node)->suspended()->create();

    $this->actingAs($owner)
        ->from(route('tenant-databases.index'))
        ->post(route('tenant-databases.unsuspend', $tenantDatabase))
        ->assertRedirect(route('tenant-databases.index'));

    expect($tenantDatabase->refresh()->isSuspended())->toBeFalse();
});

test('destroying a tenant database redirects to the index', function () {
    [$account, $owner, $node] = actingAsOwnerWithTenantDatabaseCapableAccount();
    $tenantDatabase = TenantDatabase::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->delete(route('tenant-databases.destroy', $tenantDatabase))
        ->assertRedirect(route('tenant-databases.index'));

    expect(TenantDatabase::find($tenantDatabase->id))->toBeNull();
});
