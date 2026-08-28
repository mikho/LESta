<?php

use App\Actions\Dns\UnsuspendDnsRecord;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can unsuspend a standalone dns record, which reprovisions the zone as an update', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create(['desired_state_version' => 1]);
    $dnsRecord = DnsRecord::factory()->suspended()->for($dnsZone)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(UnsuspendDnsRecord::class)->handle($owner, $dnsRecord);

    $dnsRecord->refresh();

    expect($dnsRecord->isSuspended())->toBeFalse()
        ->and($dnsRecord->suspension_source)->toBeNull()
        ->and(AuditEvent::where('action', 'dns_record.unsuspended')->where('auditable_id', $dnsRecord->id)->exists())->toBeTrue();

    expect($dnsZone->refresh()->desired_state_version)->toBe(2);

    $operation = ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull();
});

test('unsuspending an already-active dns record is a no-op', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(UnsuspendDnsRecord::class)->handle($owner, $dnsRecord);

    expect(AuditEvent::where('action', 'dns_record.unsuspended')->where('auditable_id', $dnsRecord->id)->count())->toBe(0);
});

test('a non-owner member cannot unsuspend a dns record', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->suspended()->for($dnsZone)->create();
    $member = Membership::factory()->for($dnsZone->account)->member()->create()->user;

    app(UnsuspendDnsRecord::class)->handle($member, $dnsRecord);
})->throws(AuthorizationException::class);
