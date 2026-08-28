<?php

use App\Actions\Dns\SuspendDnsRecord;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can suspend a standalone dns record, which reprovisions the zone as an update', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create(['desired_state_version' => 1]);
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(SuspendDnsRecord::class)->handle($owner, $dnsRecord);

    $dnsRecord->refresh();

    expect($dnsRecord->isSuspended())->toBeTrue()
        ->and($dnsRecord->suspension_source)->toBe(SuspensionSource::Manual)
        ->and(AuditEvent::where('action', 'dns_record.suspended')->where('auditable_id', $dnsRecord->id)->exists())->toBeTrue();

    expect($dnsZone->refresh()->desired_state_version)->toBe(2);

    $operation = ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull();
});

test('duplicate record suspend submissions do not create a second audit row', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(SuspendDnsRecord::class)->handle($owner, $dnsRecord);
    app(SuspendDnsRecord::class)->handle($owner, $dnsRecord);

    expect(AuditEvent::where('action', 'dns_record.suspended')->where('auditable_id', $dnsRecord->id)->count())->toBe(1);
});

test('a non-owner member cannot suspend a dns record', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();
    $member = Membership::factory()->for($dnsZone->account)->member()->create()->user;

    app(SuspendDnsRecord::class)->handle($member, $dnsRecord);
})->throws(AuthorizationException::class);
