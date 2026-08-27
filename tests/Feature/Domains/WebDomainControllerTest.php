<?php

use App\Models\Account;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

function actingAsOwnerWithWebCapableAccount(): array
{
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();

    return [$account, $owner, $node];
}

test('the index page lists the account web domains', function () {
    [$account, $owner] = actingAsOwnerWithWebCapableAccount();
    WebDomain::factory()->for($account)->create(['domain' => 'example.com']);

    $this->actingAs($owner)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('domains/index')
            ->has('webDomains.data', 1)
        );
});

test('a guest is redirected to login', function () {
    $this->get(route('domains.index'))->assertRedirect(route('login'));
});

test('storing a web domain redirects to the index with a flash message', function () {
    [$account, $owner] = actingAsOwnerWithWebCapableAccount();

    $this->actingAs($owner)
        ->post(route('domains.store'), ['domain' => 'example.com'])
        ->assertRedirect(route('domains.index'));

    expect(WebDomain::where('account_id', $account->id)->where('domain', 'example.com')->exists())->toBeTrue();
});

test('storing a web domain over quota returns a validation error instead of a 500', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    $this->actingAs($owner)
        ->post(route('domains.store'), ['domain' => 'example.com'])
        ->assertSessionHasErrors('domain');
});

test('a non-owner member is forbidden from the create page', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)->get(route('domains.create'))->assertForbidden();
});

test('updating a web domain redirects back to the edit page', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->put(route('domains.update', $webDomain), ['domain' => 'updated.example.com'])
        ->assertRedirect(route('domains.edit', $webDomain));
});

test('suspending a web domain redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->from(route('domains.index'))
        ->post(route('domains.suspend', $webDomain))
        ->assertRedirect(route('domains.index'));

    expect($webDomain->refresh()->isSuspended())->toBeTrue();
});

test('destroying a web domain redirects to the index', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->delete(route('domains.destroy', $webDomain))
        ->assertRedirect(route('domains.index'));

    expect(WebDomain::find($webDomain->id))->toBeNull();
});
