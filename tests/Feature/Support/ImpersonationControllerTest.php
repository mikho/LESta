<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\NodeAdminGrant;
use App\Models\User;
use Illuminate\Support\Str;

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

test('impersonating and stopping never records a sign-in for either user', function () {
    $account = Account::factory()->create();
    $membership = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->post(route('impersonation.store', $membership), ['reason' => 'support ticket #123']);
    $this->delete(route('impersonation.destroy'));

    expect($admin->fresh()->last_login_at)->toBeNull()
        ->and($membership->user->fresh()->last_login_at)->toBeNull();
});

/**
 * Starts impersonating a fresh customer as a fresh admin and returns both.
 *
 * @return array{0: User, 1: Membership}
 */
function impersonatingACustomer(): array
{
    $membership = Membership::factory()->for(Account::factory())->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    test()->actingAs($admin)
        ->post(route('impersonation.store', $membership), ['reason' => 'support ticket #123'])
        ->assertRedirect(route('dashboard'));

    return [$admin, $membership];
}

test('while signed in as a customer, the account security settings, tokens and webhooks are refused', function () {
    impersonatingACustomer();

    $this->get(route('security.edit'))->assertForbidden();
    $this->get(route('api-tokens.index'))->assertForbidden();
    $this->post(route('api-tokens.store'), ['name' => 'x'])->assertForbidden();
    $this->post(route('webhooks.store'), [])->assertForbidden();
    $this->patch(route('profile.update'), ['name' => 'x', 'email' => 'taken-over@example.com'])->assertForbidden();
    $this->put(route('user-password.update'), [])->assertForbidden();
    $this->post(route('two-factor.enable'))->assertForbidden();

    $this->get(route('dashboard'))->assertOk();
});

test('the admin password confirmation does not carry over into the customer session', function () {
    $membership = Membership::factory()->for(Account::factory())->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->post(route('impersonation.store', $membership), ['reason' => 'support ticket #123']);

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

test('signing in as a customer ends on its own after an hour', function () {
    [$admin] = impersonatingACustomer();

    $this->travel(61)->minutes();

    $this->get(route('dashboard'))->assertRedirect(route('accounts.index'));

    expect(auth()->id())->toBe($admin->id)
        ->and(session()->has('impersonator_id'))->toBeFalse();
});

test('what is done while signed in as a customer names the admin in the audit log', function () {
    [$admin, $membership] = impersonatingACustomer();

    $event = AuditEvent::create([
        'actor_type' => $membership->user->getMorphClass(),
        'actor_id' => $membership->user_id,
        'auditable_type' => $membership->getMorphClass(),
        'auditable_id' => $membership->id,
        'action' => 'test.changed',
        'correlation_id' => (string) Str::uuid(),
        'metadata' => ['field' => 'value'],
    ]);

    expect($event->metadata)->toBe(['field' => 'value', 'impersonator_id' => $admin->id]);
});

test('a user who holds a node grant or belongs to a reseller account cannot be impersonated', function (string $kind) {
    $membership = Membership::factory()->for(Account::factory())->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    match ($kind) {
        'node grant' => NodeAdminGrant::factory()->create(['user_id' => $membership->user_id]),
        'reseller' => Account::factory()->create(['reseller_account_id' => $membership->account_id]),
        'the admin' => $membership->forceFill(['user_id' => $admin->id])->save(),
    };

    $this->actingAs($admin)
        ->post(route('impersonation.store', $membership), ['reason' => 'support ticket #123'])
        ->assertForbidden();

    expect(auth()->id())->toBe($admin->id);
})->with(['node grant', 'reseller', 'the admin']);
