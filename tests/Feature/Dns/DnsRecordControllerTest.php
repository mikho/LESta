<?php

use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;

function actingAsOwnerWithDnsZoneAndCapableNode(): array
{
    $package = Package::factory()->withLimit('dns_records', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    return [$account, $owner, $node, $dnsZone];
}

test('storing a dns record redirects to the zone edit page', function () {
    [$account, $owner, $node, $dnsZone] = actingAsOwnerWithDnsZoneAndCapableNode();

    $this->actingAs($owner)
        ->post(route('dns.records.store', $dnsZone), [
            'name' => 'www',
            'type' => 'A',
            'value' => '203.0.113.10',
        ])
        ->assertRedirect(route('dns.edit', $dnsZone));

    expect(DnsRecord::where('dns_zone_id', $dnsZone->id)->where('name', 'www')->exists())->toBeTrue();
});

test('storing a dns record over the per-zone quota returns a validation error instead of a 500', function () {
    $package = Package::factory()->withLimit('dns_records', 0)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->post(route('dns.records.store', $dnsZone), [
            'name' => 'www',
            'type' => 'A',
            'value' => '203.0.113.10',
        ])
        ->assertSessionHasErrors('name');
});

test('updating a dns record redirects to the zone edit page', function () {
    [$account, $owner, $node, $dnsZone] = actingAsOwnerWithDnsZoneAndCapableNode();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create(['name' => 'old', 'type' => 'A', 'value' => '203.0.113.1']);

    $this->actingAs($owner)
        ->put(route('dns.records.update', [$dnsZone, $dnsRecord]), [
            'name' => 'new',
            'type' => 'A',
            'value' => '203.0.113.2',
        ])
        ->assertRedirect(route('dns.edit', $dnsZone));

    expect($dnsRecord->refresh()->name)->toBe('new');
});

test('suspending a dns record redirects back', function () {
    [$account, $owner, $node, $dnsZone] = actingAsOwnerWithDnsZoneAndCapableNode();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();

    $this->actingAs($owner)
        ->from(route('dns.edit', $dnsZone))
        ->post(route('dns.records.suspend', [$dnsZone, $dnsRecord]))
        ->assertRedirect(route('dns.edit', $dnsZone));

    expect($dnsRecord->refresh()->isSuspended())->toBeTrue();
});

test('unsuspending a dns record redirects back', function () {
    [$account, $owner, $node, $dnsZone] = actingAsOwnerWithDnsZoneAndCapableNode();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->suspended()->create();

    $this->actingAs($owner)
        ->from(route('dns.edit', $dnsZone))
        ->post(route('dns.records.unsuspend', [$dnsZone, $dnsRecord]))
        ->assertRedirect(route('dns.edit', $dnsZone));

    expect($dnsRecord->refresh()->isSuspended())->toBeFalse();
});

test('destroying a dns record redirects to the zone edit page', function () {
    [$account, $owner, $node, $dnsZone] = actingAsOwnerWithDnsZoneAndCapableNode();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();

    $this->actingAs($owner)
        ->delete(route('dns.records.destroy', [$dnsZone, $dnsRecord]))
        ->assertRedirect(route('dns.edit', $dnsZone));

    expect(DnsRecord::find($dnsRecord->id))->toBeNull();
});

test('a non-owner member is forbidden from storing a dns record', function () {
    [$account, $owner, $node, $dnsZone] = actingAsOwnerWithDnsZoneAndCapableNode();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)
        ->post(route('dns.records.store', $dnsZone), [
            'name' => 'www',
            'type' => 'A',
            'value' => '203.0.113.10',
        ])
        ->assertForbidden();
});

test('a dns record belonging to a different zone than the url cannot be updated, returning 404', function () {
    [$accountA, $ownerA, $nodeA, $dnsZoneA] = actingAsOwnerWithDnsZoneAndCapableNode();
    $nodeB = Node::factory()->create();
    NodeCapability::factory()->for($nodeB)->create(['capability' => 'dns.bind9.v1']);
    $dnsZoneB = DnsZone::factory()->for($accountA)->for($nodeB)->create();
    $dnsRecordOnZoneB = DnsRecord::factory()->for($dnsZoneB)->create();

    $this->actingAs($ownerA)
        ->put(route('dns.records.update', [$dnsZoneA, $dnsRecordOnZoneB]), [
            'name' => 'mismatched',
            'type' => 'A',
            'value' => '203.0.113.3',
        ])
        ->assertNotFound();
});
