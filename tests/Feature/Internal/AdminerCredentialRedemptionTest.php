<?php

use App\Models\AdminerAccessToken;
use App\Models\Node;
use App\Models\TenantDatabase;

function mintAdminerToken(TenantDatabase $tenantDatabase, array $overrides = []): array
{
    $raw = 'test-token-'.bin2hex(random_bytes(16));

    AdminerAccessToken::create(array_merge([
        'token_hash' => hash('sha256', $raw),
        'tenant_database_id' => $tenantDatabase->id,
        'expires_at' => now()->addSeconds(60),
    ], $overrides));

    return [$raw];
}

test('a valid token returns the expected credentials with no auth required', function () {
    // host is always 127.0.0.1, never this node's own external hostname: the tenant MariaDB
    // instance binds to 127.0.0.1 only (.install/services/mariadb/install.sh), and Adminer's own
    // pool always runs on the identical node as the TenantDatabase it was opened for.
    $node = Node::factory()->create(['hostname' => 'node1.example.test']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create([
        'database_user' => 'lesta_1_app',
        'database_name' => 'lesta_1_app',
        'password' => 'super-secret-password',
    ]);

    [$token] = mintAdminerToken($tenantDatabase);

    // Bare get(), no actingAs(): this endpoint is token-only-authenticated, never a session.
    $response = $this->get("/internal/adminer-credentials/{$token}");

    $response->assertOk();
    $response->assertExactJson([
        'host' => '127.0.0.1',
        'port' => 3307,
        'username' => 'lesta_1_app',
        'password' => 'super-secret-password',
        'database' => 'lesta_1_app',
    ]);
});

test('an expired token is rejected with 404', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();

    [$token] = mintAdminerToken($tenantDatabase, ['expires_at' => now()->subSecond()]);

    $this->get("/internal/adminer-credentials/{$token}")->assertNotFound();
});

test('an already-used token is rejected on the second redemption', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($node)->create();

    [$token] = mintAdminerToken($tenantDatabase);

    $this->get("/internal/adminer-credentials/{$token}")->assertOk();
    $this->get("/internal/adminer-credentials/{$token}")->assertNotFound();
});

test('a nonexistent token is rejected with 404', function () {
    $this->get('/internal/adminer-credentials/does-not-exist')->assertNotFound();
});

test('a suspended tenant database is rejected even with a valid token', function () {
    $node = Node::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->suspended()->for($node)->create();

    [$token] = mintAdminerToken($tenantDatabase);

    $this->get("/internal/adminer-credentials/{$token}")->assertNotFound();
});
