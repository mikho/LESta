<?php

use App\Models\AccountNodeIdentity;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;

test('an owner adds and removes an IP rule from the access rules page', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $this->actingAs($owner);

    $page = visit(route('ip-rules.index'));

    $page->assertSee('IP access rules')
        ->assertSee('No rules yet. Every visitor is allowed.')
        ->fill('cidr', 'not-an-address')
        ->click('[data-test="add-ip-rule-button"]')
        ->assertSee('Enter an IP address or a range')
        ->fill('cidr', '203.0.113.9/24')
        ->fill('note', 'scanner')
        ->click('[data-test="add-ip-rule-button"]')
        ->assertSee('203.0.113.0/24')
        ->assertSee('scanner')
        ->assertDontSee('No rules yet')
        ->click('[aria-label="Remove deny rule for 203.0.113.0/24"]')
        ->assertSee('No rules yet. Every visitor is allowed.')
        ->assertNoJavaScriptErrors();
});
