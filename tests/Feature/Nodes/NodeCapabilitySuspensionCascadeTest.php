<?php

use App\Actions\Nodes\SuspendNode;
use App\Actions\Nodes\UnsuspendNode;
use App\Enums\SuspensionSource;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('node suspend cascades to active capabilities and unsuspend reactivates only cascade-sourced ones', function () {
    $node = Node::factory()->create();
    $preManuallySuspended = NodeCapability::factory()->for($node)->suspended()->create(['capability' => 'web.nginx.v1']);
    $active = NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(SuspendNode::class)->handle($admin, $node);

    expect($node->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->refresh()->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($active->refresh()->suspension_source)->toBe(SuspensionSource::Cascade)
        ->and($active->isSuspended())->toBeTrue();

    app(UnsuspendNode::class)->handle($admin, $node);

    expect($node->refresh()->isSuspended())->toBeFalse()
        ->and($active->refresh()->isSuspended())->toBeFalse()
        ->and($preManuallySuspended->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->suspension_source)->toBe(SuspensionSource::Manual);
});
