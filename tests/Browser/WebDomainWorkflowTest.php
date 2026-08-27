<?php

use App\Models\Account;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\WebDomain;

test('a full list, create, edit, suspend, unsuspend, delete workflow works in a real browser', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();

    $this->actingAs($owner);

    $page = visit('/domains');

    $page->assertNoJavaScriptErrors()
        ->assertSee('Domains')
        ->assertSee('No domains yet.')
        ->click('Add domain')
        ->assertNoJavaScriptErrors()
        ->fill('domain', 'browser-test.example.com')
        ->click('Create domain')
        ->assertNoJavaScriptErrors()
        ->assertSee('browser-test.example.com');

    $webDomain = WebDomain::where('domain', 'browser-test.example.com')->sole();

    $page->click('Edit')
        ->assertNoJavaScriptErrors()
        ->assertSee('Edit domain')
        ->click('Suspend')
        ->assertNoJavaScriptErrors();

    expect($webDomain->refresh()->isSuspended())->toBeTrue();

    $page->click('Unsuspend')
        ->assertNoJavaScriptErrors();

    expect($webDomain->refresh()->isSuspended())->toBeFalse();

    $page->click('[data-test="delete-domain-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="confirm-delete-domain-button"]')
        ->assertNoJavaScriptErrors();

    expect(WebDomain::find($webDomain->id))->toBeNull();
});
