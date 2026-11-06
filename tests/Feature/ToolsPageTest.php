<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest is redirected to login', function () {
    $this->get(route('tools.index'))->assertRedirect(route('login'));
});

test('the tools page lists the account websites with their shortcuts', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    WebDomain::factory()->for($account)->create(['domain' => 'example.com']);

    $this->actingAs($owner)->get(route('tools.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tools/index')
            ->where('websites.0.domain', 'example.com')
            ->where('moreWebsites', 0));
});

test('only the first eight websites are listed and the rest are counted', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    WebDomain::factory()->count(10)->for($account)->create();

    $this->actingAs($owner)->get(route('tools.index'))
        ->assertInertia(fn (Assert $page) => $page->has('websites', 8)->where('moreWebsites', 2));
});

test('a user without an account gets the empty state', function () {
    $this->actingAs(User::factory()->create())->get(route('tools.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('websites', null));
});

test('every page carries the sidebar account overview', function () {
    $account = Account::factory()->create(['name' => 'Acme']);
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    WebDomain::factory()->for($account)->create(['domain' => 'acme.test']);

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarAccount.name', 'Acme')
            ->where('sidebarAccount.primary_domain', 'acme.test'));

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('sidebarAccount', null));
});
