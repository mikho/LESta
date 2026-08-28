<?php

use App\Models\Account;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use Inertia\Testing\AssertableInertia as Assert;

function actingAsOwnerWithDnsCapableAccount(): array
{
    $package = Package::factory()->withLimit('dns_zones', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    return [$account, $owner, $node];
}

test('the index page lists the account dns zones', function () {
    [$account, $owner] = actingAsOwnerWithDnsCapableAccount();
    DnsZone::factory()->for($account)->create(['domain' => 'example.com']);

    $this->actingAs($owner)
        ->get(route('dns.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dns/index')
            ->has('dnsZones.data', 1)
        );
});

test('a guest is redirected to login', function () {
    $this->get(route('dns.index'))->assertRedirect(route('login'));
});

test('storing a dns zone redirects to the index with a flash message', function () {
    [$account, $owner] = actingAsOwnerWithDnsCapableAccount();

    $this->actingAs($owner)
        ->post(route('dns.store'), ['domain' => 'example.com'])
        ->assertRedirect(route('dns.index'));

    expect(DnsZone::where('account_id', $account->id)->where('domain', 'example.com')->exists())->toBeTrue();
});

test('storing a dns zone over quota returns a validation error instead of a 500', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    $this->actingAs($owner)
        ->post(route('dns.store'), ['domain' => 'example.com'])
        ->assertSessionHasErrors('domain');
});

test('storing a dns zone with no dns-capable node available results in a server error', function () {
    $package = Package::factory()->withLimit('dns_zones', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    // Deliberately no Node/NodeCapability at all: CreateDnsZone::handle() lets
    // NoDnsCapableNodeAvailableException bubble up uncaught, matching decision #4 of the plan
    // (WebDomainController's store() does not catch NoWebCapableNodeAvailableException either).
    $this->actingAs($owner)
        ->post(route('dns.store'), ['domain' => 'example.com'])
        ->assertServerError();
});

test('a non-owner member is forbidden from the create page', function () {
    $package = Package::factory()->withLimit('dns_zones', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)->get(route('dns.create'))->assertForbidden();
});

test('updating a dns zone redirects back to the edit page', function () {
    [$account, $owner, $node] = actingAsOwnerWithDnsCapableAccount();
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->put(route('dns.update', $dnsZone), ['domain' => 'updated.example.com'])
        ->assertRedirect(route('dns.edit', $dnsZone));
});

test('suspending a dns zone redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithDnsCapableAccount();
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->from(route('dns.index'))
        ->post(route('dns.suspend', $dnsZone))
        ->assertRedirect(route('dns.index'));

    expect($dnsZone->refresh()->isSuspended())->toBeTrue();
});

test('unsuspending a dns zone redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithDnsCapableAccount();
    $dnsZone = DnsZone::factory()->for($account)->for($node)->suspended()->create();

    $this->actingAs($owner)
        ->from(route('dns.index'))
        ->post(route('dns.unsuspend', $dnsZone))
        ->assertRedirect(route('dns.index'));

    expect($dnsZone->refresh()->isSuspended())->toBeFalse();
});

test('destroying a dns zone redirects to the index', function () {
    [$account, $owner, $node] = actingAsOwnerWithDnsCapableAccount();
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->delete(route('dns.destroy', $dnsZone))
        ->assertRedirect(route('dns.index'));

    expect(DnsZone::find($dnsZone->id))->toBeNull();
});
