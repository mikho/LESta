<?php

use App\Actions\Provisioning\CompletesProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\NodeServiceAction;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use App\Services\Provisioning\ProvisioningResult;
use Inertia\Testing\AssertableInertia as Assert;

function nodeWithCapabilities(string ...$capabilities): Node
{
    $node = Node::factory()->create(['name' => 'node-a']);

    foreach ($capabilities as $capability) {
        NodeCapability::factory()->for($node)->create(['capability' => $capability]);
    }

    return $node;
}

test('an unused service is removed after the node name is typed', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = nodeWithCapabilities('web.nginx.v1', 'scheduler.account-cron.v1');

    $this->actingAs($admin)
        ->delete(route('nodes.installed-services.destroy', ['node' => $node, 'service' => 'cron']), ['confirm' => 'node-a'])
        ->assertRedirect();

    $action = NodeServiceAction::query()->sole();
    $operation = ProvisioningOperation::query()->where('provisionable_id', $action->id)->where('provisionable_type', $action->getMorphClass())->sole();

    expect($action->action)->toBe('uninstall')
        ->and($action->unit)->toBe('cron')
        ->and($operation->capability)->toBe('node.service-uninstall.v1')
        ->and($operation->payload)->toBe(['service' => 'cron', 'confirm_data_loss' => false])
        ->and(AuditEvent::where('action', 'node_service.uninstall')->exists())->toBeTrue();
});

test('the node name must be typed exactly', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = nodeWithCapabilities('scheduler.account-cron.v1');

    $this->actingAs($admin)
        ->delete(route('nodes.installed-services.destroy', ['node' => $node, 'service' => 'cron']), ['confirm' => 'node-b'])
        ->assertSessionHasErrors('confirm');

    expect(NodeServiceAction::query()->count())->toBe(0);
});

test('a service customers use or another service needs is refused', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = nodeWithCapabilities('web.nginx.v1', 'dns.bind9.v1', 'mail.smtp-imap.v1', 'database.tenant.v1');
    TenantDatabase::factory()->for($node)->create();

    foreach (['nginx', 'bind9', 'mariadb'] as $service) {
        $this->actingAs($admin)
            ->delete(route('nodes.installed-services.destroy', ['node' => $node, 'service' => $service]), ['confirm' => 'node-a'])
            ->assertSessionHasErrors('service');
    }

    expect(NodeServiceAction::query()->count())->toBe(0);
});

test('a stateful service is removed with its data moved aside', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = nodeWithCapabilities('database.tenant.v1');

    $this->actingAs($admin)
        ->delete(route('nodes.installed-services.destroy', ['node' => $node, 'service' => 'mariadb']), ['confirm' => 'node-a'])
        ->assertRedirect();

    expect(ProvisioningOperation::query()->sole()->payload)->toBe(['service' => 'mariadb', 'confirm_data_loss' => true]);
});

test('a tenant cannot remove services', function () {
    $owner = Membership::factory()->owner()->create()->user;
    $node = nodeWithCapabilities('scheduler.account-cron.v1');

    $this->actingAs($owner)
        ->delete(route('nodes.installed-services.destroy', ['node' => $node, 'service' => 'cron']), ['confirm' => 'node-a'])
        ->assertForbidden();
});

test('a reported removal takes the service capabilities off the node', function () {
    $node = nodeWithCapabilities('scheduler.account-cron.v1', 'web.nginx.v1');
    $action = NodeServiceAction::factory()->for($node)->create(['unit' => 'cron', 'action' => 'uninstall']);
    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $action->getMorphClass(),
        'provisionable_id' => $action->id,
        'node_id' => $node->id,
        'resource_id' => $action->uuid,
        'capability' => 'node.service-uninstall.v1',
        'payload' => ['service' => 'cron', 'confirm_data_loss' => false],
        'status' => ProvisioningStatus::Dispatched,
    ]);

    app(CompletesProvisioningOperation::class)->handle($operation, new ProvisioningResult(
        status: ProvisioningStatus::Applied,
        observedStateVersion: 1,
        observedStateDigest: 'sha256:'.str_repeat('0', 64),
        generationId: 'none',
        errors: [],
        completedAt: now(),
        data: ['service' => 'cron', 'changes' => []],
    ));

    expect($node->capabilities()->pluck('capability')->all())->toBe(['web.nginx.v1']);
});

test('the node page shows each removable service and what blocks it', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = nodeWithCapabilities('web.nginx.v1', 'scheduler.account-cron.v1', 'tls.acme.v1');
    WebDomain::factory()->for($node)->create();

    $this->actingAs($admin)->get(route('nodes.edit', $node))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('node.installed_services', 2)
        ->where('node.installed_services.0.name', 'nginx')
        ->where('node.installed_services.0.blockers', ['In use by 1 web domain'])
        ->where('node.installed_services.1.name', 'cron')
        ->where('node.installed_services.1.blockers', []));
});
