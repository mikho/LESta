<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\User;

test('a direct owner still has their role, reseller or not', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    expect($owner->hasAccountRole($account, 'owner'))->toBeTrue()
        ->and($owner->hasAnyAccountMembership($account))->toBeTrue();
});

test('a reseller-account owner reaches a managed account with no direct membership on it', function () {
    $reseller = Account::factory()->create();
    $managed = Account::factory()->create(['reseller_account_id' => $reseller->id]);
    $resellerOwner = Membership::factory()->for($reseller)->owner()->create()->user;

    expect($resellerOwner->hasAccountRole($managed, 'owner'))->toBeTrue()
        ->and($resellerOwner->hasAnyAccountMembership($managed))->toBeTrue();
});

test('a reseller-account member (non-owner) reaches a managed account via hasAnyAccountMembership but not hasAccountRole owner', function () {
    $reseller = Account::factory()->create();
    $managed = Account::factory()->create(['reseller_account_id' => $reseller->id]);
    $resellerMember = Membership::factory()->for($reseller)->member()->create()->user;

    expect($resellerMember->hasAnyAccountMembership($managed))->toBeTrue()
        ->and($resellerMember->hasAccountRole($managed, 'owner'))->toBeFalse();
});

test('a stranger with no membership on the reseller account is still denied on the managed account', function () {
    $reseller = Account::factory()->create();
    $managed = Account::factory()->create(['reseller_account_id' => $reseller->id]);
    $stranger = User::factory()->create();

    expect($stranger->hasAccountRole($managed, 'owner'))->toBeFalse()
        ->and($stranger->hasAnyAccountMembership($managed))->toBeFalse();
});

test('an owner of the managed account itself is unaffected by who owns the reseller', function () {
    $reseller = Account::factory()->create();
    $managed = Account::factory()->create(['reseller_account_id' => $reseller->id]);
    $directOwner = Membership::factory()->for($managed)->owner()->create()->user;

    expect($directOwner->hasAccountRole($managed, 'owner'))->toBeTrue();
});
