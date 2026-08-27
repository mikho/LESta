<?php

use App\Actions\Domains\DeleteWebDomain;
use App\Actions\Domains\UpdateWebDomain;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia as Assert;

test('a member of another account cannot view a foreign web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    $this->actingAs($stranger)->get(route('domains.edit', $webDomain))->assertForbidden();
});

test('a member of another account cannot update a foreign web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($stranger, $webDomain, ['domain' => 'hijacked.example.com']);
})->throws(AuthorizationException::class);

test('a member of another account cannot delete a foreign web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(DeleteWebDomain::class)->handle($stranger, $webDomain);
})->throws(AuthorizationException::class);

test('the index page only ever lists the acting account own web domains', function () {
    $accountA = Account::factory()->create();
    $ownerA = Membership::factory()->for($accountA)->owner()->create()->user;
    WebDomain::factory()->for($accountA)->create(['domain' => 'a.example.com']);

    $accountB = Account::factory()->create();
    Membership::factory()->for($accountB)->owner()->create();
    WebDomain::factory()->for($accountB)->create(['domain' => 'b.example.com']);

    $this->actingAs($ownerA)
        ->get(route('domains.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('domains/index')
            ->has('webDomains.data', 1)
            ->where('webDomains.data.0.domain', 'a.example.com')
        );
});
