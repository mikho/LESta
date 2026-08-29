<?php

use App\Actions\Domains\UpdateWebDomain;
use App\Enums\ProvisioningStatus;
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

test('an owner can update a web domain, bumping the desired state version and replacing aliases', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'old.example.com']);
    WebDomainAlias::factory()->for($webDomain)->create(['alias' => 'stale.example.com']);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $updated = app(UpdateWebDomain::class)->handle($owner, $webDomain, [
        'domain' => 'New.Example.com',
        'aliases' => ['fresh.example.com'],
    ]);

    expect($updated->domain)->toBe('new.example.com')
        ->and($updated->desired_state_version)->toBe(2)
        ->and($updated->aliases()->pluck('alias')->all())->toBe(['fresh.example.com'])
        ->and(AuditEvent::where('action', 'web_domain.updated')->where('auditable_id', $webDomain->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->desired_state_version)->toBe(2);
});

test('a non-owner member cannot update a web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;

    app(UpdateWebDomain::class)->handle($member, $webDomain, ['domain' => 'new.example.com']);
})->throws(AuthorizationException::class);

test('updating a web domain with the default web_server still produces exactly one nginx provisioning operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain]);

    $operations = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Update)
        ->get();

    expect($webDomain->refresh()->web_server)->toBe(WebServer::Nginx)
        ->and($operations)->toHaveCount(1)
        ->and($operations->first()->capability)->toBe('web.nginx.v1');
});

test('updating a web domain to web_server apache on a both-profile node provisions apache then nginx', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'old.example.com']);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, [
        'domain' => 'old.example.com',
        'web_server' => 'apache',
    ]);

    $operations = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Update)
        ->orderBy('id')
        ->get();

    expect($webDomain->refresh()->web_server)->toBe(WebServer::Apache)
        ->and($operations)->toHaveCount(2)
        ->and($operations->get(0)->capability)->toBe('web.apache.v1')
        ->and($operations->get(1)->capability)->toBe('web.nginx.v1')
        ->and($operations->get(0)->payload['web_template'])->toBe('default')
        ->and($operations->get(1)->payload['web_template'])->toBe('apache-proxy');
});

test('updating with an empty aliases list removes all existing aliases', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    WebDomainAlias::factory()->for($webDomain)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain, 'aliases' => []]);

    expect($webDomain->aliases()->count())->toBe(0);
});
