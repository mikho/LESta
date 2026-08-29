<?php

use App\Actions\Domains\DeleteWebDomain;
use App\Enums\ProvisioningVerb;
use App\Enums\WebServer;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;
use Illuminate\Auth\Access\AuthorizationException;

test('deleting a suspended web domain force-unsuspends then deletes as one action', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;
    $id = $webDomain->id;

    app(DeleteWebDomain::class)->handle($owner, $webDomain);

    expect(WebDomain::find($id))->toBeNull()
        ->and(AuditEvent::where('action', 'web_domain.deleted')->where('auditable_id', $id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->first();

    expect($operation)->not->toBeNull();
});

test('deleting a web domain with the default web_server still produces exactly one nginx provisioning operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;
    $id = $webDomain->id;

    app(DeleteWebDomain::class)->handle($owner, $webDomain);

    $operations = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->get();

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->capability)->toBe('web.nginx.v1');
});

test('deleting a web domain configured for apache on a both-profile node deletes apache then nginx', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['web_server' => WebServer::Apache]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;
    $id = $webDomain->id;

    app(DeleteWebDomain::class)->handle($owner, $webDomain);

    $operations = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->orderBy('id')
        ->get();

    expect($operations)->toHaveCount(2)
        ->and($operations->get(0)->capability)->toBe('web.apache.v1')
        ->and($operations->get(1)->capability)->toBe('web.nginx.v1');
});

test('a non-owner member cannot delete a web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;

    app(DeleteWebDomain::class)->handle($member, $webDomain);
})->throws(AuthorizationException::class);

test('deleting a web domain removes its aliases via the cascade FK', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->withAlias()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;
    $id = $webDomain->id;

    app(DeleteWebDomain::class)->handle($owner, $webDomain);

    expect(WebDomainAlias::where('web_domain_id', $id)->count())->toBe(0);
});
