<?php

use App\Models\AccountNodeIdentity;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;

test('an owner can switch a domain to detect only and exclude rules from the edit page', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'shop.example.com']);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $this->actingAs($owner);

    $page = visit(route('domains.edit', $webDomain));

    $page->assertSee('Web application firewall')
        ->click('#waf_mode')
        ->click('[role="option"]:has-text("Detect only")')
        ->fill('waf_excluded_rules', '942100, 920350')
        ->click('#hotlink_protection')
        ->fill('hotlink_allowed_hosts', 'partner.example')
        ->click('[data-test="update-domain-button"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $webDomain->refresh();

    expect($webDomain->waf_mode)->toBe('detect')
        ->and($webDomain->waf_excluded_rules)->toBe([942100, 920350])
        ->and($webDomain->hotlink_protection)->toBeTrue()
        ->and($webDomain->hotlink_allowed_hosts)->toBe(['partner.example']);
});
