<?php

use App\Contracts\Provisioner;
use App\Enums\ProvisioningStatus;
use App\Jobs\DispatchProvisioningOperation;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

test('the daemon provisioner leaves an operation dispatched with its node_id set and no terminal result', function () {
    config(['provisioning.driver' => 'daemon']);

    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();

    $operation = ProvisioningOperation::factory()->pending()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'node_id' => $node->id,
        'resource_id' => $webDomain->uuid,
    ]);

    $job = new DispatchProvisioningOperation($operation->id);
    $job->handle(app(Provisioner::class));

    $operation->refresh();

    expect($operation->status)->toBe(ProvisioningStatus::Dispatched)
        ->and($operation->node_id)->toBe($node->id)
        ->and($operation->completed_at)->toBeNull();
});
