<?php

use App\Actions\Domains\SuspendWebDomain;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Enums\WebServer;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can suspend their web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(SuspendWebDomain::class)->handle($owner, $webDomain);

    $webDomain->refresh();

    expect($webDomain->isSuspended())->toBeTrue()
        ->and($webDomain->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($webDomain->desired_state_version)->toBe(2)
        ->and(AuditEvent::where('action', 'web_domain.suspended')->where('auditable_id', $webDomain->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Suspend)
        ->first();

    expect($operation)->not->toBeNull();
});

test('suspending a web domain with the default web_server still produces exactly one nginx provisioning operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(SuspendWebDomain::class)->handle($owner, $webDomain);

    $operations = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Suspend)
        ->get();

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->capability)->toBe('web.nginx.v1');
});

test('suspending a web domain configured for apache on a both-profile node suspends apache then nginx', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['web_server' => WebServer::Apache]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(SuspendWebDomain::class)->handle($owner, $webDomain);

    $operations = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Suspend)
        ->orderBy('id')
        ->get();

    expect($operations)->toHaveCount(2)
        ->and($operations->get(0)->capability)->toBe('web.apache.v1')
        ->and($operations->get(1)->capability)->toBe('web.nginx.v1');
});

test('duplicate suspend submissions do not create a second audit row', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(SuspendWebDomain::class)->handle($owner, $webDomain);
    app(SuspendWebDomain::class)->handle($owner, $webDomain);

    expect(AuditEvent::where('action', 'web_domain.suspended')->where('auditable_id', $webDomain->id)->count())->toBe(1);
});

test('a non-owner member cannot suspend a web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;

    app(SuspendWebDomain::class)->handle($member, $webDomain);
})->throws(AuthorizationException::class);

test('a cascade suspension records the cascade source', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(SuspendWebDomain::class)->handle($owner, $webDomain, SuspensionSource::Cascade);

    expect($webDomain->refresh()->suspension_source)->toBe(SuspensionSource::Cascade);
});
