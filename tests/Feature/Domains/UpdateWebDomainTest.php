<?php

use App\Actions\Domains\UpdateWebDomain;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
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

test('updating with an empty aliases list removes all existing aliases', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    WebDomainAlias::factory()->for($webDomain)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    app(UpdateWebDomain::class)->handle($owner, $webDomain, ['domain' => $webDomain->domain, 'aliases' => []]);

    expect($webDomain->aliases()->count())->toBe(0);
});
