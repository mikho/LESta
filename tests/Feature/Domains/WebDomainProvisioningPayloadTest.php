<?php

use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\SslMode;
use App\Enums\WebServer;
use App\Exceptions\NoWebCapableNodeAvailableException;
use App\Models\DnsZone;
use App\Models\IpAllocation;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;

test('toProvisioningPayload returns exactly the expected keys with no secret-shaped values', function () {
    $node = Node::factory()->create();
    $allocation = IpAllocation::factory()->for($node)->create(['ip_address' => '203.0.113.10']);
    $webDomain = WebDomain::factory()
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
        'ssl' => ['mode' => 'manual'],
        'suspended' => false,
    ])
        ->and(array_keys($payload))->toBe(['domain', 'aliases', 'ip_address', 'web_template', 'ssl', 'suspended']);
});

test('toProvisioningPayload omits ssl certificate paths until a certificate has actually been issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['ssl_mode' => SslMode::LetsEncrypt, 'certificate_issued_at' => null]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['ssl'])->toBe(['mode' => 'lets_encrypt']);
});

test('toProvisioningPayload adds ssl certificate paths for web.nginx.v1 once a certificate is issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'domain' => 'issued.example.com',
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => now(),
    ]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['ssl'])->toBe([
        'mode' => 'lets_encrypt',
        'certificate_path' => '/var/lib/lesta/acme/certs/issued.example.com/fullchain.pem',
        'private_key_path' => '/var/lib/lesta/acme/certs/issued.example.com/privkey.pem',
    ]);
});

test('toProvisioningPayload never adds ssl certificate paths for web.apache.v1, even once a certificate is issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => now(),
    ]);

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

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['suspended'])->toBeTrue();
});

test('toProvisioningPayload overrides web_template to apache-proxy for nginx when web_server is apache', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_template' => 'custom',
        'web_server' => WebServer::Apache,
    ]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['web_template'])->toBe('apache-proxy')
        ->and($webDomain->toProvisioningPayload('web.apache.v1')['web_template'])->toBe('custom');
});

test('toProvisioningPayload never overrides web_template when web_server is nginx', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_template' => 'custom',
        'web_server' => WebServer::Nginx,
    ]);

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
