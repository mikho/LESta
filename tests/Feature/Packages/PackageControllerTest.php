<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\Package;
use App\Models\PackageLimit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest is redirected to login', function () {
    $this->get(route('packages.index'))->assertRedirect(route('login'));
});

test('a non-admin is forbidden from every package route', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create();

    $this->actingAs($user)->get(route('packages.index'))->assertForbidden();
    $this->actingAs($user)->get(route('packages.create'))->assertForbidden();
    $this->actingAs($user)->get(route('packages.edit', $package))->assertForbidden();
    $this->actingAs($user)->put(route('packages.update', $package), ['name' => 'x', 'is_active' => true])->assertForbidden();
    $this->actingAs($user)->delete(route('packages.destroy', $package))->assertForbidden();
    $this->actingAs($user)->put(route('packages.limits.update', [$package, 'web_domains']), ['limit_value' => 5])->assertForbidden();
});

test('the index page lists every package', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    Package::factory()->create(['name' => 'acme-plan']);

    $this->actingAs($admin)
        ->get(route('packages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('packages/index')
            ->has('packages', 1)
            ->where('packages.0.name', 'acme-plan')
        );
});

test('storing a package redirects to its edit page', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $response = $this->actingAs($admin)->post(route('packages.store'), [
        'name' => 'acme-plan',
        'description' => 'A real plan',
        'is_active' => true,
    ]);

    $package = Package::where('name', 'acme-plan')->firstOrFail();
    $response->assertRedirect(route('packages.edit', $package));
    expect($package->is_active)->toBeTrue();
});

test('storing a package with a duplicate name fails validation', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    Package::factory()->create(['name' => 'acme-plan']);

    $this->actingAs($admin)
        ->post(route('packages.store'), ['name' => 'acme-plan', 'is_active' => true])
        ->assertSessionHasErrors('name');
});

test('the edit page presents every real quota-enforced resource type, reporting an unconfigured one as blocked', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->withLimit('web_domains', 5)->create();

    $this->actingAs($admin)
        ->get(route('packages.edit', $package))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('packages/edit')
            ->has('package.limits', 7)
            ->where('package.limits.0.resource_type', 'web_domains')
            ->where('package.limits.0.configured', true)
            ->where('package.limits.0.limit_value', 5)
            ->where('package.limits.1.configured', false)
            ->where('package.limits.1.limit_value', null)
        );
});

test('updating a package redirects back to its edit page', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create(['name' => 'old-name', 'is_active' => true]);

    $this->actingAs($admin)
        ->put(route('packages.update', $package), ['name' => 'new-name', 'is_active' => false])
        ->assertRedirect(route('packages.edit', $package));

    expect($package->refresh()->name)->toBe('new-name')
        ->and($package->is_active)->toBeFalse();
});

test('unchecking is_active on update actually turns it off, not silently kept on', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create(['is_active' => true]);

    // A real unchecked HTML checkbox omits the field entirely, exactly like this request.
    $this->actingAs($admin)->put(route('packages.update', $package), ['name' => $package->name]);

    expect($package->refresh()->is_active)->toBeFalse();
});

test('updating a quota limit persists it and reports it as configured afterward', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create();

    $this->actingAs($admin)
        ->put(route('packages.limits.update', [$package, 'cron_jobs']), ['limit_value' => 10])
        ->assertRedirect(route('packages.edit', $package));

    $limit = PackageLimit::where('package_id', $package->id)->where('resource_type', 'cron_jobs')->firstOrFail();
    expect($limit->limit_value)->toBe(10);
});

test('an unrecognized resource type on the quota route 404s', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create();

    $this->actingAs($admin)
        ->put(route('packages.limits.update', [$package, 'not_a_real_resource']), ['limit_value' => 10])
        ->assertNotFound();
});

test('deleting a package with no accounts on it redirects to the index', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create();
    $id = $package->id;

    $this->actingAs($admin)
        ->delete(route('packages.destroy', $package))
        ->assertRedirect(route('packages.index'));

    expect(Package::find($id))->toBeNull();
});

test('deleting a package with an account on it fails validation instead of a raw database error', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create();
    Account::factory()->for($package)->create();

    $this->actingAs($admin)
        ->delete(route('packages.destroy', $package))
        ->assertSessionHasErrors('package');
});
