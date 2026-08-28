<?php

use App\Actions\Dns\UnsuspendDnsZone;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can unsuspend their dns zone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(UnsuspendDnsZone::class)->handle($owner, $dnsZone);

    $dnsZone->refresh();

    expect($dnsZone->isSuspended())->toBeFalse()
        ->and($dnsZone->suspension_source)->toBeNull()
        ->and(AuditEvent::where('action', 'dns_zone.unsuspended')->where('auditable_id', $dnsZone->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $dnsZone->id)
        ->where('operation', ProvisioningVerb::Unsuspend)
        ->first();

    expect($operation)->not->toBeNull();
});

test('unsuspending an already-active dns zone is a no-op', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(UnsuspendDnsZone::class)->handle($owner, $dnsZone);

    expect(AuditEvent::where('action', 'dns_zone.unsuspended')->where('auditable_id', $dnsZone->id)->count())->toBe(0);
});

test('a non-owner member cannot unsuspend a dns zone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->suspended()->for($node)->create();
    $member = Membership::factory()->for($dnsZone->account)->member()->create()->user;

    app(UnsuspendDnsZone::class)->handle($member, $dnsZone);
})->throws(AuthorizationException::class);
