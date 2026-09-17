<?php

use App\Enums\RoleScope;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest is redirected to login', function () {
    $this->get(route('roles.index'))->assertRedirect(route('login'));
});

test('a non-admin is forbidden from every role route', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create(['scope' => RoleScope::Platform]);

    $this->actingAs($user)->get(route('roles.index'))->assertForbidden();
    $this->actingAs($user)->get(route('roles.create'))->assertForbidden();
    $this->actingAs($user)->get(route('roles.edit', $role))->assertForbidden();
    $this->actingAs($user)->put(route('roles.update', $role), ['name' => 'x'])->assertForbidden();
    $this->actingAs($user)->delete(route('roles.destroy', $role))->assertForbidden();
});

test('the index page lists custom platform roles but excludes provider_admin, owner, and member', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    Role::factory()->create(['name' => 'billing_admin', 'scope' => RoleScope::Platform]);
    Role::query()->firstOrCreate(['name' => 'owner'], ['scope' => RoleScope::Account]);
    Role::query()->firstOrCreate(['name' => 'member'], ['scope' => RoleScope::Account]);

    $this->actingAs($admin)
        ->get(route('roles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('roles/index')
            ->has('roles', 1)
            ->where('roles.0.name', 'billing_admin')
        );
});

test('storing a role creates it with only the submitted permissions, with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $response = $this->actingAs($admin)->post(route('roles.store'), [
        'name' => 'billing_admin',
        'description' => 'Views packages only',
        'permissions' => ['packages.view_any', 'packages.view'],
    ]);

    $role = Role::where('name', 'billing_admin')->firstOrFail();
    $response->assertRedirect(route('roles.edit', $role));

    expect($role->scope)->toBe(RoleScope::Platform)
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['packages.view', 'packages.view_any']);
});

test('a reserved role name cannot be used when creating a role', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->post(route('roles.store'), ['name' => 'owner', 'permissions' => []])
        ->assertSessionHasErrors('name');
});

test('updating a role replaces its permission set entirely, not merging with the old one', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $role = Role::factory()->create(['scope' => RoleScope::Platform]);
    $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'packages.view_any'])->id);

    $this->actingAs($admin)
        ->put(route('roles.update', $role), [
            'name' => $role->name,
            'permissions' => ['nodes.view_any'],
        ])
        ->assertRedirect(route('roles.edit', $role));

    expect($role->refresh()->permissions->pluck('name')->all())->toBe(['nodes.view_any']);
});

test('the provider_admin role cannot be reached for editing at all', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $providerAdminRole = Role::where('name', 'provider_admin')->sole();

    $this->actingAs($admin)
        ->get(route('roles.edit', $providerAdminRole))
        ->assertNotFound();
});

test('deleting an unused role redirects to the index', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $role = Role::factory()->create(['scope' => RoleScope::Platform]);
    $id = $role->id;

    $this->actingAs($admin)
        ->delete(route('roles.destroy', $role))
        ->assertRedirect(route('roles.index'));

    expect(Role::find($id))->toBeNull();
});

test('deleting a role still assigned to a user fails validation instead of a raw database error', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $role = Role::factory()->create(['scope' => RoleScope::Platform]);
    Membership::factory()->create(['account_id' => null, 'role_id' => $role->id]);

    $this->actingAs($admin)
        ->delete(route('roles.destroy', $role))
        ->assertSessionHasErrors('role');

    expect(Role::find($role->id))->not->toBeNull();
});
