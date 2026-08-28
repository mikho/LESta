<?php

use App\Actions\Dns\CreateDnsZone;
use App\Enums\ProvisioningStatus;
use App\Exceptions\NoDnsCapableNodeAvailableException;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can create a dns zone and it is provisioned after commit', function () {
    $package = Package::factory()->withLimit('dns_zones', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    $dnsZone = app(CreateDnsZone::class)->handle($owner, $account, [
        'domain' => 'Example.COM',
    ]);

    expect($dnsZone->domain)->toBe('example.com')
        ->and($dnsZone->account_id)->toBe($account->id)
        ->and($dnsZone->node_id)->toBe($node->id)
        ->and($dnsZone->ttl)->toBe(14400)
        ->and($dnsZone->desired_state_version)->toBe(1)
        ->and(AuditEvent::where('action', 'dns_zone.created')->where('auditable_id', $dnsZone->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->capability)->toBe('dns.bind9.v1')
        ->and($operation->operation->value)->toBe('create');
});

test('a non-owner member cannot create a dns zone', function () {
    $package = Package::factory()->withLimit('dns_zones', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(CreateDnsZone::class)->handle($member, $account, ['domain' => 'example.com']);
})->throws(AuthorizationException::class);

test('a package with no dns_zones limit row blocks creation entirely', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(ResourceQuotaExceededException::class);

test('a package with an explicit limit already reached blocks creation', function () {
    $package = Package::factory()->withLimit('dns_zones', 1)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'first.example.com']);

    app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'second.example.com']);
})->throws(ResourceQuotaExceededException::class);

test('creation fails when no dns-capable node is available', function () {
    $package = Package::factory()->withLimit('dns_zones', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(NoDnsCapableNodeAvailableException::class);

test('a rolled-back creation leaves no partial rows', function () {
    $package = Package::factory()->withLimit('dns_zones', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    try {
        app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'example.com']);
    } catch (NoDnsCapableNodeAvailableException) {
        // expected
    }

    expect(DnsZone::count())->toBe(0)
        ->and(AuditEvent::where('action', 'dns_zone.created')->count())->toBe(0)
        ->and(ProvisioningOperation::count())->toBe(0);
});
