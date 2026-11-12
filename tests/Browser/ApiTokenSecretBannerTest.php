<?php

use App\Models\Membership;

test('a newly created API token is shown once with a field and a copy button', function () {
    $owner = Membership::factory()->owner()->create()->user;

    $this->actingAs($owner);

    $page = visit(route('api-tokens.index'));

    $page->fill('password', 'password')
        ->click('[data-test="confirm-password-button"]')
        ->fill('name', 'Deploy script')
        ->click('Read')
        ->click('Create token')
        ->assertSee('Copy your new token now')
        ->assertPresent('[aria-label="New API token"]')
        ->assertPresent('[aria-label="Copy token"]')
        ->assertNoJavaScriptErrors();
});
