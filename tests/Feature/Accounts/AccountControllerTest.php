<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Package;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest is redirected to login', function () {
    $account = Account::factory()->create();

    $this->get(route('accounts.index'))->assertRedirect(route('login'));
    $this->get(route('accounts.show', $account))->assertRedirect(route('login'));
});

test('a non-owner member is denied on every account route, since only owners and provider admins may manage an account', function () {
    $account = Account::factory()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;
    $package = Package::factory()->create();

    $this->actingAs($member)->get(route('accounts.index'))->assertForbidden();
    $this->actingAs($member)->get(route('accounts.show', $account))->assertForbidden();
    $this->actingAs($member)->put(route('accounts.update', $account), ['name' => 'x', 'package_id' => $package->id])->assertForbidden();
    $this->actingAs($member)->post(route('accounts.suspend', $account))->assertForbidden();
    $this->actingAs($member)->post(route('accounts.unsuspend', $account))->assertForbidden();
    $this->actingAs($member)->delete(route('accounts.destroy', $account))->assertForbidden();
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
