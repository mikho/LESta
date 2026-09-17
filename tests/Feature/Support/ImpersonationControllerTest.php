<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;

test('an admin with permission can start impersonation and lands on the dashboard', function () {
    $account = Account::factory()->create();
    $membership = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->post(route('impersonation.store', $membership), ['reason' => 'support ticket #123'])
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($membership->user_id)
        ->and(AuditEvent::where('action', 'impersonation.started')->where('auditable_id', $membership->id)->exists())->toBeTrue();
});

test('starting impersonation requires a reason', function () {
    $account = Account::factory()->create();
    $membership = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->post(route('impersonation.store', $membership), [])
        ->assertSessionHasErrors('reason');
});

test('a user without memberships.impersonate is denied', function () {
    $account = Account::factory()->create();
    $membership = Membership::factory()->for($account)->owner()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('impersonation.store', $membership), ['reason' => 'support ticket #123'])
        ->assertForbidden();

    expect(auth()->id())->toBe($user->id);
});

test('impersonating a platform-scope membership is denied even for an admin', function () {
    $platformMembership = Membership::factory()->providerAdmin()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->post(route('impersonation.store', $platformMembership), ['reason' => 'invalid target'])
        ->assertForbidden();
});

test('stopping impersonation restores the admin session and returns to the accounts index', function () {
    $account = Account::factory()->create();
    $membership = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->post(route('impersonation.store', $membership), ['reason' => 'support ticket #123']);

    $this->delete(route('impersonation.destroy'))
        ->assertRedirect(route('accounts.index'));

    expect(auth()->id())->toBe($admin->id)
        ->and(AuditEvent::where('action', 'impersonation.ended')->exists())->toBeTrue();
});
