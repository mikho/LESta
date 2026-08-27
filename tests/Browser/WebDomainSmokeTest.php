<?php

use App\Models\Account;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\WebDomain;

test('the domains pages render with no JavaScript errors', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner);

    $pages = visit([
        '/domains',
        '/domains/create',
        "/domains/{$webDomain->uuid}/edit",
    ]);

    $pages->assertNoJavaScriptErrors();
});
