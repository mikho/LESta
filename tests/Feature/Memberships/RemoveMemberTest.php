<?php

use App\Actions\Memberships\RemoveMember;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('an owner can remove a plain member, with an audit event', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create();

    app(RemoveMember::class)->handle($owner, $member);

    expect(Membership::find($member->id))->toBeNull()
        ->and(AuditEvent::where('action', 'membership.removed')->where('auditable_id', $member->id)->exists())->toBeTrue();
});

test('removing the only owner of an account is refused, leaving the membership intact', function () {
    $account = Account::factory()->create();
    $onlyOwner = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(RemoveMember::class)->handle($admin, $onlyOwner);
})->throws(ValidationException::class);

test('removing one of two owners succeeds', function () {
    $account = Account::factory()->create();
    $firstOwner = Membership::factory()->for($account)->owner()->create();
    Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(RemoveMember::class)->handle($admin, $firstOwner);

    expect(Membership::find($firstOwner->id))->toBeNull();
});

test('a plain member cannot remove another member', function () {
    $account = Account::factory()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;
    $otherMember = Membership::factory()->for($account)->member()->create();

    app(RemoveMember::class)->handle($member, $otherMember);
})->throws(AuthorizationException::class);

test('a stranger cannot remove a membership', function () {
    $account = Account::factory()->create();
    $membership = Membership::factory()->for($account)->member()->create();
    $stranger = User::factory()->create();

    app(RemoveMember::class)->handle($stranger, $membership);
})->throws(AuthorizationException::class);

test('a platform-scope membership cannot be removed through this action', function () {
    $admin = Membership::factory()->providerAdmin()->create();

    app(RemoveMember::class)->handle($admin->user, $admin);
})->throws(ValidationException::class);
