<?php

use App\Models\AccountNodeIdentity;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;

test('an owner protects a folder, adds a login and removes the protection from the domain page', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $this->actingAs($owner);

    $page = visit(route('domains.edit', $webDomain));

    $page->assertSee('Password-protected folders')
        ->fill('path', '/members')
        ->fill('username', 'alice')
        ->fill('password', 'short')
        ->click('[data-test="add-protected-dir-button"]')
        ->assertSee('must be at least 8 characters')
        ->fill('password', 'a-good-long-password')
        ->click('[data-test="add-protected-dir-button"]')
        ->assertSee('/members/')
        ->assertSee('alice')
        ->assertDontSee('a-good-long-password')
        ->click('[aria-label="Remove the password from /members"]')
        ->assertDontSee('Login box: Members area')
        ->assertNoJavaScriptErrors();
});
