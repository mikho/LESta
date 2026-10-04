<?php

use App\Enums\PhpVersion;
use App\Models\AdminerAccessToken;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\TenantDatabase;
use App\Models\WebDomain;

test('an owner can prepare an adminer session when an eligible domain exists', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $webDomain = WebDomain::factory()
        ->for($tenantDatabase->account)
        ->for($node)
        ->create([
            'php_version' => PhpVersion::Php83,
            'certificate_issued_at' => now(),
        ]);
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->postJson(route('tenant-databases.open-adminer', $tenantDatabase));

    $response->assertOk();

    $url = $response->json('url');

    expect($url)->toStartWith("https://{$webDomain->domain}/__lesta-adminer__?token=");

    $token = str_replace("https://{$webDomain->domain}/__lesta-adminer__?token=", '', $url);

    expect(AdminerAccessToken::where('token_hash', hash('sha256', $token))->where('tenant_database_id', $tenantDatabase->id)->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'tenant_database.adminer_session_prepared')->where('auditable_id', $tenantDatabase->id)->exists())->toBeTrue();
});

test('a non-member is forbidden from preparing an adminer session', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    WebDomain::factory()->for($tenantDatabase->account)->for($node)->create([
        'php_version' => PhpVersion::Php83,
        'certificate_issued_at' => now(),
    ]);

    $outsider = Membership::factory()->owner()->create()->user;

    $this->actingAs($outsider)
        ->postJson(route('tenant-databases.open-adminer', $tenantDatabase))
        ->assertForbidden();
});

test('a non-owner member is forbidden from preparing an adminer session', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    WebDomain::factory()->for($tenantDatabase->account)->for($node)->create([
        'php_version' => PhpVersion::Php83,
        'certificate_issued_at' => now(),
    ]);

    $member = Membership::factory()->for($tenantDatabase->account)->member()->create()->user;

    $this->actingAs($member)
        ->postJson(route('tenant-databases.open-adminer', $tenantDatabase))
        ->assertForbidden();
});

test('a suspended tenant database is rejected', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->suspended()->for($node)->create();
    WebDomain::factory()->for($tenantDatabase->account)->for($node)->create([
        'php_version' => PhpVersion::Php83,
        'certificate_issued_at' => now(),
    ]);
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->postJson(route('tenant-databases.open-adminer', $tenantDatabase));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['tenant_database']);
});

test('no eligible web domain is rejected with a clear message', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();
    $owner = Membership::factory()->for($tenantDatabase->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->postJson(route('tenant-databases.open-adminer', $tenantDatabase));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['tenant_database']);
});
