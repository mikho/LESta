<?php

use App\Models\Account;
use App\Models\Membership;

/**
 * The sidebar prefetches pages while the visitor is still the admin. Those cached copies carry the
 * admin's menu and no impersonation banner, so the first click after impersonating used to show
 * the admin's version of the page until the next navigation. Starting impersonation must drop
 * them.
 */
test('the first page opened after impersonating shows the impersonated user, not the admin', function () {
    $account = Account::factory()->create(['name' => 'acme-hosting']);
    Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin);

    $page = visit(route('accounts.show', $account));

    $page->assertSee('acme-hosting')
        // Hover the sidebar's Documentation link long enough for Inertia to prefetch it as the admin.
        ->hover('a[href$="/docs"]')
        ->wait(1)
        ->click('[data-test="impersonate-member-button"]')
        ->fill('reason', 'support ticket #321')
        ->click('[data-test="confirm-impersonate-button"]')
        ->assertSee($admin->name)
        ->click('Documentation')
        ->assertPathContains('/docs')
        // The impersonated customer's own view: the banner is shown, and the admin-only
        // Accounts link and the admin guides are not.
        ->assertPresent('[data-test="impersonation-banner"]')
        ->assertDontSeeLink('Accounts')
        ->assertDontSee('Installation Guide')
        ->assertNoJavaScriptErrors();
});
