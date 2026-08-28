<?php

use App\Actions\Dns\UpdateDnsZone;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can update a dns zone, bumping the desired state version', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create(['domain' => 'old.example.com', 'ttl' => 14400]);
    $owner = Membership::factory()->for($dnsZone->account)->owner()->create()->user;

    $updated = app(UpdateDnsZone::class)->handle($owner, $dnsZone, [
        'domain' => 'New.Example.com',
        'ttl' => 3600,
    ]);

    expect($updated->domain)->toBe('new.example.com')
        ->and($updated->ttl)->toBe(3600)
        ->and($updated->desired_state_version)->toBe(2)
        ->and(AuditEvent::where('action', 'dns_zone.updated')->where('auditable_id', $dnsZone->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $dnsZone->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->desired_state_version)->toBe(2);
});

test('a non-owner member cannot update a dns zone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $member = Membership::factory()->for($dnsZone->account)->member()->create()->user;

    app(UpdateDnsZone::class)->handle($member, $dnsZone, ['domain' => 'new.example.com']);
})->throws(AuthorizationException::class);
