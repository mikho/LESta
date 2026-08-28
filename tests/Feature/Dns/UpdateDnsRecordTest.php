<?php

use App\Actions\Dns\UpdateDnsRecord;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can update a dns record and the zone is re-provisioned as an update', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create(['desired_state_version' => 1]);
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create(['name' => 'www', 'type' => 'A', 'value' => '203.0.113.1']);
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    $updated = app(UpdateDnsRecord::class)->handle($owner, $dnsRecord, [
        'name' => 'www',
        'type' => 'A',
        'value' => '203.0.113.99',
    ]);

    expect($updated->value)->toBe('203.0.113.99')
        ->and(AuditEvent::where('action', 'dns_record.updated')->where('auditable_id', $dnsRecord->id)->exists())->toBeTrue();

    expect($dnsZone->refresh()->desired_state_version)->toBe(2);

    $operation = ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->desired_state_version)->toBe(2)
        ->and($operation->payload['records'][0]['value'])->toBe('203.0.113.99');
});

test('a non-owner member cannot update a dns record', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();
    $member = Membership::factory()->for($dnsZone->account)->member()->create()->user;

    app(UpdateDnsRecord::class)->handle($member, $dnsRecord, ['name' => 'www', 'type' => 'A', 'value' => '203.0.113.99']);
})->throws(AuthorizationException::class);
