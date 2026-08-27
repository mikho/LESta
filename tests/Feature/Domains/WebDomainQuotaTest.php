<?php

use App\Actions\Domains\CreateWebDomain;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\WebDomain;

function setUpWebCapableAccount(?int $limitValue): array
{
    $package = $limitValue === -1
        ? Package::factory()->create()
        : Package::factory()->withLimit('web_domains', $limitValue)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();

    return [$account, $owner];
}

test('a package with no PackageLimit row at all blocks web domain creation', function () {
    [$account, $owner] = setUpWebCapableAccount(-1);

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(ResourceQuotaExceededException::class);

test('an explicit PackageLimit row with a null limit value means unlimited', function () {
    [$account, $owner] = setUpWebCapableAccount(null);

    WebDomain::factory()->for($account)->count(10)->create();

    $webDomain = app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'example.com']);

    expect($webDomain)->toBeInstanceOf(WebDomain::class);
});

test('a configured and exceeded limit blocks further creation', function () {
    [$account, $owner] = setUpWebCapableAccount(1);

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'first.example.com']);

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'second.example.com']);
})->throws(ResourceQuotaExceededException::class);

test('creation under a configured limit is allowed', function () {
    [$account, $owner] = setUpWebCapableAccount(2);

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'first.example.com']);
    $second = app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'second.example.com']);

    expect($second)->toBeInstanceOf(WebDomain::class)
        ->and($account->webDomains()->count())->toBe(2);
});
