<?php

use App\Actions\Domains\CreateWebDomain;
use App\Enums\IpAllocationStatus;
use App\Enums\ProvisioningStatus;
use App\Enums\WebServer;
use App\Exceptions\NoIpAllocationAvailableException;
use App\Exceptions\NoWebCapableNodeAvailableException;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can create a web domain and it is provisioned after commit', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $allocation = IpAllocation::factory()->for($node)->create();

    $webDomain = app(CreateWebDomain::class)->handle($owner, $account, [
        'domain' => 'Example.COM',
        'aliases' => ['www.example.com'],
    ]);

    expect($webDomain->domain)->toBe('example.com')
        ->and($webDomain->account_id)->toBe($account->id)
        ->and($webDomain->node_id)->toBe($node->id)
        ->and($webDomain->ip_allocation_id)->toBe($allocation->id)
        ->and($webDomain->desired_state_version)->toBe(1)
        ->and($webDomain->aliases()->pluck('alias')->all())->toBe(['www.example.com'])
        ->and(AuditEvent::where('action', 'web_domain.created')->where('auditable_id', $webDomain->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_type', $webDomain->getMorphClass())
        ->where('provisionable_id', $webDomain->id)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->capability)->toBe('web.nginx.v1');
});

test('a non-owner member cannot create a web domain', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(CreateWebDomain::class)->handle($member, $account, ['domain' => 'example.com']);
})->throws(AuthorizationException::class);

test('a package with no web_domains limit row blocks creation entirely', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(ResourceQuotaExceededException::class);

test('a package with an explicit limit already reached blocks creation', function () {
    $package = Package::factory()->withLimit('web_domains', 1)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'first.example.com']);

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'second.example.com']);
})->throws(ResourceQuotaExceededException::class);

test('creation fails when no web-capable node is available', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(NoWebCapableNodeAvailableException::class);

test('creation fails when no ip allocation is available on the resolved node', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(NoIpAllocationAvailableException::class);

test('a rolled-back creation leaves no partial rows', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    try {
        app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
    } catch (NoIpAllocationAvailableException) {
        // expected
    }

    expect(WebDomain::count())->toBe(0)
        ->and(AuditEvent::where('action', 'web_domain.created')->count())->toBe(0)
        ->and(ProvisioningOperation::count())->toBe(0);
});

test('creating a web domain with the default web_server produces exactly one nginx provisioning operation', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    IpAllocation::factory()->for($node)->create();

    $webDomain = app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'example.com']);

    $operations = ProvisioningOperation::where('provisionable_id', $webDomain->id)->get();

    expect($webDomain->web_server)->toBe(WebServer::Nginx)
        ->and($operations)->toHaveCount(1)
        ->and($operations->first()->capability)->toBe('web.nginx.v1');
});

test('creating a web domain with web_server apache on a both-profile node provisions apache then nginx', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    IpAllocation::factory()->for($node)->create();

    $webDomain = app(CreateWebDomain::class)->handle($owner, $account, [
        'domain' => 'example.com',
        'web_server' => 'apache',
    ]);

    $operations = ProvisioningOperation::where('provisionable_id', $webDomain->id)->orderBy('id')->get();

    expect($webDomain->web_server)->toBe(WebServer::Apache)
        ->and($operations)->toHaveCount(2)
        ->and($operations->get(0)->capability)->toBe('web.apache.v1')
        ->and($operations->get(1)->capability)->toBe('web.nginx.v1')
        ->and($operations->get(0)->payload['web_template'])->toBe('default')
        ->and($operations->get(1)->payload['web_template'])->toBe('apache-proxy');
});

test('creating a web domain with web_server apache fails when the resolved node has no active apache capability', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();

    app(CreateWebDomain::class)->handle($owner, $account, [
        'domain' => 'example.com',
        'web_server' => 'apache',
    ]);
})->throws(NoWebCapableNodeAvailableException::class);

test('a dedicated ip allocation for the account is preferred over a shared one', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create(['status' => IpAllocationStatus::Shared]);
    $dedicated = IpAllocation::factory()->for($node)->for($account)->create(['status' => IpAllocationStatus::Dedicated]);

    $webDomain = app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'example.com']);

    expect($webDomain->ip_allocation_id)->toBe($dedicated->id);
});
