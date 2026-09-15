<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('a user with no account membership sees the welcome state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('account', null));
});

test('a member sees their own account resource counts', function () {
    $account = Account::factory()->create(['name' => 'acme-inc']);
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    WebDomain::factory()->for($account)->create();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.name', 'acme-inc')
            ->where('account.web_domains_count', 1)
        );
});
