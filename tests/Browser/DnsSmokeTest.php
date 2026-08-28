<?php

use App\Models\Account;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;

test('the dns pages render with no JavaScript errors', function () {
    $package = Package::factory()->withLimit('dns_zones', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();

    $this->actingAs($owner);

    $pages = visit([
        '/dns',
        '/dns/create',
        "/dns/{$dnsZone->uuid}/edit",
    ]);

    $pages->assertNoJavaScriptErrors();
});
