<?php

use App\Actions\Accounts\CreateAccount;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Package;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('a platform admin can create an account for an existing user, with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create(['is_active' => true]);
    $existingUser = User::factory()->create(['email' => 'owner@example.test']);

    $account = app(CreateAccount::class)->handle($admin, [
        'name' => 'acme-inc',
        'contact_email' => null,
        'package_id' => $package->id,
        'owner_name' => 'Ignored',
        'owner_email' => 'owner@example.test',
    ]);

    expect($account->name)->toBe('acme-inc')
        ->and($account->contact_email)->toBe('owner@example.test')
        ->and(Membership::where('user_id', $existingUser->id)->where('account_id', $account->id)
            ->whereHas('role', fn ($query) => $query->where('name', 'owner'))->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'account.created')->where('auditable_id', $account->id)->exists())->toBeTrue();
});

test('creating an account for an unknown email creates a new user with an unusable password and sends a reset link', function () {
    Notification::fake();

    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create(['is_active' => true]);

    app(CreateAccount::class)->handle($admin, [
        'name' => 'acme-inc',
        'contact_email' => null,
        'package_id' => $package->id,
        'owner_name' => 'Ada Lovelace',
        'owner_email' => 'ada@example.test',
    ]);

    $newUser = User::where('email', 'ada@example.test')->first();

    expect($newUser)->not->toBeNull()
        ->and($newUser->name)->toBe('Ada Lovelace')
        ->and($newUser->email_verified_at)->not->toBeNull()
        ->and(Hash::check('anything-at-all', $newUser->password))->toBeFalse();

    Notification::assertSentTo($newUser, ResetPassword::class);
});

test('a non-admin cannot create an account', function () {
    $stranger = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);

    app(CreateAccount::class)->handle($stranger, [
        'name' => 'acme-inc',
        'contact_email' => null,
        'package_id' => $package->id,
        'owner_name' => 'Ada',
        'owner_email' => 'ada@example.test',
    ]);
})->throws(AuthorizationException::class);

test('an account owner (without accounts.create) cannot create another account', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $package = Package::factory()->create(['is_active' => true]);

    app(CreateAccount::class)->handle($owner, [
        'name' => 'another-account',
        'contact_email' => null,
        'package_id' => $package->id,
        'owner_name' => 'Ada',
        'owner_email' => 'ada@example.test',
    ]);
})->throws(AuthorizationException::class);
