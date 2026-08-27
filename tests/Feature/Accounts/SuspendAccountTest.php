<?php

use App\Actions\Accounts\SuspendAccount;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can suspend their account', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(SuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeTrue()
        ->and(AuditEvent::where('action', 'account.suspended')->count())->toBe(1);
});

test('duplicate suspend submissions do not create a second audit row', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(SuspendAccount::class)->handle($owner, $account);
    app(SuspendAccount::class)->handle($owner, $account);

    expect(AuditEvent::where('action', 'account.suspended')->count())->toBe(1);
});

test('a non-owner member cannot suspend an account', function () {
    $account = Account::factory()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(SuspendAccount::class)->handle($member, $account);
})->throws(AuthorizationException::class);

test('a provider admin cannot suspend an account without impersonating', function () {
    $account = Account::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(SuspendAccount::class)->handle($admin, $account);
})->throws(AuthorizationException::class);
