<?php

use App\Actions\Dns\SuspendDnsZone;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can suspend their dns zone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(SuspendDnsZone::class)->handle($owner, $dnsZone);

    $dnsZone->refresh();

    expect($dnsZone->isSuspended())->toBeTrue()
        ->and($dnsZone->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($dnsZone->desired_state_version)->toBe(2)
        ->and(AuditEvent::where('action', 'dns_zone.suspended')->where('auditable_id', $dnsZone->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $dnsZone->id)
        ->where('operation', ProvisioningVerb::Suspend)
        ->first();

    expect($operation)->not->toBeNull();
});

test('duplicate suspend submissions do not create a second audit row', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(SuspendDnsZone::class)->handle($owner, $dnsZone);
    app(SuspendDnsZone::class)->handle($owner, $dnsZone);

    expect(AuditEvent::where('action', 'dns_zone.suspended')->where('auditable_id', $dnsZone->id)->count())->toBe(1);
});

test('a non-owner member cannot suspend a dns zone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $member = Membership::factory()->for($dnsZone->account)->member()->create()->user;

    app(SuspendDnsZone::class)->handle($member, $dnsZone);
})->throws(AuthorizationException::class);

test('a cascade suspension records the cascade source', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(SuspendDnsZone::class)->handle($owner, $dnsZone, SuspensionSource::Cascade);

    expect($dnsZone->refresh()->suspension_source)->toBe(SuspensionSource::Cascade);
});
