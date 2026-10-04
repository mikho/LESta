<?php

use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\PhpVersion;
use App\Enums\SslMode;
use App\Enums\WebServer;
use App\Exceptions\NoWebCapableNodeAvailableException;
use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\DnsZone;
use App\Models\IpAllocation;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;

test('toProvisioningPayload returns exactly the expected keys with no secret-shaped values', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $allocation = IpAllocation::factory()->for($node)->create(['ip_address' => '203.0.113.10']);
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $webDomain = WebDomain::factory()
        ->for($account)
        ->for($node)
        ->for($allocation)
        ->create(['domain' => 'example.com', 'ssl_mode' => SslMode::Manual]);
    WebDomainAlias::factory()->for($webDomain)->create(['alias' => 'www.example.com']);

    $payload = $webDomain->toProvisioningPayload('web.nginx.v1');

    expect($payload)->toBe([
        'domain' => 'example.com',
        'aliases' => ['www.example.com'],
        'ip_address' => '203.0.113.10',
        'web_template' => 'default',
        'account_id' => $account->id,
        'account_username' => 'lesta-t'.$account->id,
        'php_socket' => null,
        'adminer_socket' => null,
        'ssl' => ['mode' => 'manual'],
        'suspended' => false,
    ])
        ->and(array_keys($payload))->toBe(['domain', 'aliases', 'ip_address', 'web_template', 'account_id', 'account_username', 'php_socket', 'adminer_socket', 'ssl', 'suspended']);
});

test('toProvisioningPayload reports a real php_socket once php_version is set', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => PhpVersion::Php83]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['php_socket'])
        ->toBe("/run/lesta-php/8.3/{$webDomain->uuid}.sock");
});

test('toProvisioningPayload reports a null php_socket when php_version is not set', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => null]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['php_socket'])->toBeNull();
});

test('toProvisioningPayload reports the fixed adminer_socket once every eligibility condition is met', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'php_version' => PhpVersion::Php83,
        'certificate_issued_at' => now(),
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    NodeCapability::factory()->for($node)->create(['capability' => 'tools.adminer.v1']);
    TenantDatabase::factory()->for($webDomain->account)->for($node)->create();

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['adminer_socket'])
        ->toBe('/run/lesta-adminer/adminer.sock');
});

test('toProvisioningPayload reports a null adminer_socket for web.apache.v1 regardless of eligibility', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_server' => WebServer::Apache,
        'php_version' => PhpVersion::Php83,
        'certificate_issued_at' => now(),
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    NodeCapability::factory()->for($node)->create(['capability' => 'tools.adminer.v1']);
    TenantDatabase::factory()->for($webDomain->account)->for($node)->create();

    expect($webDomain->toProvisioningPayload('web.apache.v1')['adminer_socket'])->toBeNull();
});

test('toProvisioningPayload reports a null adminer_socket when the node has no tools.adminer.v1 capability', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'php_version' => PhpVersion::Php83,
        'certificate_issued_at' => now(),
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    TenantDatabase::factory()->for($webDomain->account)->for($node)->create();

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['adminer_socket'])->toBeNull();
});

test('toProvisioningPayload reports a null adminer_socket when the account has no tenant database on this node', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'php_version' => PhpVersion::Php83,
        'certificate_issued_at' => now(),
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    NodeCapability::factory()->for($node)->create(['capability' => 'tools.adminer.v1']);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['adminer_socket'])->toBeNull();
});

test('toPhpFpmProvisioningPayload reports the account\'s own real node identity', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['php_version' => PhpVersion::Php82]);

    expect($webDomain->toPhpFpmProvisioningPayload())->toBe([
        'account_id' => $account->id,
        'account_username' => 'lesta-t'.$account->id,
        'php_version' => '8.2',
        'suspended' => false,
    ]);
});

test('toPhpFpmProvisioningPayload accepts an explicit version override for a delete after turning PHP off', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['php_version' => null]);

    expect($webDomain->toPhpFpmProvisioningPayload(PhpVersion::Php81)['php_version'])->toBe('8.1');
});

test('toPhpFpmProvisioningPayload throws when no version is set and none is passed explicitly', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['php_version' => null]);

    $webDomain->toPhpFpmProvisioningPayload();
})->throws(RuntimeException::class);

test('toPhpFpmProvisioningPayload throws when no AccountNodeIdentity exists yet', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['php_version' => PhpVersion::Php83]);

    $webDomain->toPhpFpmProvisioningPayload();
})->throws(RuntimeException::class);

