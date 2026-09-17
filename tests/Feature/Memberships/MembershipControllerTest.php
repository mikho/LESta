<?php

use App\Models\Account;
use App\Models\Membership;
use Inertia\Testing\AssertableInertia as Assert;

test('an owner can invite and remove a member through the real routes', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    $this->actingAs($owner)
        ->post(route('accounts.memberships.store', $account), [
            'name' => 'New Member',
            'email' => 'new-member@example.test',
            'role' => 'member',
        ])
        ->assertRedirect(route('accounts.show', $account));

    $membership = Membership::whereHas('user', fn ($q) => $q->where('email', 'new-member@example.test'))->sole();

    expect($membership->account_id)->toBe($account->id);

    $this->actingAs($owner)
        ->delete(route('accounts.memberships.destroy', [$account, $membership]))
        ->assertRedirect(route('accounts.show', $account));

    expect(Membership::find($membership->id))->toBeNull();
});

test('a plain member gets a 403 attempting to invite or remove a member', function () {
    $account = Account::factory()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;
    $otherMember = Membership::factory()->for($account)->member()->create();

    $this->actingAs($member)
        ->post(route('accounts.memberships.store', $account), ['name' => 'x', 'email' => 'x@example.test', 'role' => 'member'])
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('accounts.memberships.destroy', [$account, $otherMember]))
        ->assertForbidden();
});

test('the account show page exposes canInviteMembers/canRemoveMembers correctly for an owner versus a member', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($owner)
        ->get(route('accounts.show', $account))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canInviteMembers', true)
            ->where('canRemoveMembers', true)
        );

    $this->actingAs($member)
        ->get(route('accounts.show', $account))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canInviteMembers', false)
            ->where('canRemoveMembers', false)
        );
});

test('inviting a duplicate member surfaces a validation error, not a 500', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $existingMember = Membership::factory()->for($account)->member()->create();

    $this->actingAs($owner)
        ->post(route('accounts.memberships.store', $account), [
            'name' => 'x',
            'email' => $existingMember->user->email,
            'role' => 'owner',
        ])
        ->assertSessionHasErrors('email');
});

test('removing the last owner surfaces a validation error, not a 500', function () {
    $account = Account::factory()->create();
    $onlyOwner = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->delete(route('accounts.memberships.destroy', [$account, $onlyOwner]))
        ->assertSessionHasErrors('membership');

    expect(Membership::find($onlyOwner->id))->not->toBeNull();
});
