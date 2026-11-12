<?php

use App\Actions\Provisioning\CompletesProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\NodeServiceAction;
use App\Models\ProvisioningOperation;
use App\Services\Provisioning\ProvisioningResult;
use Inertia\Testing\AssertableInertia as Assert;

function sshOperationFor(Node $node, bool $open): ProvisioningOperation
{
    $action = NodeServiceAction::factory()->for($node)->create(['unit' => 'ssh', 'action' => $open ? 'open' : 'close']);

    return ProvisioningOperation::factory()->create([
        'provisionable_type' => $action->getMorphClass(),
        'provisionable_id' => $action->id,
        'node_id' => $node->id,
        'resource_id' => $action->uuid,
        'capability' => 'node.ssh-access.v1',
        'status' => ProvisioningStatus::Dispatched,
    ]);
}

function sshResult(ProvisioningStatus $status, ?array $data): ProvisioningResult
{
    return new ProvisioningResult(
        status: $status,
        observedStateVersion: 1,
        observedStateDigest: 'sha256:'.str_repeat('0', 64),
        generationId: 'none',
        errors: [],
        completedAt: now(),
        data: $data,
    );
}

test('a provider admin closes ssh and the node is sent exactly that, with the page unchanged until it reports back', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    $this->actingAs($admin)
        ->put(route('nodes.ssh-access.update', $node), ['open' => false])
        ->assertRedirect();

    $action = NodeServiceAction::query()->sole();
    $operation = ProvisioningOperation::query()->where('provisionable_type', $action->getMorphClass())->where('provisionable_id', $action->id)->sole();

    expect($action->unit)->toBe('ssh')
        ->and($action->action)->toBe('close')
        ->and($action->user_id)->toBe($admin->id)
        ->and($operation->capability)->toBe('node.ssh-access.v1')
        ->and($operation->node_id)->toBe($node->id)
        ->and($operation->payload)->toBe(['open' => false])
        ->and(AuditEvent::where('action', 'node_ssh.close')->where('auditable_id', $action->id)->exists())->toBeTrue()
        ->and($node->fresh()->ssh_open)->toBeTrue();
});

test('the node own admin may open and close ssh, a tenant may not', function () {
    $node = Node::factory()->create();
    $nodeAdmin = NodeAdminGrant::factory()->for($node)->create()->user;
    $tenant = Membership::factory()->owner()->create()->user;

    $this->actingAs($nodeAdmin)->put(route('nodes.ssh-access.update', $node), ['open' => true])->assertRedirect();
    $this->actingAs($tenant)->put(route('nodes.ssh-access.update', $node), ['open' => false])->assertForbidden();

    expect(NodeServiceAction::query()->count())->toBe(1);
});

test('the request must say open or closed', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    foreach ([[], ['open' => 'maybe']] as $body) {
        $this->actingAs($admin)->put(route('nodes.ssh-access.update', $node), $body)->assertSessionHasErrors('open');
    }

    expect(NodeServiceAction::query()->count())->toBe(0);
});

test('the node reporting the port closed, then open again, is what the page shows', function () {
    $node = Node::factory()->create();

    app(CompletesProvisioningOperation::class)->handle(sshOperationFor($node, false), sshResult(ProvisioningStatus::Applied, ['open' => false]));
    expect($node->fresh()->ssh_open)->toBeFalse();

    app(CompletesProvisioningOperation::class)->handle(sshOperationFor($node, true), sshResult(ProvisioningStatus::Applied, ['open' => true]));
    expect($node->fresh()->ssh_open)->toBeTrue();
});

test('a failed or rejected attempt leaves the port as it was shown', function () {
    $node = Node::factory()->create();

    foreach ([ProvisioningStatus::Failed, ProvisioningStatus::Rejected] as $status) {
        app(CompletesProvisioningOperation::class)->handle(sshOperationFor($node, false), sshResult($status, ['open' => false]));
    }

    app(CompletesProvisioningOperation::class)->handle(sshOperationFor($node, false), sshResult(ProvisioningStatus::Applied, null));

    expect($node->fresh()->ssh_open)->toBeTrue();
});

test('the node page says whether the port is open', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    $this->actingAs($admin)->get(route('nodes.edit', $node))->assertInertia(fn (Assert $page) => $page->where('node.ssh_open', true));

    $node->forceFill(['ssh_open' => false])->save();

    $this->actingAs($admin)->get(route('nodes.edit', $node))->assertInertia(fn (Assert $page) => $page->where('node.ssh_open', false));
});
