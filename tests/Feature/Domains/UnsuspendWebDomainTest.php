<?php

use App\Actions\Domains\UnsuspendWebDomain;
use App\Enums\ProvisioningVerb;
use App\Enums\WebServer;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can unsuspend their web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UnsuspendWebDomain::class)->handle($owner, $webDomain);

    $webDomain->refresh();

    expect($webDomain->isSuspended())->toBeFalse()
        ->and($webDomain->suspension_source)->toBeNull()
        ->and(AuditEvent::where('action', 'web_domain.unsuspended')->where('auditable_id', $webDomain->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Unsuspend)
        ->first();

    expect($operation)->not->toBeNull();
});

test('unsuspending a web domain with the default web_server still produces exactly one nginx provisioning operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UnsuspendWebDomain::class)->handle($owner, $webDomain);

    $operations = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Unsuspend)
        ->get();

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->capability)->toBe('web.nginx.v1');
});

test('unsuspending a web domain configured for apache on a both-profile node unsuspends apache then nginx', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    $webDomain = WebDomain::factory()->suspended()->for($node)->create(['web_server' => WebServer::Apache]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UnsuspendWebDomain::class)->handle($owner, $webDomain);

    $operations = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('operation', ProvisioningVerb::Unsuspend)
        ->orderBy('id')
        ->get();

    expect($operations)->toHaveCount(2)
        ->and($operations->get(0)->capability)->toBe('web.apache.v1')
        ->and($operations->get(1)->capability)->toBe('web.nginx.v1');
});

test('unsuspending an already-active web domain is a no-op', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UnsuspendWebDomain::class)->handle($owner, $webDomain);

    expect(AuditEvent::where('action', 'web_domain.unsuspended')->where('auditable_id', $webDomain->id)->count())->toBe(0);
});

test('a non-owner member cannot unsuspend a web domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->suspended()->for($node)->create();
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;

    app(UnsuspendWebDomain::class)->handle($member, $webDomain);
})->throws(AuthorizationException::class);
