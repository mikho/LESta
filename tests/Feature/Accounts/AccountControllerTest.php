<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Package;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest is redirected to login, identically for a real and a nonexistent account token', function () {
    $account = Account::factory()->create();

    $this->get(route('accounts.index'))->assertRedirect(route('login'));
    $this->get(route('accounts.show', $account))->assertRedirect(route('login'));

    // Laravel's own default middleware priority (verified directly against this app's real
    // framework version) runs `auth` before route-model binding, so a guest never reaches the
    // point where a nonexistent token would 404 differently from a real one -- both redirect to
    // login identically, leaking nothing about which accounts exist.
    $this->get('/accounts/does-not-exist')->assertRedirect(route('login'));
});

test('a non-owner member is denied on management routes, but can view their own account with no support-view audit event', function () {
    $account = Account::factory()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;
    $package = Package::factory()->create();

    $this->actingAs($member)->get(route('accounts.index'))->assertForbidden();
    $this->actingAs($member)->put(route('accounts.update', $account), ['name' => 'x', 'package_id' => $package->id])->assertForbidden();
    $this->actingAs($member)->post(route('accounts.suspend', $account))->assertForbidden();
    $this->actingAs($member)->post(route('accounts.unsuspend', $account))->assertForbidden();
    $this->actingAs($member)->delete(route('accounts.destroy', $account))->assertForbidden();

    $this->actingAs($member)->get(route('accounts.show', $account))->assertOk();

    expect(AuditEvent::where('action', 'account.viewed_as_support')->where('auditable_id', $account->id)->exists())->toBeFalse();
});

test('a logged-in stranger with no membership gets a 404, not a 403, for both a real and a nonexistent account', function () {
    $account = Account::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('accounts.show', $account))->assertNotFound();
    $this->actingAs($stranger)->get('/accounts/does-not-exist')->assertNotFound();
});

test('a provider admin can list accounts', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    Account::factory()->create(['name' => 'acme-inc']);

    $this->actingAs($admin)
        ->get(route('accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/index')
            ->has('accounts.data', 1)
            ->where('accounts.data.0.name', 'acme-inc')
        );
});

test('the account list can be searched by name or contact email', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    Account::factory()->create(['name' => 'acme-inc', 'contact_email' => 'billing@acme.test']);
    Account::factory()->create(['name' => 'other-co', 'contact_email' => 'billing@other.test']);

    $this->actingAs($admin)
        ->get(route('accounts.index', ['search' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('accounts.data', 1)
            ->where('accounts.data.0.name', 'acme-inc')
        );
});

test('a provider admin can view an account and it is recorded as a support view', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create(['name' => 'acme-inc']);
    Membership::factory()->for($account)->owner()->create();

    $this->actingAs($admin)
        ->get(route('accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/show')
            ->where('account.name', 'acme-inc')
            ->has('account.memberships', 1)
        );

    expect(AuditEvent::where('action', 'account.viewed_as_support')
        ->where('auditable_id', $account->id)
        ->exists())->toBeTrue();
});

test('a provider admin can update an account', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create(['name' => 'old-name']);
    $package = Package::factory()->create();

    $this->actingAs($admin)
        ->put(route('accounts.update', $account), [
            'name' => 'new-name',
            'contact_email' => 'new@example.test',
            'package_id' => $package->id,
        ])
        ->assertRedirect(route('accounts.show', $account));

    expect($account->refresh()->name)->toBe('new-name')
        ->and($account->contact_email)->toBe('new@example.test')
        ->and($account->package_id)->toBe($package->id);
});

test('updating an account validates its input', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create();

    $this->actingAs($admin)
        ->put(route('accounts.update', $account), ['name' => '', 'package_id' => 999999])
        ->assertSessionHasErrors(['name', 'package_id']);
});

test('a provider admin can suspend and unsuspend an account', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create();

    $this->actingAs($admin)->post(route('accounts.suspend', $account))->assertRedirect();
    expect($account->refresh()->suspended_at)->not->toBeNull();

    $this->actingAs($admin)->post(route('accounts.unsuspend', $account))->assertRedirect();
    expect($account->refresh()->suspended_at)->toBeNull();
});

test('a provider admin can delete an account', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create();
    $id = $account->id;

    $this->actingAs($admin)
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect(route('accounts.index'));

    expect(Account::find($id))->toBeNull();
});

test('a non-admin cannot reach the create-account form or submit it', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);

    $this->actingAs($user)->get(route('accounts.create'))->assertForbidden();
    $this->actingAs($user)->post(route('accounts.store'), [
        'name' => 'acme',
        'package_id' => $package->id,
        'owner_name' => 'Ada',
        'owner_email' => 'ada@example.test',
    ])->assertForbidden();
});

test('a user with no account memberships sees an empty "my account" list', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('accounts.mine'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/mine')
            ->has('accounts', 0)
        );
});

test('a member sees their own account on the "my account" list', function () {
    $account = Account::factory()->create(['name' => 'acme-inc']);
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)
        ->get(route('accounts.mine'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.name', 'acme-inc')
        );
});
