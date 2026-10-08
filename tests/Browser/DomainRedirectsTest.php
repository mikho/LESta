<?php

use App\Models\AccountNodeIdentity;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;

test('an owner adds and removes a redirect from the domain page', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $this->actingAs($owner);

    $page = visit(route('domains.edit', $webDomain));

    $page->assertSee('Redirects')
        ->fill('source', '/old-page')
        ->fill('target', 'javascript:alert(1)')
        ->click('[data-test="add-redirect-button"]')
        ->assertSee('Use a full address such as')
        ->fill('target', 'https://example.com/new')
        ->click('[data-test="add-redirect-button"]')
        ->assertSee('/old-page')
        ->assertSee('https://example.com/new')
        ->click('[aria-label="Remove redirect from /old-page"]')
        ->assertDontSee('https://example.com/new')
        ->assertNoJavaScriptErrors();
});
