<?php

use App\Models\AdminerAccessToken;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;
use App\Models\WebDomain;

/**
 * A tenant database on a node where Adminer is fully available: nginx and tools.adminer.v1
 * declared, and the node's own hostname served by a web domain with an issued certificate.
 */
function adminerReadyDatabase(bool $nginx = true, bool $adminer = true, bool $toolsDomain = true, bool $certified = true, string $hostname = 'node.example.test'): TenantDatabase
{
    $node = Node::factory()->create(['hostname' => $hostname]);

    if ($nginx) {
        NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    }

    if ($adminer) {
        NodeCapability::factory()->for($node)->create(['capability' => 'tools.adminer.v1']);
    }

    if ($toolsDomain) {
        WebDomain::factory()->for($node)->create([
            'domain' => $hostname,
            'certificate_issued_at' => $certified ? now() : null,
        ]);
    }

    return TenantDatabase::factory()->for($node)->create();
}

test('an owner opens adminer on the node\'s own hostname, never on a customer domain', function () {
    $tenantDatabase = adminerReadyDatabase();
    $customerDomain = WebDomain::factory()
        ->for($tenantDatabase->account)
        ->for($tenantDatabase->node)
        ->create(['domain' => 'customer.example', 'certificate_issued_at' => now()]);
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->postJson(route('tenant-databases.open-adminer', $tenantDatabase));

    $response->assertOk();

    $url = $response->json('url');

    expect($url)->toStartWith('https://node.example.test/__lesta-adminer__?token=')
        ->and($url)->not->toContain($customerDomain->domain);

    $token = str_replace('https://node.example.test/__lesta-adminer__?token=', '', $url);

    expect(AdminerAccessToken::where('token_hash', hash('sha256', $token))->where('tenant_database_id', $tenantDatabase->id)->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'tenant_database.adminer_session_prepared')->where('auditable_id', $tenantDatabase->id)->exists())->toBeTrue();
});

test('adminer is refused on a node that is missing a requirement', function (array $missing) {
    $tenantDatabase = adminerReadyDatabase(...$missing);
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->postJson(route('tenant-databases.open-adminer', $tenantDatabase))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tenant_database');

    expect(AdminerAccessToken::count())->toBe(0);
})->with([
    'no nginx, the only server that renders the hand-off' => [['nginx' => false]],
    'no tools.adminer.v1 capability' => [['adminer' => false]],
    'no web domain for the node hostname' => [['toolsDomain' => false]],
    'a node hostname domain without a certificate' => [['certified' => false]],
]);

test('a customer\'s own PHP domain with a certificate no longer makes adminer available', function () {
    $tenantDatabase = adminerReadyDatabase(toolsDomain: false);
    WebDomain::factory()->for($tenantDatabase->account)->for($tenantDatabase->node)->create([
        'domain' => 'customer.example',
        'certificate_issued_at' => now(),
    ]);
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->postJson(route('tenant-databases.open-adminer', $tenantDatabase))
        ->assertUnprocessable();
});

test('a non-member is forbidden from preparing an adminer session', function () {
    $tenantDatabase = adminerReadyDatabase();
    $outsider = Membership::factory()->owner()->create()->user;

    $this->actingAs($outsider)
        ->postJson(route('tenant-databases.open-adminer', $tenantDatabase))
        ->assertForbidden();
});

test('a non-owner member is forbidden from preparing an adminer session', function () {
    $tenantDatabase = adminerReadyDatabase();
    $member = Membership::factory()->for($tenantDatabase->account)->member()->create()->user;

    $this->actingAs($member)
        ->postJson(route('tenant-databases.open-adminer', $tenantDatabase))
        ->assertForbidden();
});

test('a suspended tenant database is rejected', function () {
    $tenantDatabase = adminerReadyDatabase();
    $tenantDatabase->forceFill(['suspended_at' => now()])->save();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->postJson(route('tenant-databases.open-adminer', $tenantDatabase))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tenant_database');
});

test('the database edit page reports whether adminer is available on its node', function () {
    $tenantDatabase = adminerReadyDatabase();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->get(route('tenant-databases.edit', $tenantDatabase))
        ->assertInertia(fn ($page) => $page->where('adminerAvailable', true));

    $unavailable = adminerReadyDatabase(adminer: false, hostname: 'other.example.test');
    $otherOwner = Membership::factory()->for($unavailable->account)->owner()->create()->user;

    $this->actingAs($otherOwner)
        ->get(route('tenant-databases.edit', $unavailable))
        ->assertInertia(fn ($page) => $page->where('adminerAvailable', false));
});
