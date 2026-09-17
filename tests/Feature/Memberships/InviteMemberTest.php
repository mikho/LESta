<?php

use App\Actions\Memberships\InviteMember;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

test('an owner can invite an existing user by email, with an audit event', function () {
    Notification::fake();

    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $existingUser = User::factory()->create();

    $membership = app(InviteMember::class)->handle($owner, $account, $existingUser->name, $existingUser->email, 'member');

    expect($membership->user_id)->toBe($existingUser->id)
        ->and($membership->account_id)->toBe($account->id)
        ->and($membership->role->name)->toBe('member')
        ->and(AuditEvent::where('action', 'membership.invited')->where('auditable_id', $membership->id)->exists())->toBeTrue();

    Notification::assertNothingSent();
});

test('inviting a brand-new email creates a user with an unusable password and sends a reset link', function () {
    Notification::fake();

    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(InviteMember::class)->handle($owner, $account, 'New Person', 'new-person@example.test', 'owner');

    $newUser = User::where('email', 'new-person@example.test')->sole();

    expect($newUser->email_verified_at)->not->toBeNull();

    Notification::assertSentTo($newUser, ResetPassword::class);
});

test('inviting a user who is already a member of the account is rejected', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $existingMember = Membership::factory()->for($account)->member()->create();

    app(InviteMember::class)->handle($owner, $account, 'x', $existingMember->user->email, 'owner');
})->throws(ValidationException::class);

test('an unrecognized role is rejected', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(InviteMember::class)->handle($owner, $account, 'x', 'x@example.test', 'superadmin');
})->throws(ValidationException::class);

test('a plain member cannot invite anyone', function () {
    $account = Account::factory()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(InviteMember::class)->handle($member, $account, 'x', 'x@example.test', 'member');
})->throws(AuthorizationException::class);

test('a stranger cannot invite anyone', function () {
    $account = Account::factory()->create();
    $stranger = User::factory()->create();

    app(InviteMember::class)->handle($stranger, $account, 'x', 'x@example.test', 'member');
})->throws(AuthorizationException::class);
