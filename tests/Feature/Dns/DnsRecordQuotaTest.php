<?php

use App\Actions\Dns\CreateDnsRecord;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;

function setUpDnsRecordCapableAccount(?int $limitValue): array
{
    $package = $limitValue === -1
        ? Package::factory()->create()
        : Package::factory()->withLimit('dns_records', $limitValue)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    return [$account, $owner, $node];
}

test('a package with no PackageLimit row at all blocks dns record creation', function () {
    [$account, $owner, $node] = setUpDnsRecordCapableAccount(-1);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'www', 'type' => 'A', 'value' => '203.0.113.1']);
})->throws(ResourceQuotaExceededException::class);

test('an explicit PackageLimit row with a null limit value means unlimited', function () {
    [$account, $owner, $node] = setUpDnsRecordCapableAccount(null);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();
    DnsRecord::factory()->for($dnsZone)->count(10)->create();

    $dnsRecord = app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'www', 'type' => 'A', 'value' => '203.0.113.1']);

    expect($dnsRecord)->toBeInstanceOf(DnsRecord::class);
});

test('a configured and exceeded limit blocks further record creation', function () {
    [$account, $owner, $node] = setUpDnsRecordCapableAccount(1);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'first', 'type' => 'A', 'value' => '203.0.113.1']);

    app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'second', 'type' => 'A', 'value' => '203.0.113.2']);
})->throws(ResourceQuotaExceededException::class);

test('record creation under a configured limit is allowed', function () {
    [$account, $owner, $node] = setUpDnsRecordCapableAccount(2);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'first', 'type' => 'A', 'value' => '203.0.113.1']);
    $second = app(CreateDnsRecord::class)->handle($owner, $dnsZone, ['name' => 'second', 'type' => 'A', 'value' => '203.0.113.2']);

    expect($second)->toBeInstanceOf(DnsRecord::class)
        ->and($dnsZone->records()->count())->toBe(2);
});

test('the per-zone limit is enforced independently for each zone under the same account', function () {
    [$account, $owner, $node] = setUpDnsRecordCapableAccount(1);
    $fullZone = DnsZone::factory()->for($account)->for($node)->create();
    $otherZone = DnsZone::factory()->for($account)->for($node)->create();

    app(CreateDnsRecord::class)->handle($owner, $fullZone, ['name' => 'first', 'type' => 'A', 'value' => '203.0.113.1']);

    // The first zone is now at capacity (limit of 1), but the second zone's own capacity is
    // untouched: the limit applies per zone, not to the account's total record count.
    $record = app(CreateDnsRecord::class)->handle($owner, $otherZone, ['name' => 'first', 'type' => 'A', 'value' => '203.0.113.9']);

    expect($record)->toBeInstanceOf(DnsRecord::class)
        ->and($fullZone->records()->count())->toBe(1)
        ->and($otherZone->records()->count())->toBe(1);
});
