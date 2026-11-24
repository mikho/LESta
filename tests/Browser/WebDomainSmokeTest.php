<?php

use App\Models\Account;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\User;
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

/**
 * @return array{0: User, 1: WebDomain}
 */
function ownerWithOneDomain(string $sslMode): array
{
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create([
        'domain' => 'shop.example.com',
        'ssl_mode' => $sslMode,
    ]);

    return [$owner, $webDomain];
}

test('each domain row has open site, files and logs shortcuts beside manage', function () {
    [$owner, $webDomain] = ownerWithOneDomain('lets_encrypt');

    $this->actingAs($owner);

    visit('/domains')
        ->assertNoJavaScriptErrors()
        ->assertAttribute('[aria-label="Open site for shop.example.com"]', 'href', 'https://shop.example.com')
        ->assertAttribute('[aria-label="Open site for shop.example.com"]', 'target', '_blank')
        ->assertVisible('[aria-label="Files for shop.example.com"]')
        ->click('[aria-label="Logs for shop.example.com"]')
        ->assertNoJavaScriptErrors()
        ->assertPathIs("/domains/{$webDomain->uuid}/logs")
        ->assertSee('Logs and traffic');
});

test('a domain without https opens over http', function () {
    [$owner] = ownerWithOneDomain('none');

    $this->actingAs($owner);

    visit('/domains')
        ->assertAttribute('[aria-label="Open site for shop.example.com"]', 'href', 'http://shop.example.com');
});

test('on a phone the shortcuts move into the more menu', function () {
    [$owner] = ownerWithOneDomain('lets_encrypt');

    $this->actingAs($owner);

    visit('/domains')->on()->mobile()
        ->assertNoJavaScriptErrors()
        ->assertMissing('[aria-label="Files for shop.example.com"]')
        ->click('[aria-label="More actions for shop.example.com"]')
        ->assertVisible('[role="menuitem"][aria-label="Open site for shop.example.com"]')
        ->assertVisible('[role="menuitem"][aria-label="Files for shop.example.com"]')
        ->assertVisible('[role="menuitem"][aria-label="Logs for shop.example.com"]')
        ->assertSee('Suspend')
        ->assertSee('Delete');
});
