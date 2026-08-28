<?php

use App\Actions\Dns\DeleteDnsRecord;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can delete a standalone dns record and the zone survives, re-provisioned as an update', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create(['desired_state_version' => 1]);
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;
    $recordId = $dnsRecord->id;

    app(DeleteDnsRecord::class)->handle($owner, $dnsRecord);

    expect(DnsRecord::find($recordId))->toBeNull()
        ->and(DnsZone::find($dnsZone->id))->not->toBeNull()
        ->and(AuditEvent::where('action', 'dns_record.deleted')->where('auditable_id', $recordId)->exists())->toBeTrue();

    expect($dnsZone->refresh()->desired_state_version)->toBe(2);

    $operation = ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->payload['records'])->toBe([]);
});

test('a non-owner member cannot delete a dns record', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();
    $member = Membership::factory()->for($dnsZone->account)->member()->create()->user;

    app(DeleteDnsRecord::class)->handle($member, $dnsRecord);
})->throws(AuthorizationException::class);
