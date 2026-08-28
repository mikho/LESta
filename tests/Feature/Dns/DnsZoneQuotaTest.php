<?php

use App\Actions\Dns\CreateDnsZone;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;

function setUpDnsCapableAccount(?int $limitValue): array
{
    $package = $limitValue === -1
        ? Package::factory()->create()
        : Package::factory()->withLimit('dns_zones', $limitValue)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    return [$account, $owner];
}

test('a package with no PackageLimit row at all blocks dns zone creation', function () {
    [$account, $owner] = setUpDnsCapableAccount(-1);

    app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(ResourceQuotaExceededException::class);

test('an explicit PackageLimit row with a null limit value means unlimited', function () {
    [$account, $owner] = setUpDnsCapableAccount(null);

    DnsZone::factory()->for($account)->count(10)->create();

    $dnsZone = app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'example.com']);

    expect($dnsZone)->toBeInstanceOf(DnsZone::class);
});

test('a configured and exceeded limit blocks further creation', function () {
    [$account, $owner] = setUpDnsCapableAccount(1);

    app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'first.example.com']);

    app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'second.example.com']);
})->throws(ResourceQuotaExceededException::class);

test('creation under a configured limit is allowed', function () {
    [$account, $owner] = setUpDnsCapableAccount(2);

    app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'first.example.com']);
    $second = app(CreateDnsZone::class)->handle($owner, $account, ['domain' => 'second.example.com']);

    expect($second)->toBeInstanceOf(DnsZone::class)
        ->and($account->dnsZones()->count())->toBe(2);
});
