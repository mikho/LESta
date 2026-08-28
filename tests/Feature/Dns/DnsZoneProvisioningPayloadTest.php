<?php

use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Enums\DnsRecordType;
use App\Exceptions\NoDnsCapableNodeAvailableException;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Node;
use App\Models\NodeCapability;

test('toProvisioningPayload returns exactly the expected keys with no secret-shaped values', function () {
    $node = Node::factory()->create();
    $dnsZone = DnsZone::factory()->for($node)->create(['domain' => 'example.com', 'ttl' => 3600]);
    DnsRecord::factory()->for($dnsZone)->create([
        'name' => '@',
        'type' => DnsRecordType::A,
        'priority' => null,
        'value' => '203.0.113.10',
    ]);

    $payload = $dnsZone->toProvisioningPayload();

    expect($payload)->toBe([
        'domain' => 'example.com',
        'ttl' => 3600,
        'records' => [
            [
                'name' => '@',
                'type' => 'A',
                'priority' => null,
                'value' => '203.0.113.10',
                'suspended' => false,
            ],
        ],
        'suspended' => false,
    ])
        ->and(array_keys($payload))->toBe(['domain', 'ttl', 'records', 'suspended']);
});

test('toProvisioningPayload reflects the current suspension state', function () {
    $node = Node::factory()->create();
    $dnsZone = DnsZone::factory()->suspended()->for($node)->create();

    expect($dnsZone->toProvisioningPayload()['suspended'])->toBeTrue();
});

test('resolve returns the first non-suspended node with an active dns.bind9.v1 capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    [$resolvedNode, $capability] = app(ResolvesDnsCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capability)->toBe('dns.bind9.v1');
});

test('resolve throws when no node has an active dns capability', function () {
    Node::factory()->create();

    app(ResolvesDnsCapableNode::class)->resolve();
})->throws(NoDnsCapableNodeAvailableException::class);

test('resolveFor returns the capability string for an already-assigned node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    expect(app(ResolvesDnsCapableNode::class)->resolveFor($node))->toBe('dns.bind9.v1');
});

test('resolveFor throws when the assigned node has no active dns capability', function () {
    $node = Node::factory()->create();

    app(ResolvesDnsCapableNode::class)->resolveFor($node);
})->throws(NoDnsCapableNodeAvailableException::class);
