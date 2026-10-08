<?php

use App\Actions\Domains\UpdateWebDomain;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Enums\WebServer;
use App\Exceptions\NoPhpCapableNodeAvailableException;
use App\Models\AccountNodeIdentity;
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
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
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
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;

    app(UpdateWebDomain::class)->handle($member, $webDomain, ['domain' => 'new.example.com']);
})->throws(AuthorizationException::class);

test('updating a web domain with the default web_server still produces exactly one nginx provisioning operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
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
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
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

test('turning php_version on for the first time records a web.php-fpm.v1 create operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.php-fpm.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => null]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain, 'php_version' => '8.3']);

    $operation = ProvisioningOperation::where('capability', 'web.php-fpm.v1')
        ->where('provisionable_id', $webDomain->id)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->operation)->toBe(ProvisioningVerb::Create)
        ->and($operation->payload['php_version'])->toBe('8.3');
});

test('changing php_version while already on records a web.php-fpm.v1 update operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.php-fpm.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => '8.1']);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain, 'php_version' => '8.4']);

    $operation = ProvisioningOperation::where('capability', 'web.php-fpm.v1')
        ->where('provisionable_id', $webDomain->id)
        ->first();

    expect($operation->operation)->toBe(ProvisioningVerb::Update)
        ->and($operation->payload['php_version'])->toBe('8.4');
});

test('turning php_version off records a web.php-fpm.v1 delete operation carrying the previous version', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.php-fpm.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => '8.2']);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain, 'php_version' => null]);

    $operation = ProvisioningOperation::where('capability', 'web.php-fpm.v1')
        ->where('provisionable_id', $webDomain->id)
        ->first();

    expect($webDomain->refresh()->php_version)->toBeNull()
        ->and($operation->operation)->toBe(ProvisioningVerb::Delete)
        ->and($operation->payload['php_version'])->toBe('8.2');
});

test('turning php_version on fails when the resolved node has no active web.php-fpm.v1 capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => null]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain, 'php_version' => '8.3']);
})->throws(NoPhpCapableNodeAvailableException::class);

test('leaving php_version off the whole time records no web.php-fpm.v1 operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => null]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain]);

    expect(ProvisioningOperation::where('capability', 'web.php-fpm.v1')
        ->where('provisionable_id', $webDomain->id)
        ->exists())->toBeFalse();
});

test('updating with an empty aliases list removes all existing aliases', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    WebDomainAlias::factory()->for($webDomain)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain, 'aliases' => []]);

    expect($webDomain->aliases()->count())->toBe(0);
});

test('an owner can turn the WAF on and exclude rules, and the nginx update carries them', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'waf.example.com']);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->put(route('domains.update', $webDomain), ['domain' => 'waf.example.com', 'waf_mode' => 'detect', 'waf_excluded_rules' => '942100, 920350'])
        ->assertSessionHasNoErrors();

    $webDomain->refresh();

    expect($webDomain->waf_mode)->toBe('detect')
        ->and($webDomain->waf_excluded_rules)->toBe([942100, 920350]);

    $operation = ProvisioningOperation::where('provisionable_id', $webDomain->id)->where('operation', ProvisioningVerb::Update)->latest('id')->first();

    expect($operation->payload)->toMatchArray(['waf_mode' => 'detect', 'waf_excluded_rules' => [942100, 920350]]);
});

test('the WAF mode and rule ids are validated', function (array $input, string $field) {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'waf.example.com']);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->put(route('domains.update', $webDomain), ['domain' => 'waf.example.com'] + $input)
        ->assertSessionHasErrors($field);
})->with([
    'unknown mode' => [['waf_mode' => 'paranoid'], 'waf_mode'],
    'text instead of an id' => [['waf_excluded_rules' => '942100; SecRuleEngine Off'], 'waf_excluded_rules.1'],
    'id out of range' => [['waf_excluded_rules' => '0'], 'waf_excluded_rules.0'],
]);
