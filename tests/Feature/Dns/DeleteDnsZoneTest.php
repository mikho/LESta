<?php

use App\Actions\Dns\DeleteDnsZone;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('deleting a suspended dns zone force-unsuspends then deletes as one action', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;
    $id = $dnsZone->id;

    app(DeleteDnsZone::class)->handle($owner, $dnsZone);

    expect(DnsZone::find($id))->toBeNull()
        ->and(AuditEvent::where('action', 'dns_zone.deleted')->where('auditable_id', $id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->first();

    expect($operation)->not->toBeNull();
});

test('a non-owner member cannot delete a dns zone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $member = Membership::factory()->for($dnsZone->account)->member()->create()->user;

    app(DeleteDnsZone::class)->handle($member, $dnsZone);
})->throws(AuthorizationException::class);

test('deleting a dns zone removes its records via the cascade FK', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    DnsRecord::factory()->for($dnsZone)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;
    $id = $dnsZone->id;

    app(DeleteDnsZone::class)->handle($owner, $dnsZone);

    expect(DnsRecord::where('dns_zone_id', $id)->count())->toBe(0);
});

test('the delete operation payload reflects the zone state before deletion', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create(['domain' => 'gone.example.com']);
    DnsRecord::factory()->for($dnsZone)->create(['name' => '@', 'value' => '203.0.113.5']);
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;
    $id = $dnsZone->id;

    app(DeleteDnsZone::class)->handle($owner, $dnsZone);

    $operation = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->first();

    expect($operation->payload['domain'])->toBe('gone.example.com')
        ->and($operation->payload['records'])->toHaveCount(1);
});
