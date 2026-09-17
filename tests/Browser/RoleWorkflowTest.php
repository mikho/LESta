<?php

use App\Enums\RoleScope;
use App\Models\Membership;
use App\Models\Role;

test('a full create, edit, delete role workflow works in a real browser, proving the permission checkbox array actually submits', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin);

    $page = visit('/roles');

    $page->assertNoJavaScriptErrors()
        ->assertSee('Roles')
        ->assertSee('No custom roles yet.')
        ->click('Create role')
        ->assertNoJavaScriptErrors()
        ->fill('name', 'billing_admin')
        ->fill('description', 'Views packages only')
        // Radix's Checkbox renders as a <button role="checkbox">, not a native <input>, so
        // check()/uncheck() (which target a real native checkbox) can't find it -- a plain click
        // toggles it instead, exactly like a real user would. data-test, not the visible label
        // text, since "packages.view" is a text-substring of "packages.view_any" and a
        // text-based locator would be ambiguous between the two.
        ->click('[data-test="permission-checkbox-packages.view_any"]')
        ->click('[data-test="permission-checkbox-packages.view"]')
        ->click('[data-test="create-role-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('billing_admin');

    $role = Role::where('name', 'billing_admin')->sole();

    expect($role->scope)->toBe(RoleScope::Platform)
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['packages.view', 'packages.view_any']);

    // Uncheck one, leaving the other, proving the checkbox state round-trips correctly on edit
    // too (defaultChecked reflecting the real saved permission set).
    $page->click('[data-test="permission-checkbox-packages.view"]')
        ->click('[data-test="update-role-button"]')
        ->assertNoJavaScriptErrors();

    expect($role->refresh()->permissions->pluck('name')->all())->toBe(['packages.view_any']);

    $page->click('[data-test="delete-role-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="confirm-delete-role-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('No custom roles yet.');

    expect(Role::find($role->id))->toBeNull();
});