test('toProvisioningPayload omits ssl certificate paths until a certificate has actually been issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['ssl_mode' => SslMode::LetsEncrypt, 'certificate_issued_at' => null]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['ssl'])->toBe(['mode' => 'lets_encrypt']);
});

test('toProvisioningPayload adds ssl certificate paths for web.nginx.v1 once a certificate is issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'domain' => 'issued.example.com',
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => now(),
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['ssl'])->toBe([
        'mode' => 'lets_encrypt',
        'certificate_path' => '/var/lib/lesta/acme/certs/issued.example.com/fullchain.pem',
        'private_key_path' => '/var/lib/lesta/acme/certs/issued.example.com/privkey.pem',
    ]);
});

test('toProvisioningPayload adds ssl certificate paths for web.apache.v1 once a certificate is issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'domain' => 'apache-issued.example.com',
        'web_server' => WebServer::Apache,
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => now(),
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.apache.v1')['ssl'])->toBe([
        'mode' => 'lets_encrypt',
        'certificate_path' => '/var/lib/lesta/acme/certs/apache-issued.example.com/fullchain.pem',
        'private_key_path' => '/var/lib/lesta/acme/certs/apache-issued.example.com/privkey.pem',
    ]);
});

test('toProvisioningPayload omits ssl certificate paths for web.apache.v1 until a certificate has actually been issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_server' => WebServer::Apache,
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => null,
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.apache.v1')['ssl'])->toBe(['mode' => 'lets_encrypt']);
});

test('resolveDnsZone finds the exact-match zone for this domain', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'zone-match.example.com']);
    $zone = DnsZone::factory()->for($node)->create(['domain' => 'zone-match.example.com']);

    expect($webDomain->resolveDnsZone()?->id)->toBe($zone->id);
});

test('resolveDnsZone returns null when no exact-match zone exists', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'no-zone.example.com']);
    DnsZone::factory()->for($node)->create(['domain' => 'unrelated.example.com']);

    expect($webDomain->resolveDnsZone())->toBeNull();
});

test('toProvisioningPayload reflects the current suspension state', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->suspended()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['suspended'])->toBeTrue();
});

test('toProvisioningPayload overrides web_template to apache-proxy for nginx when web_server is apache', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_template' => 'custom',
        'web_server' => WebServer::Apache,
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['web_template'])->toBe('apache-proxy')
        ->and($webDomain->toProvisioningPayload('web.apache.v1')['web_template'])->toBe('custom');
});

test('toProvisioningPayload never overrides web_template when web_server is nginx', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_template' => 'custom',
        'web_server' => WebServer::Nginx,
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['web_template'])->toBe('custom');
});

test('nginx is preferred over apache when a node has both capabilities active', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    [$resolvedNode, $capabilities] = app(ResolvesWebCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capabilities)->toBe(['web.nginx.v1']);
});

test('a node with only an active apache capability is used as a fallback', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    [$resolvedNode, $capabilities] = app(ResolvesWebCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capabilities)->toBe(['web.apache.v1']);
});

test('resolve throws when no node has an active web capability', function () {
    Node::factory()->create();

    app(ResolvesWebCapableNode::class)->resolve();
})->throws(NoWebCapableNodeAvailableException::class);

test('resolveFor scopes the same nginx-over-apache priority to an already-assigned node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    expect(app(ResolvesWebCapableNode::class)->resolveFor($node))->toBe(['web.nginx.v1']);
});

test('resolve with web_server apache returns apache alone on an apache-only node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    [$resolvedNode, $capabilities] = app(ResolvesWebCapableNode::class)->resolve('apache');

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capabilities)->toBe(['web.apache.v1']);
});

test('resolve with web_server apache returns apache then nginx on a both-profile node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    [$resolvedNode, $capabilities] = app(ResolvesWebCapableNode::class)->resolve('apache');

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capabilities)->toBe(['web.apache.v1', 'web.nginx.v1']);
});

test('resolve with web_server apache throws when no node has an active apache capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    app(ResolvesWebCapableNode::class)->resolve('apache');
})->throws(NoWebCapableNodeAvailableException::class);

test('resolveFor with web_server apache throws when the given node has no active apache capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    app(ResolvesWebCapableNode::class)->resolveFor($node, 'apache');
})->throws(NoWebCapableNodeAvailableException::class);
