<?php

use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\WebDomain;

/**
 * @return array{0: Account, 1: \App\Models\User, 2: WebDomain}
 */
function suspendableAccountWithDomain(): array
{
    $account = Account::factory()->for(Package::factory()->withLimit('web_domains', 5)->create())->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    return [$account, $owner, $webDomain];
}

test('a suspension by the provider can only be lifted by the provider, also resource by resource', function () {
    [$account, $owner, $webDomain] = suspendableAccountWithDomain();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->post(route('accounts.suspend', $account))->assertRedirect();
    expect($account->refresh()->suspension_source)->toBe(SuspensionSource::Provider)
        ->and($webDomain->refresh()->suspension_source)->toBe(SuspensionSource::Cascade);

    $this->actingAs($owner)->post(route('accounts.unsuspend', $account))->assertForbidden();
    $this->actingAs($owner)->post(route('domains.unsuspend', $webDomain))->assertForbidden();
    expect($account->refresh()->isSuspended())->toBeTrue()
        ->and($webDomain->refresh()->isSuspended())->toBeTrue();

    $this->actingAs($admin)->post(route('accounts.unsuspend', $account))->assertRedirect();
    expect($account->refresh()->isSuspended())->toBeFalse();
});

test('an owner lifts their own suspension, but not one of a single resource the provider made', function () {
    [$account, $owner, $webDomain] = suspendableAccountWithDomain();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($owner)->post(route('accounts.suspend', $account))->assertRedirect();
    expect($account->refresh()->suspension_source)->toBe(SuspensionSource::Manual);
    $this->actingAs($owner)->post(route('accounts.unsuspend', $account))->assertRedirect();
    expect($account->refresh()->isSuspended())->toBeFalse();

    $this->actingAs($admin)->post(route('domains.suspend', $webDomain))->assertRedirect();
    expect($webDomain->refresh()->suspension_source)->toBe(SuspensionSource::Provider);
    $this->actingAs($owner)->post(route('domains.unsuspend', $webDomain))->assertForbidden();
    expect($webDomain->refresh()->isSuspended())->toBeTrue();
});

test('a reseller suspension is lifted by the reseller or the provider, not the account owner', function () {
    [$account, $owner] = suspendableAccountWithDomain();
    $resellerAccount = Account::factory()->create();
    $reseller = Membership::factory()->for($resellerAccount)->owner()->create()->user;
    $account->forceFill(['reseller_account_id' => $resellerAccount->id])->save();

    $this->actingAs($reseller)->post(route('accounts.suspend', $account))->assertRedirect();
    expect($account->refresh()->suspension_source)->toBe(SuspensionSource::Reseller);

    $this->actingAs($owner)->post(route('accounts.unsuspend', $account))->assertForbidden();
    $this->actingAs($reseller)->post(route('accounts.unsuspend', $account))->assertRedirect();
    expect($account->refresh()->isSuspended())->toBeFalse();
});

test('while the account is suspended the owner can read but not change anything in the panel', function () {
    [$account, $owner, $webDomain] = suspendableAccountWithDomain();
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $this->actingAs($admin)->post(route('accounts.suspend', $account));

    $this->actingAs($owner)->get(route('domains.index'))->assertOk();
    $this->actingAs($owner)->post(route('domains.store'), ['domain' => 'new.example', 'ssl_mode' => 'none'])->assertForbidden();
    $this->actingAs($owner)->put(route('domains.update', $webDomain), ['domain' => $webDomain->domain, 'ssl_mode' => 'none'])->assertForbidden();
    $this->actingAs($owner)->put(route('accounts.update', $account), ['name' => 'renamed'])->assertForbidden();

    expect(WebDomain::where('domain', 'new.example')->exists())->toBeFalse()
        ->and($account->refresh()->name)->not->toBe('renamed');
});
