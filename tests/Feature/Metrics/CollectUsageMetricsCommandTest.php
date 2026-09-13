<?php

use App\Models\MetricsCollection;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;
use App\Models\UsageSnapshot;

test('metrics:collect dispatches a real collection for every non-suspended metrics-capable node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'metrics.usage.v1']);
    TenantDatabase::factory()->for($node)->create();

    $suspendedNode = Node::factory()->suspended()->create();
    NodeCapability::factory()->for($suspendedNode)->create(['capability' => 'metrics.usage.v1']);
    TenantDatabase::factory()->for($suspendedNode)->create();

    $this->artisan('metrics:collect')->assertExitCode(0);

    expect(MetricsCollection::count())->toBe(1)
        ->and(MetricsCollection::first()->node_id)->toBe($node->id);
});

test('metrics:prune deletes only snapshots past the 90-day retention window', function () {
    $node = Node::factory()->create();
    $account = \App\Models\Account::factory()->create();

    $old = UsageSnapshot::factory()->for($account)->for($node)->create(['collected_at' => now()->subDays(91)]);
    $recent = UsageSnapshot::factory()->for($account)->for($node)->create(['collected_at' => now()->subDays(10)]);

    $this->artisan('metrics:prune')->assertExitCode(0);

    expect(UsageSnapshot::find($old->id))->toBeNull()
        ->and(UsageSnapshot::find($recent->id))->not->toBeNull();
});
