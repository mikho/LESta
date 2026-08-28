<?php

use App\Actions\Dns\SuspendDnsZone;
use App\Actions\Dns\UnsuspendDnsZone;
use App\Enums\SuspensionSource;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('dns zone suspend cascades to active records and unsuspend reactivates only cascade-sourced ones', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $preManuallySuspended = DnsRecord::factory()->for($dnsZone)->suspended()->create();
    $active = DnsRecord::factory()->for($dnsZone)->create();
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    app(SuspendDnsZone::class)->handle($owner, $dnsZone);

    expect($dnsZone->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->refresh()->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($active->refresh()->suspension_source)->toBe(SuspensionSource::Cascade)
        ->and($active->isSuspended())->toBeTrue();

    app(UnsuspendDnsZone::class)->handle($owner, $dnsZone);

    expect($dnsZone->refresh()->isSuspended())->toBeFalse()
        ->and($active->refresh()->isSuspended())->toBeFalse()
        ->and($preManuallySuspended->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->suspension_source)->toBe(SuspensionSource::Manual);
});
