<?php

use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use App\Models\WebDomainRedirect;

function domainForRedirects(): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    return [$webDomain, $owner];
}

test('an owner adds a redirect, the vhost is re-rendered with it, and it is audited', function () {
    [$webDomain, $owner] = domainForRedirects();

    $this->actingAs($owner)
        ->post(route('domains.redirects.store', $webDomain), ['source' => 'old-page', 'target' => 'https://example.com/new?x=1', 'status' => 302, 'prefix' => '1'])
        ->assertSessionHasNoErrors();

    $redirect = $webDomain->redirects()->sole();

    expect($redirect->source)->toBe('/old-page')
        ->and($redirect->status)->toBe(302)
        ->and($redirect->prefix)->toBeTrue()
        ->and(AuditEvent::where('action', 'web_domain.redirect_created')->count())->toBe(1);

    $operation = ProvisioningOperation::where('capability', 'web.nginx.v1')->latest('id')->first();

    expect($operation->payload['redirects'])->toBe([['source' => '/old-page', 'target' => 'https://example.com/new?x=1', 'status' => 302, 'prefix' => true]]);
});

test('removing a redirect re-renders the domain without it', function () {
    [$webDomain, $owner] = domainForRedirects();
    $redirect = WebDomainRedirect::factory()->for($webDomain)->create();

    $this->actingAs($owner)->delete(route('domains.redirects.destroy', [$webDomain, $redirect]))->assertRedirect();

    expect($webDomain->redirects()->count())->toBe(0)
        ->and(ProvisioningOperation::where('capability', 'web.nginx.v1')->latest('id')->first()->payload['redirects'])->toBe([]);
});

test('unsafe or invalid input is refused', function (array $input) {
    [$webDomain, $owner] = domainForRedirects();

    $this->actingAs($owner)
        ->post(route('domains.redirects.store', $webDomain), $input + ['source' => '/a', 'target' => '/b', 'status' => 301])
        ->assertSessionHasErrors();

    expect($webDomain->redirects()->count())->toBe(0);
})->with([
    'config injection in the path' => [['source' => '/a; return 200']],
    'config injection in the target' => [['target' => "/x';}server{"]],
    'nginx variable in the target' => [['target' => '/x$host']],
    'javascript target' => [['target' => 'javascript:alert(1)']],
    'path traversal' => [['source' => '/a/../b']],
    'double slash' => [['source' => '/a//b']],
    'unsupported status' => [['status' => 200]],
    'a redirect to itself' => [['source' => '/same', 'target' => '/same']],
]);

test('a path can only have one redirect, and a domain is limited', function () {
    [$webDomain, $owner] = domainForRedirects();
    WebDomainRedirect::factory()->for($webDomain)->create(['source' => '/taken']);

    $this->actingAs($owner)->post(route('domains.redirects.store', $webDomain), ['source' => '/taken', 'target' => '/b', 'status' => 301])->assertSessionHasErrors('source');

    for ($i = 1; $i < WebDomainRedirect::MAX_PER_DOMAIN; $i++) {
        WebDomainRedirect::factory()->for($webDomain)->create(['source' => "/p{$i}"]);
    }

    $this->actingAs($owner)->post(route('domains.redirects.store', $webDomain), ['source' => '/one-too-many', 'target' => '/b', 'status' => 301])->assertSessionHasErrors('source');
});

test('only an owner of the domain can change its redirects', function () {
    [$webDomain, $owner] = domainForRedirects();
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;
    [, $stranger] = domainForRedirects();
    $redirect = WebDomainRedirect::factory()->for($webDomain)->create();

    $this->actingAs($member)->post(route('domains.redirects.store', $webDomain), ['source' => '/a', 'target' => '/b', 'status' => 301])->assertForbidden();
    $this->actingAs($stranger)->post(route('domains.redirects.store', $webDomain), ['source' => '/a', 'target' => '/b', 'status' => 301])->assertForbidden();
    $this->actingAs($stranger)->delete(route('domains.redirects.destroy', [$webDomain, $redirect]))->assertForbidden();

    expect($webDomain->redirects()->count())->toBe(1);
});

test('a redirect of another domain cannot be removed through this one', function () {
    [$webDomain, $owner] = domainForRedirects();
    $other = WebDomain::factory()->for($webDomain->account)->for($webDomain->node)->create();
    $foreign = WebDomainRedirect::factory()->for($other)->create();

    $this->actingAs($owner)->delete(route('domains.redirects.destroy', [$webDomain, $foreign]))->assertNotFound();

    expect($other->redirects()->count())->toBe(1);
});

test('the edit page lists the domain redirects', function () {
    [$webDomain, $owner] = domainForRedirects();
    WebDomainRedirect::factory()->for($webDomain)->create(['source' => '/listed', 'target' => '/there']);

    $this->actingAs($owner)->get(route('domains.edit', $webDomain))
        ->assertInertia(fn ($page) => $page->has('redirects', 1)->where('redirects.0.source', '/listed'));
});
