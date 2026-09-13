<?php

use App\Actions\Accounts\UpdateAccount;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Package;
use Illuminate\Auth\Access\AuthorizationException;

test('an account owner can update their own account', function () {
    $account = Account::factory()->create(['name' => 'old-name']);
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $package = Package::factory()->create();

    app(UpdateAccount::class)->handle($owner, $account, [
        'name' => 'new-name',
        'contact_email' => 'new@example.test',
        'package_id' => $package->id,
    ]);

    expect($account->refresh()->name)->toBe('new-name')
        ->and($account->contact_email)->toBe('new@example.test')
        ->and($account->package_id)->toBe($package->id);
});

test('a non-owner member cannot update the account', function () {
    $account = Account::factory()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;
    $package = Package::factory()->create();

    expect(fn () => app(UpdateAccount::class)->handle($member, $account, [
        'name' => 'new-name',
        'package_id' => $package->id,
    ]))->toThrow(AuthorizationException::class);
});

test('a provider admin with accounts.update can update any account', function () {
    $account = Account::factory()->create(['name' => 'old-name']);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(UpdateAccount::class)->handle($admin, $account, [
        'name' => 'new-name',
        'package_id' => $account->package_id,
    ]);

    expect($account->refresh()->name)->toBe('new-name');
});

test('updating leaves fields not present in the data array untouched', function () {
    $account = Account::factory()->create(['name' => 'keep-me', 'contact_email' => 'keep@example.test']);
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(UpdateAccount::class)->handle($owner, $account, [
        'package_id' => $account->package_id,
    ]);

    expect($account->refresh()->name)->toBe('keep-me')
        ->and($account->contact_email)->toBe('keep@example.test');
});
