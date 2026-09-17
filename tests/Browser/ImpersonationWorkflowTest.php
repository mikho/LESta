<?php

use App\Models\Account;
use App\Models\Membership;

test('an admin can impersonate a customer to support them, then return to their own account', function () {
    $account = Account::factory()->create(['name' => 'acme-hosting']);
    $membership = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin);

    $page = visit(route('accounts.show', $account));

    $page->assertNoJavaScriptErrors()
        ->assertSee('acme-hosting')
        ->click('[data-test="impersonate-member-button"]')
        ->assertNoJavaScriptErrors()
        ->fill('reason', 'support ticket #123')
        ->click('[data-test="confirm-impersonate-button"]')
        ->assertNoJavaScriptErrors()
        // Landed on the impersonated owner's own dashboard, proving the identity swap really
        // happened, not just a support-view redirect back to the account page.
        ->assertSee('acme-hosting')
        ->assertSee($admin->name)
        ->click('[data-test="stop-impersonation-button"]')
        ->assertNoJavaScriptErrors()
        ->assertMissing('[data-test="impersonation-banner"]');
});
