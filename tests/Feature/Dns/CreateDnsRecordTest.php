<?php

use App\Actions\Dns\CreateDnsRecord;
use App\Enums\ProvisioningVerb;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can create a dns record and the zone is re-provisioned after commit', function () {
    $package = Package::factory()->withLimit('dns_records', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    $dnsRecord = app(CreateDnsRecord::class)->handle($owner, $dnsZone, [
        'name' => 'www',
        'type' => 'A',
        'value' => '203.0.113.20',
    ]);

    expect($dnsRecord->name)->toBe('www')
        ->and($dnsRecord->type->value)->toBe('A')
        ->and($dnsRecord->value)->toBe('203.0.113.20')
        ->and(AuditEvent::where('action', 'dns_record.created')
            ->where('auditable_id', $dnsRecord->id)
            ->where('auditable_type', $dnsRecord->getMorphClass())
            ->exists())->toBeTrue();

    expect($dnsZone->refresh()->desired_state_version)->toBe(2);

    $operation = ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->desired_state_version)->toBe(2);
});

test('a non-owner member cannot create a dns record', function () {
    $package = Package::factory()->withLimit('dns_records', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    app(CreateDnsRecord::class)->handle($member, $dnsZone, ['name' => 'www', 'type' => 'A', 'value' => '203.0.113.20']);
})->throws(AuthorizationException::class);

test('a package with no dns_records limit row blocks record creation entirely', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'www', 'type' => 'A', 'value' => '203.0.113.20']);
})->throws(ResourceQuotaExceededException::class);

test('a package with an explicit dns_records limit already reached blocks creation', function () {
    $package = Package::factory()->withLimit('dns_records', 1)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'first', 'type' => 'A', 'value' => '203.0.113.20']);

    app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'second', 'type' => 'A', 'value' => '203.0.113.21']);
})->throws(ResourceQuotaExceededException::class);

test('a rolled-back record creation leaves no partial rows', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    try {
        app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'www', 'type' => 'A', 'value' => '203.0.113.20']);
    } catch (ResourceQuotaExceededException) {
        // expected
    }

    expect(DnsRecord::count())->toBe(0)
        ->and(AuditEvent::where('action', 'dns_record.created')->count())->toBe(0);
});
