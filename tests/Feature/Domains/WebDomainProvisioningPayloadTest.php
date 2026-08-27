<?php

use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\SslMode;
use App\Exceptions\NoWebCapableNodeAvailableException;
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

    $payload = $webDomain->toProvisioningPayload();

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

test('toProvisioningPayload reflects the current suspension state', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->suspended()->for($node)->create();

    expect($webDomain->toProvisioningPayload()['suspended'])->toBeTrue();
});

test('nginx is preferred over apache when a node has both capabilities active', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    [$resolvedNode, $capability] = app(ResolvesWebCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capability)->toBe('web.nginx.v1');
});

test('a node with only an active apache capability is used as a fallback', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    [$resolvedNode, $capability] = app(ResolvesWebCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capability)->toBe('web.apache.v1');
});

test('resolve throws when no node has an active web capability', function () {
    Node::factory()->create();

    app(ResolvesWebCapableNode::class)->resolve();
})->throws(NoWebCapableNodeAvailableException::class);

test('resolveFor scopes the same nginx-over-apache priority to an already-assigned node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    expect(app(ResolvesWebCapableNode::class)->resolveFor($node))->toBe('web.nginx.v1');
});
