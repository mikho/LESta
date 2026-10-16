<?php

use App\Actions\Provisioning\CompletesProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\NodeServiceAction;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use App\Services\Provisioning\ProvisioningResult;
use Inertia\Testing\AssertableInertia as Assert;

function nodeWithServices(): Node
{
    $node = Node::factory()->create();
    $node->forceFill(['service_states' => [
        ['name' => 'nginx', 'state' => 'active'],
        ['name' => 'php8.3-fpm', 'state' => 'active'],
        ['name' => 'ssh', 'state' => 'active'],
        ['name' => 'lesta-applier', 'state' => 'active'],
    ]])->save();

    return $node;
}

test('a provider admin stops a service and the node is sent exactly that', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = nodeWithServices();

    $this->actingAs($admin)
        ->post(route('nodes.services.control', ['node' => $node, 'unit' => 'nginx', 'action' => 'stop']))
        ->assertRedirect();

    $action = NodeServiceAction::query()->sole();
    $operation = ProvisioningOperation::query()->where('provisionable_type', $action->getMorphClass())->where('provisionable_id', $action->id)->sole();

    expect($action->unit)->toBe('nginx')
        ->and($action->action)->toBe('stop')
        ->and($action->user_id)->toBe($admin->id)
        ->and($operation->capability)->toBe('node.service-control.v1')
        ->and($operation->node_id)->toBe($node->id)
        ->and($operation->payload)->toBe(['unit' => 'nginx', 'action' => 'stop'])
        ->and(AuditEvent::where('action', 'node_service.stop')->where('auditable_id', $action->id)->exists())->toBeTrue();
});

test('the node own admin may control its services, a tenant may not', function () {
    $node = nodeWithServices();
    $nodeAdmin = NodeAdminGrant::factory()->for($node)->create()->user;
    $tenant = Membership::factory()->owner()->create()->user;

    $this->actingAs($nodeAdmin)
        ->post(route('nodes.services.control', ['node' => $node, 'unit' => 'php8.3-fpm', 'action' => 'restart']))
        ->assertRedirect();

    $this->actingAs($tenant)
        ->post(route('nodes.services.control', ['node' => $node, 'unit' => 'nginx', 'action' => 'stop']))
        ->assertForbidden();

    expect(NodeServiceAction::query()->count())->toBe(1);
});

test('only listed services that the node reports can be controlled', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = nodeWithServices();

    foreach (['ssh', 'lesta-applier', 'dovecot'] as $unit) {
        $this->actingAs($admin)
            ->post(route('nodes.services.control', ['node' => $node, 'unit' => $unit, 'action' => 'stop']))
            ->assertSessionHasErrors('unit');
    }

    $this->actingAs($admin)
        ->post('/nodes/'.$node->uuid.'/services/nginx/mask')
        ->assertNotFound();

    expect(NodeServiceAction::query()->count())->toBe(0);
});

test('the reported result updates the service state at once', function () {
    $node = nodeWithServices();
    $action = NodeServiceAction::factory()->for($node)->create(['unit' => 'nginx', 'action' => 'stop']);
    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $action->getMorphClass(),
        'provisionable_id' => $action->id,
        'node_id' => $node->id,
        'resource_id' => $action->uuid,
        'capability' => 'node.service-control.v1',
        'status' => ProvisioningStatus::Dispatched,
    ]);

    app(CompletesProvisioningOperation::class)->handle($operation, new ProvisioningResult(
        status: ProvisioningStatus::Applied,
        observedStateVersion: 1,
        observedStateDigest: 'sha256:'.str_repeat('0', 64),
        generationId: 'none',
        errors: [],
        completedAt: now(),
        data: ['unit' => 'nginx', 'state' => 'inactive'],
    ));

    expect(collect($node->fresh()->service_states)->firstWhere('name', 'nginx')['state'])->toBe('inactive');
});

test('the node page lists the services, which can be controlled, and what depends on them', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = nodeWithServices();
    WebDomain::factory()->for($node)->count(2)->create(['php_version' => '8.3']);

    $this->actingAs($admin)->get(route('nodes.edit', $node))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('node.services.0.name', 'nginx')
        ->where('node.services.0.controllable', true)
        ->where('node.services.0.dependents', '2 web domains')
        ->where('node.services.1.dependents', '2 web domains using PHP 8.3')
        ->where('node.services.2.name', 'ssh')
        ->where('node.services.2.controllable', false));
});
